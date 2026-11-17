<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TenantInventoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('REALTIME_INTEGRATION') !== 'true') {
            $this->markTestSkipped('Requires the isolated PostgreSQL fixture.');
        }
        config([
            'realtime.enabled' => true,
            'database.connections.asterisk.host' => 'pbx-fixture',
            'database.connections.asterisk.database' => 'realtime_fixture',
            'database.connections.asterisk.username' => 'fixture_reader',
            'database.connections.asterisk.password' => '',
        ]);
    }

    protected function tearDown(): void
    {
        DB::purge('asterisk');
        parent::tearDown();
    }

    private function signInToAbc(): Tenant
    {
        $tenant = Tenant::factory()->create(['context' => 'abc']);
        $viewer = User::factory()->create();
        $tenant->members()->attach($viewer, ['role' => 'viewer']);
        $this->actingAs($viewer);

        return $tenant;
    }

    public function test_endpoints_are_scoped_to_exact_context(): void
    {
        $tenant = $this->signInToAbc();
        $this->get(route('tenants.inventory', [$tenant, 'endpoints']))->assertOk()
            ->assertSee('100_abc')->assertDontSee('100_xyz')->assertDontSee('legacy-account');
    }

    public function test_auths_resolve_inbound_and_outbound_lists_without_exposing_credentials_or_shared_records(): void
    {
        $tenant = $this->signInToAbc();
        $this->get(route('tenants.inventory', [$tenant, 'auths']))->assertOk()
            ->assertSee('auth-abc')->assertSee('auth-outbound')
            ->assertDontSee('auth-xyz')->assertDontSee('auth-shared')->assertDontSee('auth-orphan')
            ->assertDontSee('fixture-private-auth-password')->assertDontSee('fixture-outbound-password');
    }

    public function test_aors_resolve_multiple_references_without_showing_other_tenants_or_orphans(): void
    {
        $tenant = $this->signInToAbc();
        $this->get(route('tenants.inventory', [$tenant, 'aors']))->assertOk()
            ->assertSee('aor-abc')->assertSee('aor-extra')->assertDontSee('aor-xyz')
            ->assertDontSee('aor-shared')->assertDontSee('aor-orphan');
    }

    public function test_an_empty_tenant_does_not_inherit_default_accounts(): void
    {
        $tenant = Tenant::factory()->create(['context' => 'empty']);
        $this->actingAs(User::factory()->create(['is_platform_admin' => true]))
            ->get(route('tenants.inventory', [$tenant, 'endpoints']))->assertOk()
            ->assertSee('No endpoints')->assertDontSee('legacy-account')->assertDontSee('100_abc');
    }

    public function test_unavailable_connection_has_a_generic_response(): void
    {
        $tenant = $this->signInToAbc();
        config(['database.connections.asterisk.port' => '1']);
        $this->get(route('tenants.inventory', [$tenant, 'auths']))->assertOk()
            ->assertSee('temporarily unavailable')->assertDontSee('SQLSTATE')->assertDontSee('pbx-fixture');
    }
}
