<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Services\DialplanManager;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DialplanManagementTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    private array $committedExtensions = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create(['context' => 'abc']);
        $this->admin = User::factory()->create(['is_platform_admin' => true]);
    }

    private function fixture(): Connection
    {
        if (getenv('REALTIME_INTEGRATION') !== 'true') {
            $this->markTestSkipped('Requires isolated PostgreSQL fixture.');
        }
        config(['realtime.enabled' => true, 'realtime.dialplan_writes_enabled' => true]);
        foreach (['asterisk' => 'fixture_reader', 'dialplan_write' => 'fixture_dialplan_writer'] as $name => $role) {
            config(["database.connections.$name.host" => 'pbx-fixture', "database.connections.$name.database" => 'realtime_fixture', "database.connections.$name.username" => $role, "database.connections.$name.password" => '']);
        }
        config(['database.connections.fixture_admin' => array_replace(config('database.connections.dialplan_write'), ['username' => 'fixture'])]);
        $this->actingAs($this->admin);
        $db = DB::connection('dialplan_write');
        $db->beginTransaction();

        return $db;
    }

    protected function tearDown(): void
    {
        if ($this->app) {
            if (DB::getConnections()['dialplan_write'] ?? false) {
                $db = DB::connection('dialplan_write');
                while ($db->transactionLevel()) {
                    $db->rollBack();
                }
                if ($this->committedExtensions !== []) {
                    $db->table('extensions')->where('context', 'abc')->whereIn('exten', $this->committedExtensions)->delete();
                }
            }
            foreach (['asterisk', 'dialplan_write', 'fixture_admin'] as $name) {
                DB::purge($name);
            }
        }
        parent::tearDown();
    }

    private function steps(): array
    {
        return [['app' => 'Answer'], ['app' => 'Wait', 'argument' => '1'], ['app' => 'Playback', 'argument' => 'hello-world'], ['app' => 'Hangup']];
    }

    private function createRoute(string $extension = '310', ?array $steps = null): void
    {
        $steps ??= $this->steps();
        $preview = $this->post(route('dialplan.store', $this->tenant), ['action' => 'preview', 'extension' => $extension, 'steps' => $steps]);
        $preview->assertOk()->assertViewIs('dialplan.preview');
        $this->post(route('dialplan.store', $this->tenant), ['action' => 'save', 'confirm_save' => 1, 'extension' => $extension, 'steps' => $steps, 'version_token' => $preview->viewData('token')])->assertSessionHasNoErrors()->assertRedirect();
    }

    public function test_guests_viewers_and_other_tenant_admins_cannot_write(): void
    {
        $this->get(route('dialplan.index', $this->tenant))->assertRedirect('/login');
        $viewer = User::factory()->create();
        $this->tenant->members()->attach($viewer, ['role' => 'viewer']);
        $this->actingAs($viewer)->get(route('dialplan.index', $this->tenant))->assertOk();
        $this->get(route('dialplan.create', $this->tenant))->assertForbidden();
        $this->post(route('dialplan.provision', $this->tenant), ['confirm_provision' => 1])->assertForbidden();
        $other = Tenant::factory()->create();
        $this->get(route('dialplan.index', $other))->assertForbidden();
        $this->post(route('dialplan.store', $this->tenant), ['action' => 'preview', 'extension' => '310', 'steps' => $this->steps()])->assertForbidden();
    }

    public function test_dialplan_writes_are_disabled_by_default(): void
    {
        $this->actingAs($this->admin)->get(route('dialplan.create', $this->tenant))->assertStatus(503);
    }

    public function test_raw_code_patterns_context_changes_and_unconfirmed_saves_are_rejected(): void
    {
        $this->actingAs($this->admin);
        $this->post(route('dialplan.store', $this->tenant), ['action' => 'preview', 'extension' => '_X.', 'context' => 'xyz', 'steps' => [['app' => 'System', 'appdata' => 'private-command']]])
            ->assertSessionHasErrors(['extension', 'context', 'steps.0.app', 'steps.0']);
        $this->post(route('dialplan.store', $this->tenant), ['action' => 'save', 'extension' => '310', 'steps' => $this->steps()])->assertSessionHasErrors(['version_token', 'confirm_save']);
    }

    public function test_preview_has_no_writes_and_save_creates_ordered_priorities_with_leading_zeroes(): void
    {
        $db = $this->fixture();
        $this->get(route('dialplan.create', $this->tenant))->assertOk()->assertSee('Preview changes');
        $preview = $this->post(route('dialplan.store', $this->tenant), ['action' => 'preview', 'extension' => '00310', 'steps' => $this->steps()]);
        $preview->assertOk()->assertSee('hello-world');
        $this->assertFalse($db->table('extensions')->where('exten', '00310')->exists());
        $this->post(route('dialplan.store', $this->tenant), ['action' => 'save', 'confirm_save' => 1, 'extension' => '00310', 'steps' => $this->steps(), 'version_token' => $preview->viewData('token')])->assertSessionHasNoErrors();
        $this->assertSame([1, 2, 3, 4], $db->table('extensions')->where('context', 'abc')->where('exten', '00310')->orderBy('priority')->pluck('priority')->all());
        $this->get(route('dialplan.edit', [$this->tenant, '00310']))->assertOk()->assertSee('dialplan.js');
        $this->get(route('dialplan.delete', [$this->tenant, '00310']))->assertOk()->assertSee('Delete extension 00310');
    }

    public function test_dial_targets_are_tenant_scoped_and_arbitrary_arguments_are_rejected(): void
    {
        $this->fixture();
        foreach ([['app' => 'Dial', 'argument' => '100_xyz'], ['app' => 'Dial', 'argument' => '100_abc&Local/100@xyz'], ['app' => 'Playback', 'argument' => '../private'], ['app' => 'Wait', 'argument' => '999'], ['app' => 'Echo', 'argument' => 'unexpected']] as $step) {
            $this->post(route('dialplan.store', $this->tenant), ['action' => 'preview', 'extension' => '310', 'steps' => [$step, ['app' => 'Hangup']]])->assertSessionHasErrors('steps.0.argument');
        }
        $this->createRoute('310', [['app' => 'Dial', 'argument' => '100_abc', 'timeout' => 20], ['app' => 'Hangup']]);
        $this->assertSame('PJSIP/100_abc,20,i', DB::connection('dialplan_write')->table('extensions')->where('context', 'abc')->where('exten', '310')->where('priority', 1)->value('appdata'));
    }

    public function test_reordering_replaces_priorities_atomically_and_preserves_other_routes(): void
    {
        $db = $this->fixture();
        $this->createRoute();
        $this->createRoute('311');
        $token = app(DialplanManager::class)->form($this->tenant, '310')['token'];
        $steps = [['app' => 'Answer'], ['app' => 'Playback', 'argument' => 'hello-world'], ['app' => 'Wait', 'argument' => '2'], ['app' => 'Hangup']];
        $this->post(route('dialplan.update', [$this->tenant, '310']), ['action' => 'save', 'confirm_save' => 1, 'version_token' => $token, 'steps' => $steps])->assertSessionHasNoErrors();
        $this->assertSame(['Answer', 'Playback', 'Wait', 'Hangup'], $db->table('extensions')->where('context', 'abc')->where('exten', '310')->orderBy('priority')->pluck('app')->all());
        $this->assertSame(4, $db->table('extensions')->where('context', 'abc')->where('exten', '311')->count());
        $newToken = app(DialplanManager::class)->form($this->tenant, '310')['token'];
        $this->post(route('dialplan.update', [$this->tenant, '310']), ['action' => 'save', 'confirm_save' => 1, 'version_token' => $newToken, 'steps' => [['app' => 'Wait', 'argument' => '2']]])->assertSessionHasErrors('steps');
        $this->assertSame(4, $db->table('extensions')->where('context', 'abc')->where('exten', '310')->count());
    }

    public function test_provisioning_creates_only_missing_same_tenant_routes_and_preserves_tests(): void
    {
        $db = $this->fixture();
        $db->table('extensions')->insert(['context' => 'abc', 'exten' => '200', 'priority' => 1, 'app' => 'Hangup', 'appdata' => '']);
        $this->post(route('dialplan.provision', $this->tenant), ['confirm_provision' => 1])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(1, $db->table('extensions')->where('context', 'abc')->where('exten', '200')->count());
        $this->assertSame('PJSIP/100_abc,30,i', $db->table('extensions')->where('context', 'abc')->where('exten', '100')->where('priority', 1)->value('appdata'));
        $this->assertSame(4, $db->table('extensions')->where('context', 'abc')->where('exten', '201')->count());
        $count = $db->table('extensions')->count();
        $this->post(route('dialplan.provision', $this->tenant), ['confirm_provision' => 1])->assertSessionHasNoErrors();
        $this->assertSame($count, $db->table('extensions')->count());
        $this->assertSame(0, $db->table('extensions')->where('context', 'xyz')->count());
    }

    public function test_unsupported_routes_do_not_expose_arguments_and_cannot_be_overwritten(): void
    {
        $db = $this->fixture();
        $db->table('extensions')->insert(['context' => 'abc', 'exten' => '310', 'priority' => 1, 'app' => 'System', 'appdata' => 'private-legacy-argument']);
        $this->get(route('dialplan.edit', [$this->tenant, '310']))->assertOk()->assertSee('read-only')->assertDontSee('private-legacy-argument');
        $form = app(DialplanManager::class)->form($this->tenant, '310');
        $this->post(route('dialplan.update', [$this->tenant, '310']), ['action' => 'save', 'confirm_save' => 1, 'version_token' => $form['token'], 'steps' => $this->steps()])->assertSessionHasErrors('operation');
        $this->delete(route('dialplan.destroy', [$this->tenant, '310']), ['version_token' => $form['token'], 'confirmation' => '310', 'confirm_delete' => 1])->assertSessionHasErrors('operation');
        $this->assertSame('private-legacy-argument', $db->table('extensions')->where('context', 'abc')->where('exten', '310')->value('appdata'));
    }

    public function test_deletion_requires_confirmation_and_is_scoped_to_context(): void
    {
        $db = $this->fixture();
        $this->createRoute();
        $db->table('extensions')->insert(['context' => 'xyz', 'exten' => '310', 'priority' => 1, 'app' => 'Hangup', 'appdata' => '']);
        $token = app(DialplanManager::class)->form($this->tenant, '310')['token'];
        $this->delete(route('dialplan.destroy', [$this->tenant, '310']), ['version_token' => $token, 'confirmation' => '311', 'confirm_delete' => 1])->assertSessionHasErrors('confirmation');
        $this->delete(route('dialplan.destroy', [$this->tenant, '310']), ['version_token' => $token, 'confirmation' => '310', 'confirm_delete' => 1])->assertSessionHasNoErrors();
        $this->assertSame(0, $db->table('extensions')->where('context', 'abc')->where('exten', '310')->count());
        $this->assertSame(1, $db->table('extensions')->where('context', 'xyz')->where('exten', '310')->count());
    }

    public function test_stale_forms_detect_external_updates_and_tokens_cannot_move_between_tenants(): void
    {
        $db = $this->fixture();
        $db->commit();
        $this->committedExtensions = ['318'];
        $this->createRoute('318');
        $manager = app(DialplanManager::class);
        $token = $manager->form($this->tenant, '318')['token'];
        DB::connection('fixture_admin')->table('extensions')->where('context', 'abc')->where('exten', '318')->where('priority', 2)->update(['appdata' => '3']);
        $this->post(route('dialplan.update', [$this->tenant, '318']), ['action' => 'save', 'confirm_save' => 1, 'version_token' => $token, 'steps' => $this->steps()])->assertSessionHasErrors('version_token');
        $other = Tenant::factory()->create(['context' => 'xyz']);
        $this->post(route('dialplan.store', $other), ['action' => 'save', 'confirm_save' => 1, 'version_token' => $token, 'extension' => '318', 'steps' => $this->steps()])->assertSessionHasErrors('version_token');
    }

    public function test_writer_does_not_gain_pjsip_or_schema_permissions_and_mutations_share_a_lock(): void
    {
        $db = $this->fixture();
        $this->createRoute();
        $rights = $db->selectOne("SELECT has_table_privilege(current_user, 'ps_endpoints', 'DELETE') AS endpoints, has_schema_privilege(current_user, 'public', 'CREATE') AS schema");
        $this->assertFalse($rights->endpoints);
        $this->assertFalse($rights->schema);
        $external = DB::connection('fixture_admin');
        $external->beginTransaction();
        try {
            $this->assertFalse($external->selectOne('SELECT pg_try_advisory_xact_lock(171117, 1) AS acquired')->acquired);
        } finally {
            $external->rollBack();
        }
    }

    public function test_viewer_inventory_is_scoped_to_context_and_does_not_render_arguments(): void
    {
        $this->fixture();
        $setup = DB::connection('fixture_admin');
        $ids = [];
        try {
            $ids[] = $setup->table('extensions')->insertGetId(['context' => 'abc', 'exten' => '9191', 'priority' => 1, 'app' => 'Hangup', 'appdata' => 'private-unrendered-argument']);
            $ids[] = $setup->table('extensions')->insertGetId(['context' => 'xyz', 'exten' => '9292', 'priority' => 1, 'app' => 'Hangup', 'appdata' => '']);
            $viewer = User::factory()->create();
            $this->tenant->members()->attach($viewer, ['role' => 'viewer']);
            $this->actingAs($viewer)->get(route('dialplan.index', $this->tenant))->assertOk()
                ->assertSee('9191')->assertDontSee('9292')->assertDontSee('private-unrendered-argument')->assertDontSee('Create route');
        } finally {
            $setup->table('extensions')->whereIn('id', $ids)->delete();
        }
    }
}
