<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantInventory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

class TenancyTest extends TestCase
{
    use RefreshDatabase;

    private function platformAdmin(): User
    {
        return User::factory()->create(['is_platform_admin' => true]);
    }

    public function test_tenants_require_authentication_and_platform_permission_to_create(): void
    {
        $this->get('/tenants')->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get('/tenants/create')->assertForbidden();
        $this->post('/tenants', ['name' => 'Forbidden', 'context' => 'forbidden', 'confirm_context_ownership' => 1])->assertForbidden();
        $this->assertDatabaseCount('tenants', 0);
    }

    public function test_platform_admin_can_register_and_rename_tenant_with_audit_records(): void
    {
        $admin = $this->platformAdmin();
        $this->actingAs($admin)->post('/tenants', ['name' => 'ABC Company', 'context' => 'abc', 'confirm_context_ownership' => 1])->assertRedirect();
        $tenant = Tenant::firstOrFail();
        $this->assertDatabaseHas('audit_events', ['action' => 'tenant.created', 'actor_id' => $admin->id, 'tenant_id' => $tenant->id]);
        $this->patch(route('tenants.update', $tenant), ['name' => 'New Name'])->assertRedirect();
        $this->assertSame('abc', $tenant->fresh()->context);
        $this->assertDatabaseHas('tenants', ['name' => 'New Name']);
        $this->assertDatabaseHas('audit_events', ['action' => 'tenant.renamed']);
    }

    public function test_context_validation_rejects_ambiguous_identifiers_duplicates_and_unconfirmed_ownership(): void
    {
        $this->actingAs($this->platformAdmin());
        foreach (['general', 'globals', 'ABC', 'abc_def', '1abc', 'abc.def', str_repeat('a', 33), 'abc/def'] as $context) {
            $this->post('/tenants', ['name' => 'Tenant', 'context' => $context, 'confirm_context_ownership' => 1])->assertSessionHasErrors('context');
        }
        $this->post('/tenants', ['name' => 'Tenant', 'context' => 'abc'])->assertSessionHasErrors('confirm_context_ownership');
        Tenant::factory()->create(['context' => 'abc']);
        $this->post('/tenants', ['name' => 'Duplicate', 'context' => 'abc', 'confirm_context_ownership' => 1])->assertSessionHasErrors('context');
    }

    public function test_context_is_immutable_through_http_and_model_updates(): void
    {
        $tenant = Tenant::factory()->create(['context' => 'abc']);
        $this->actingAs($this->platformAdmin())->patch(route('tenants.update', $tenant), ['name' => 'Name', 'context' => 'xyz'])->assertSessionHasErrors('context');
        $this->assertSame('abc', $tenant->fresh()->context);
        $this->expectException(LogicException::class);
        $tenant->update(['context' => 'xyz']);
    }

    public function test_tenant_list_and_inventory_routes_enforce_membership_before_querying_pbx(): void
    {
        $own = Tenant::factory()->create(['name' => 'Own Workspace']);
        $other = Tenant::factory()->create(['name' => 'Secret Workspace']);
        $viewer = User::factory()->create();
        $own->members()->attach($viewer, ['role' => 'viewer']);
        $this->mock(TenantInventory::class)->shouldNotReceive('paginate');
        $this->actingAs($viewer)->get('/tenants')->assertOk()->assertSee('Own Workspace')->assertDontSee('Secret Workspace');
        $this->get(route('tenants.show', $own))->assertOk()->assertDontSee('Tenant access');
        $this->get(route('tenants.show', $other))->assertForbidden();
        foreach (['endpoints', 'auths', 'aors'] as $resource) {
            $this->get(route('tenants.inventory', [$other, $resource]))->assertForbidden();
            $this->get(route('tenants.inventory', [$own, $resource]))->assertOk()->assertSee('PBX inventory is disabled');
        }
    }

