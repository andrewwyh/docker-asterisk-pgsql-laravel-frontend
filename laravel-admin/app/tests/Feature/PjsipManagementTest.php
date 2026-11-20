<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Services\PjsipManager;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PjsipManagementTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    private array $committedIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create(['context' => 'abc']);
        $this->admin = User::factory()->create(['is_platform_admin' => true]);
    }

    private function fixture(): Connection
    {
        if (getenv('REALTIME_INTEGRATION') !== 'true') {
            $this->markTestSkipped('Requires isolated PostgreSQL writer fixture.');
        }
        config(['realtime.enabled' => true, 'realtime.writes_enabled' => true]);
        foreach (['asterisk' => 'fixture_reader', 'asterisk_write' => 'fixture_writer'] as $name => $role) {
            config(["database.connections.$name.host" => 'pbx-fixture', "database.connections.$name.database" => 'realtime_fixture', "database.connections.$name.username" => $role, "database.connections.$name.password" => '']);
        }
        config(['database.connections.fixture_admin' => array_replace(config('database.connections.asterisk_write'), ['username' => 'fixture'])]);
        DB::connection('fixture_admin')->beginTransaction();
        $db = DB::connection('asterisk_write');
        $db->beginTransaction();
        $this->actingAs($this->admin);

        return $db;
    }

    protected function tearDown(): void
    {
        if ($this->app) {
            if (DB::getConnections()['asterisk_write'] ?? false) {
                $db = DB::connection('asterisk_write');
                while ($db->transactionLevel()) {
                    $db->rollBack();
                }
            }
            if (DB::getConnections()['fixture_admin'] ?? false) {
                $adminDb = DB::connection('fixture_admin');
                while ($adminDb->transactionLevel()) {
                    $adminDb->rollBack();
                }
            }
            if ($this->committedIds !== []) {
                foreach (['ps_endpoints', 'ps_auths', 'ps_aors'] as $table) {
                    DB::connection('asterisk_write')->table($table)->whereIn('id', $this->committedIds)->delete();
                }
            }
            DB::purge('fixture_admin');
            DB::purge('asterisk');
            DB::purge('asterisk_write');
        }
        parent::tearDown();
    }

    private function createAccount(string $extension = '201'): string
    {
        $response = $this->post(route('pjsip.store', [$this->tenant, 'endpoints']), [
            'resource_key' => $extension, 'password' => 'private-new-sip-password', 'password_confirmation' => 'private-new-sip-password',
            'max_contacts' => 2, 'allow' => 'ulaw,alaw',
        ]);
        $response->assertSessionHasNoErrors()->assertRedirect();

        return $extension.'_abc';
    }

    private function snapshot(string $resource, string $id): string
    {
        $manager = app(PjsipManager::class);

        return $manager->token($this->tenant, $resource, $manager->record($this->tenant, $resource, $id));
    }

    private function deleteInput(string $resource, string $id): array
    {
        $manager = app(PjsipManager::class);
        $preview = $manager->deletion($this->tenant, $resource, $id);

        return ['version_token' => $manager->token($this->tenant, $resource, $preview['record'], $preview['related']), 'confirmation' => $id, 'confirm_delete' => '1'];
    }

    public function test_guests_viewers_and_other_tenant_admins_cannot_manage_pjsip(): void
    {
        $url = route('pjsip.store', [$this->tenant, 'endpoints']);
        $this->post($url)->assertRedirect('/login');
        $viewer = User::factory()->create();
        $this->tenant->members()->attach($viewer, ['role' => 'viewer']);
        $this->actingAs($viewer)->post($url)->assertForbidden();
        $this->get(route('pjsip.create', [$this->tenant, 'endpoints']))->assertForbidden();
        $other = Tenant::factory()->create();
        $other->members()->attach($viewer, ['role' => 'admin']);
        $this->post($url)->assertForbidden();
    }

    public function test_writes_must_be_explicitly_enabled(): void
    {
        $this->actingAs($this->admin)->get(route('pjsip.create', [$this->tenant, 'endpoints']))->assertStatus(503);
    }

    public function test_identity_and_password_validation_do_not_flash_secrets(): void
    {
        $this->actingAs($this->admin)->post(route('pjsip.store', [$this->tenant, 'endpoints']), [
            'resource_key' => '201_xyz', 'password' => 'short', 'password_confirmation' => 'other', 'context' => 'xyz',
        ])->assertSessionHasErrors(['resource_key', 'password', 'context']);
        $this->assertArrayNotHasKey('password', session()->getOldInput());
        $this->assertArrayNotHasKey('password_confirmation', session()->getOldInput());
    }

    public function test_tenant_admin_creates_three_records_atomically_and_keeps_numeric_extension(): void
    {
        $db = $this->fixture();
        $user = User::factory()->create();
        $this->tenant->members()->attach($user, ['role' => 'admin']);
        $this->actingAs($user);
        foreach (['endpoints', 'auths', 'aors'] as $resource) {
            $this->get(route('pjsip.create', [$this->tenant, $resource]))->assertOk()->assertSee('resource_key');
        }
        $id = $this->createAccount('00201');
        $this->assertSame('00201_abc', $id);
        $endpoint = $db->table('ps_endpoints')->where('id', $id)->first();
        $this->assertSame('abc', $endpoint->context);
        $this->assertSame('auth_username', $endpoint->identify_by);
        $this->assertSame($id, $endpoint->auth);
        $this->assertSame($id, $endpoint->aors);
        $this->assertSame('<00201>', $endpoint->callerid);
        $this->assertSame(2, $db->table('ps_aors')->where('id', $id)->value('max_contacts'));
        $this->assertSame('private-new-sip-password', $db->table('ps_auths')->where('id', $id)->value('password'));
        $this->get(route('pjsip.edit', [$this->tenant, 'auths', $id]))->assertOk()
            ->assertSee('name="password" type="text" value="private-new-sip-password"', false)
            ->assertDontSee('password_confirmation');
        $this->get(route('pjsip.edit', [$this->tenant, 'endpoints', $id]))->assertOk()->assertDontSee('private-new-sip-password');
        $this->get(route('pjsip.edit', [$this->tenant, 'endpoints', $id]))->assertOk();
        $this->get(route('pjsip.delete', [$this->tenant, 'endpoints', $id]))->assertOk()->assertSee('Review deletion');
        $this->assertStringNotContainsString('private-new-sip-password', DB::connection('sqlite')->table('audit_events')->value('details'));

        $inventory = route('tenants.inventory', [$this->tenant, 'auths']);
        $this->get($inventory)->assertOk()
            ->assertSee('<th>Id</th><th>Password</th>', false)
            ->assertSee('<td>00201_abc</td><td>private-new-sip-password</td>', false);
        $viewer = User::factory()->create();
        $this->tenant->members()->attach($viewer, ['role' => 'viewer']);
        $this->actingAs($viewer)->get($inventory)->assertOk()
            ->assertDontSee('<th>Password</th>', false)
            ->assertDontSee('fixture-private-auth-password');
    }

    public function test_a_failure_after_auth_creation_rolls_back_all_pbx_records(): void
    {
        $db = $this->fixture();
        $this->post(route('pjsip.store', [$this->tenant, 'endpoints']), [
            'resource_key' => '202', 'password' => 'private-test-password', 'password_confirmation' => 'private-test-password',
            'minimum_expiration' => 600, 'maximum_expiration' => 60,
        ])->assertSessionHasErrors('maximum_expiration');
        foreach (['ps_auths', 'ps_aors', 'ps_endpoints'] as $table) {
            $this->assertFalse($db->table($table)->where('id', '202_abc')->exists());
        }
    }

    public function test_edits_preserve_password_and_unlisted_settings_then_allow_explicit_replacement(): void
    {
        $db = $this->fixture();
        $id = $this->createAccount();
        $db->table('ps_auths')->where('id', $id)->update(['password' => 'legacy']);
        $url = route('pjsip.update', [$this->tenant, 'auths', $id]);
        $this->patch($url, ['version_token' => $this->snapshot('auths', $id), 'realm' => 'example.test', 'password' => 'legacy'])->assertSessionHasNoErrors();
        $this->assertSame('legacy', $db->table('ps_auths')->where('id', $id)->value('password'));
        $this->patch($url, ['version_token' => $this->snapshot('auths', $id), 'password' => 'short'])->assertSessionHasErrors('password');
        $this->assertArrayNotHasKey('password', session()->getOldInput());
        $this->assertSame('legacy', $db->table('ps_auths')->where('id', $id)->value('password'));
        $this->patch($url, ['version_token' => $this->snapshot('auths', $id), 'password' => 'replacement-sip-password'])->assertSessionHasNoErrors();
        $this->assertSame('replacement-sip-password', $db->table('ps_auths')->where('id', $id)->value('password'));
        $endpoint = $db->table('ps_endpoints')->where('id', $id)->first();
        $this->patch(route('pjsip.update', [$this->tenant, 'endpoints', $id]), ['version_token' => $this->snapshot('endpoints', $id), 'callerid' => 'New caller'])->assertSessionHasNoErrors();
        $this->assertSame($endpoint->allow, $db->table('ps_endpoints')->where('id', $id)->value('allow'));
        $this->assertSame($endpoint->auth, $db->table('ps_endpoints')->where('id', $id)->value('auth'));
    }

    public function test_cross_tenant_records_and_shared_auths_cannot_be_edited(): void
    {
        $this->fixture();
        foreach ([['endpoints', '100_xyz'], ['auths', 'auth-xyz'], ['auths', 'auth-shared'], ['aors', 'aor-shared']] as [$resource, $id]) {
            $this->get(route('pjsip.edit', [$this->tenant, $resource, $id]))->assertNotFound();
            $this->patch(route('pjsip.update', [$this->tenant, $resource, $id]), ['version_token' => 'invalid'])->assertNotFound();
        }
    }

    public function test_identity_collision_does_not_overwrite_existing_rows(): void
    {
        $db = $this->fixture();
        $before = $db->table('ps_auths')->where('id', 'auth-abc')->value('password');
        $this->post(route('pjsip.store', [$this->tenant, 'endpoints']), ['resource_key' => '100', 'password' => 'private-test-password', 'password_confirmation' => 'private-test-password'])->assertSessionHasErrors('resource_key');
        $this->assertSame($before, $db->table('ps_auths')->where('id', 'auth-abc')->value('password'));
    }

    public function test_extra_auth_and_aor_creation_editing_and_deletion_are_scoped_and_atomic(): void
    {
        $db = $this->fixture();
        $id = $this->createAccount();
        foreach (['auths', 'aors'] as $resource) {
            $this->post(route('pjsip.store', [$this->tenant, $resource]), ['resource_key' => 'extra', 'endpoint_id' => $id, 'password' => 'extra-private-password', 'password_confirmation' => 'extra-private-password'])->assertSessionHasNoErrors()->assertRedirect();
            $this->get(route('pjsip.edit', [$this->tenant, $resource, 'extra_abc']))->assertOk();
            $changes = $resource === 'auths' ? ['username' => 'another-username'] : ['max_contacts' => 3];
            $this->patch(route('pjsip.update', [$this->tenant, $resource, 'extra_abc']), ['version_token' => $this->snapshot($resource, 'extra_abc')] + $changes)->assertSessionHasNoErrors();
            $this->delete(route('pjsip.destroy', [$this->tenant, $resource, 'extra_abc']), $this->deleteInput($resource, 'extra_abc'))->assertSessionHasNoErrors()->assertRedirect();
            $this->assertFalse($db->table('ps_'.$resource)->where('id', 'extra_abc')->exists());
        }
        $this->assertSame($id, $db->table('ps_endpoints')->where('id', $id)->value('auth'));
        $this->assertSame($id, $db->table('ps_endpoints')->where('id', $id)->value('aors'));
    }

    public function test_deletion_requires_typed_confirmation_and_removes_only_reviewed_bundle(): void
    {
        $db = $this->fixture();
        $id = $this->createAccount();
        $input = $this->deleteInput('endpoints', $id);
        $this->delete(route('pjsip.destroy', [$this->tenant, 'endpoints', $id]), array_replace($input, ['confirmation' => 'wrong']))->assertSessionHasErrors('confirmation');
        $this->delete(route('pjsip.destroy', [$this->tenant, 'endpoints', $id]), $input)->assertSessionHasNoErrors()->assertRedirect();
        foreach (['ps_endpoints', 'ps_auths', 'ps_aors'] as $table) {
            $this->assertFalse($db->table($table)->where('id', $id)->exists());
        }
        $this->assertTrue($db->table('ps_endpoints')->where('id', '100_xyz')->exists());
    }

    public function test_shared_records_and_last_required_records_block_deletion(): void
    {
        $db = $this->fixture();
        $id = $this->createAccount();
        $this->delete(route('pjsip.destroy', [$this->tenant, 'auths', $id]), $this->deleteInput('auths', $id))->assertSessionHasErrors('operation');
        $this->delete(route('pjsip.destroy', [$this->tenant, 'endpoints', '100_abc']), $this->deleteInput('endpoints', '100_abc'))->assertSessionHasErrors('operation');
        $this->assertTrue($db->table('ps_auths')->where('id', $id)->exists());
    }

    public function test_active_contacts_dialplan_and_trunk_dependencies_block_account_deletion(): void
    {
        $db = $this->fixture();
        $id = $this->createAccount();
        $setup = DB::connection('fixture_admin');
        $setup->commit();
        try {
            $setup->table('ps_contacts')->insert(['id' => $id.';@fixture', 'endpoint' => $id, 'expiration_time' => time() + 3600]);
            $preview = app(PjsipManager::class)->deletion($this->tenant, 'endpoints', $id);
            $this->assertStringContainsString('Unregister', implode(' ', $preview['blocked']));
            $this->delete(route('pjsip.destroy', [$this->tenant, 'endpoints', $id]), $this->deleteInput('endpoints', $id))->assertSessionHasErrors('operation');
            $setup->table('ps_contacts')->where('endpoint', $id)->delete();
            $setup->table('extensions')->insert(['context' => 'abc', 'exten' => '201', 'priority' => 1, 'app' => 'Dial', 'appdata' => 'PJSIP/'.$id]);
            $this->delete(route('pjsip.destroy', [$this->tenant, 'endpoints', $id]), $this->deleteInput('endpoints', $id))->assertSessionHasErrors('operation');
            $setup->table('extensions')->where('context', 'abc')->delete();
            $setup->table('ps_endpoint_id_ips')->insert(['id' => 'fixture-identify', 'endpoint' => $id]);
            $this->delete(route('pjsip.destroy', [$this->tenant, 'endpoints', $id]), $this->deleteInput('endpoints', $id))->assertSessionHasErrors('operation');
            $setup->table('ps_endpoint_id_ips')->where('id', 'fixture-identify')->delete();
            $setup->table('ps_registrations')->insert(['id' => 'fixture-registration', 'outbound_auth' => $id]);
            $this->delete(route('pjsip.destroy', [$this->tenant, 'endpoints', $id]), $this->deleteInput('endpoints', $id))->assertSessionHasErrors('operation');
            $this->assertTrue($db->table('ps_endpoints')->where('id', $id)->exists());
        } finally {
            $setup->table('ps_contacts')->where('endpoint', $id)->delete();
            $setup->table('extensions')->where('context', 'abc')->where('exten', '201')->delete();
            $setup->table('ps_endpoint_id_ips')->where('id', 'fixture-identify')->delete();
            $setup->table('ps_registrations')->where('id', 'fixture-registration')->delete();
        }
    }

    public function test_stale_forms_detect_changes_including_password_only_updates_and_related_deletions(): void
    {
        $db = $this->fixture();
        $db->commit();
        $external = DB::connection('fixture_admin');
        $external->commit();
        $this->committedIds = ['209_abc'];
        $id = $this->createAccount('209');
        $stale = $this->snapshot('auths', $id);
        $external->table('ps_auths')->where('id', $id)->update(['password' => 'externally-replaced-password']);
        $this->patch(route('pjsip.update', [$this->tenant, 'auths', $id]), ['version_token' => $stale, 'realm' => 'stale.test'])->assertSessionHasErrors('version_token');
        $this->assertNull($db->table('ps_auths')->where('id', $id)->value('realm'));
        $deletion = $this->deleteInput('endpoints', $id);
        $external->table('ps_aors')->where('id', $id)->update(['max_contacts' => 3]);
        $this->delete(route('pjsip.destroy', [$this->tenant, 'endpoints', $id]), $deletion)->assertSessionHasErrors('version_token');
        $this->assertTrue($db->table('ps_endpoints')->where('id', $id)->exists());
    }

    public function test_writer_cannot_modify_dialplan_contacts_or_immutable_endpoint_identity(): void
    {
        $db = $this->fixture();
        $rights = $db->selectOne("SELECT has_table_privilege(current_user, 'extensions', 'INSERT') AS dialplan, has_table_privilege(current_user, 'ps_contacts', 'DELETE') AS contacts, has_column_privilege(current_user, 'ps_endpoints', 'context', 'UPDATE') AS context, has_column_privilege(current_user, 'ps_endpoints', 'id', 'UPDATE') AS identity");
        $this->assertFalse($rights->dialplan);
        $this->assertFalse($rights->contacts);
        $this->assertFalse($rights->context);
        $this->assertFalse($rights->identity);
    }

    public function test_a_token_cannot_be_reused_for_another_resource_or_modified(): void
    {
        $this->fixture();
        $id = $this->createAccount();
        $wrongResource = $this->snapshot('endpoints', $id);
        $this->patch(route('pjsip.update', [$this->tenant, 'auths', $id]), ['version_token' => $wrongResource, 'realm' => 'forbidden.test'])->assertSessionHasErrors('version_token');
        $this->patch(route('pjsip.update', [$this->tenant, 'endpoints', $id]), ['version_token' => 'tampered', 'callerid' => 'forbidden'])->assertSessionHasErrors('version_token');
    }
}