    public function test_tenant_admin_can_rename_only_own_tenant_and_cannot_manage_memberships(): void
    {
        $own = Tenant::factory()->create();
        $other = Tenant::factory()->create();
        $user = User::factory()->create();
        $own->members()->attach($user, ['role' => 'admin']);
        $this->actingAs($user)->patch(route('tenants.update', $own), ['name' => 'Updated'])->assertRedirect();
        $this->patch(route('tenants.update', $other), ['name' => 'Forbidden'])->assertForbidden();
        $this->post(route('tenants.members.store', $own), ['email' => $user->email, 'role' => 'admin', 'is_platform_admin' => true])->assertForbidden();
        $this->assertFalse($user->fresh()->is_platform_admin);
    }

    public function test_viewer_cannot_rename_tenant_or_change_membership(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create();
        $tenant->members()->attach($user, ['role' => 'viewer']);
        $this->actingAs($user)->patch(route('tenants.update', $tenant), ['name' => 'Forbidden'])->assertForbidden();
        $this->delete(route('tenants.members.destroy', [$tenant, $user]))->assertForbidden();
    }

    public function test_platform_membership_changes_take_effect_immediately(): void
    {
        $tenant = Tenant::factory()->create();
        $viewer = User::factory()->create();
        $admin = $this->platformAdmin();
        $this->actingAs($admin)->post(route('tenants.members.store', $tenant), ['email' => strtoupper($viewer->email), 'role' => 'viewer'])->assertRedirect();
        $this->actingAs($viewer)->get(route('tenants.show', $tenant))->assertOk();
        $this->actingAs($admin)->post(route('tenants.members.store', $tenant), ['email' => $viewer->email, 'role' => 'superuser'])->assertSessionHasErrors('role');
        $this->delete(route('tenants.members.destroy', [$tenant, $viewer]))->assertRedirect();
        $this->actingAs($viewer)->get(route('tenants.show', $tenant))->assertForbidden();
        $this->assertDatabaseHas('audit_events', ['action' => 'tenant.member_removed']);
    }

    public function test_existing_default_context_is_only_registered_through_explicit_confirmation(): void
    {
        $this->assertDatabaseCount('tenants', 0);
        $this->actingAs($this->platformAdmin())->post('/tenants', ['name' => 'Legacy', 'context' => 'default', 'confirm_context_ownership' => 1])->assertRedirect();
        $this->assertDatabaseHas('tenants', ['context' => 'default']);
    }

    public function test_console_can_create_tenant_viewer_without_platform_privileges(): void
    {
        $tenant = Tenant::factory()->create(['context' => 'abc']);
        $this->artisan('admin:create', ['--tenant' => 'abc', '--role' => 'viewer'])
            ->expectsQuestion('Name', 'Tenant Viewer')
            ->expectsQuestion('Email', 'viewer@example.com')
            ->expectsQuestion('Password (at least 12 characters)', 'private-viewer-password')
            ->expectsOutput('Administrator created.')
            ->assertSuccessful();
        $user = User::where('email', 'viewer@example.com')->firstOrFail();
        $this->assertFalse($user->is_platform_admin);
        $this->assertSame('viewer', $tenant->members()->findOrFail($user->id)->pivot->role);
        $this->assertStringNotContainsString('private-viewer-password', DB::table('audit_events')->value('details'));
    }

    public function test_upgrade_preserves_existing_administrators_and_passwords(): void
    {
        $user = User::factory()->create();
        $passwordHash = $user->password;
        $migration = require database_path('migrations/2026_11_17_081158_create_tenant_administration_tables.php');
        $migration->down();
        $migration->up();
        $this->assertTrue($user->fresh()->is_platform_admin);
        $this->assertSame($passwordHash, $user->fresh()->password);
        $this->assertDatabaseCount('tenants', 0);
    }

    public function test_platform_flag_is_not_mass_assignable(): void
    {
        $user = new User(['name' => 'Name', 'email' => 'test@example.com', 'password' => 'private-test-password', 'is_platform_admin' => true]);
        $user->save();
        $this->assertFalse($user->fresh()->is_platform_admin);
    }
}
