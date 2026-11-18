<?php

namespace Tests\Feature;

use App\Models\Phone;
use App\Models\Tenant;
use App\Models\User;
use App\Services\PhoneReboot;
use App\Services\TenantInventory;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Mockery;
use Tests\TestCase;

class PhoneProvisioningTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_is_encrypted_and_served_only_at_its_current_url(): void
    {
        config(['realtime.enabled' => true]);
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->create(['is_platform_admin' => true]);
        $query = Mockery::mock(Builder::class);
        $query->shouldReceive('where')->with('id', '101_office')->once()->andReturnSelf();
        $query->shouldReceive('exists')->once()->andReturnTrue();
        $this->mock(TenantInventory::class)->shouldReceive('query')->withArgs(
            fn (Tenant $workspace, string $resource) => $workspace->is($tenant) && $resource === 'endpoints',
        )->once()->andReturn($query);

        $xml = '<profile><user>101</user></profile>';
        $this->actingAs($admin)->post(route('tenants.phones.store', $tenant), [
            'name' => 'Desk phone',
            'mac' => '00:11:22:33:44:55',
            'endpoint' => '101_office',
            'profile' => UploadedFile::fake()->createWithContent('profile.xml', $xml),
        ])->assertSessionHasNoErrors()->assertRedirect();

        $phone = Phone::firstOrFail();
        $this->assertNotSame($xml, $phone->getRawOriginal('profile'));
        $url = route('phones.profile', [$phone->token, '001122334455.xml']);
        $this->app['auth']->forgetGuards();
        $this->get($url)->assertOk()->assertContent($xml);
        $this->get(route('phones.profile', [$phone->token, '001122334466.xml']))->assertNotFound();

        $this->actingAs($admin)->patch(route('tenants.phones.update', [$tenant, $phone]), [
            'name' => 'Desk phone', 'mac' => '001122334466', 'endpoint' => '101_office',
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->get($url)->assertNotFound();

        $newUrl = route('phones.profile', [$phone->fresh()->token, '001122334466.xml']);
        $this->get($newUrl)->assertOk()->assertContent($xml);
        $this->delete(route('tenants.phones.destroy', [$tenant, $phone]))->assertRedirect();
        $this->get($newUrl)->assertNotFound();
    }

    public function test_reboot_respects_tenant_scope_and_viewer_permissions(): void
    {
        $tenant = Tenant::factory()->create();
        $other = Tenant::factory()->create();
        $viewer = User::factory()->create();
        $tenant->members()->attach($viewer, ['role' => 'viewer']);
        $phone = $tenant->phones()->create([
            'name' => 'Desk phone', 'mac' => '001122334455', 'endpoint' => 'desk',
            'format' => 'xml', 'profile' => '<profile/>', 'token' => str_repeat('a', 64),
        ]);
        $this->mock(PhoneReboot::class)->shouldNotReceive('send');

        $this->actingAs($viewer)->get(route('tenants.phones.index', $tenant))->assertOk()
            ->assertSee('Desk phone')->assertDontSee($phone->token);
        $this->post(route('tenants.phones.reboot', [$tenant, $phone]))->assertForbidden();
        $this->actingAs(User::factory()->create(['is_platform_admin' => true]))
            ->post(route('tenants.phones.reboot', [$other, $phone]))->assertNotFound();
    }

    private function editablePhone(): array
    {
        $tenant = Tenant::factory()->create();
        $phone = $tenant->phones()->create([
            'name' => 'Desk phone', 'mac' => '001122334455', 'endpoint' => '101_office',
            'format' => 'cfg', 'profile' => 'private-test-profile', 'token' => str_repeat('b', 64),
        ]);
        $this->actingAs(User::factory()->create(['is_platform_admin' => true]));

        return [$tenant, $phone];
    }

    public function test_profile_url_can_be_saved_per_phone_without_changing_private_download(): void
    {
        [$tenant, $phone] = $this->editablePhone();
        $path = route('phones.profile', [$phone->token, $phone->mac.'.cfg'], absolute: false);
        $url = 'http://192.0.2.10:8080/phones'.$path;
        $this->patch(route('tenants.phones.update', [$tenant, $phone]), [
            'name' => $phone->name, 'mac' => $phone->mac, 'endpoint' => $phone->endpoint, 'profile_url' => $url,
        ])->assertSessionHasNoErrors()->assertRedirect();
        $phone->refresh();
        $this->assertSame('http://192.0.2.10:8080/phones', $phone->profile_origin);
        $this->assertNotSame($phone->profile_origin, $phone->getRawOriginal('profile_origin'));
        $this->assertSame($url, $phone->profileUrl());
        $this->assertSame(str_repeat('b', 64), $phone->token);
        $this->assertSame('private-test-profile', $phone->profile);
        $this->get(route('tenants.phones.edit', [$tenant, $phone]))->assertOk()->assertSee('name="profile_url"', false)->assertSee($url, false);
        $this->app['auth']->forgetGuards();
        $this->get($path)->assertOk()->assertContent('private-test-profile');
    }

    public function test_missing_override_is_preserved_and_blank_override_restores_default(): void
    {
        [$tenant, $phone] = $this->editablePhone();
        $phone->profile_origin = 'https://phones.example.test';
        $phone->save();
        $fields = ['name' => $phone->name, 'mac' => $phone->mac, 'endpoint' => $phone->endpoint];
        $this->patch(route('tenants.phones.update', [$tenant, $phone]), $fields)->assertSessionHasNoErrors();
        $this->assertSame('https://phones.example.test', $phone->fresh()->profile_origin);
        $this->patch(route('tenants.phones.update', [$tenant, $phone]), $fields + ['profile_url' => ''])->assertSessionHasNoErrors();
        $this->assertNull($phone->fresh()->profile_origin);
        $this->assertStringStartsWith(rtrim(config('phone_provisioning.url') ?: config('app.url'), '/').'/', $phone->fresh()->profileUrl());
    }

    public function test_private_path_and_url_schemes_are_validated_without_flashing_the_url(): void
    {
        [$tenant, $phone] = $this->editablePhone();
        $fields = ['name' => $phone->name, 'mac' => $phone->mac, 'endpoint' => $phone->endpoint];
        $path = route('phones.profile', [$phone->token, $phone->mac.'.cfg'], absolute: false);
        foreach (['https://phones.example.test/not-the-private-path', 'file:///tmp/profile.cfg', 'https://user:secret@phones.example.test'.$path, 'https://phones.example.test'.$path.'?secret=value', 'https://phones.example.test'.$path.'#fragment'] as $url) {
            $this->patch(route('tenants.phones.update', [$tenant, $phone]), $fields + ['profile_url' => $url])->assertSessionHasErrors('profile_url');
            $this->assertArrayNotHasKey('profile_url', session()->getOldInput());
        }
        $this->assertNull($phone->fresh()->profile_origin);
    }

    public function test_saved_origin_survives_mac_rotation_and_tenant_permissions_are_enforced(): void
    {
        [$tenant, $phone] = $this->editablePhone();
        $path = route('phones.profile', [$phone->token, $phone->mac.'.cfg'], absolute: false);
        $this->patch(route('tenants.phones.update', [$tenant, $phone]), [
            'name' => $phone->name, 'mac' => '001122334466', 'endpoint' => $phone->endpoint,
            'profile_url' => 'https://phones.example.test'.$path,
        ])->assertSessionHasNoErrors();
        $phone->refresh();
        $this->assertNotSame(str_repeat('b', 64), $phone->token);
        $this->assertStringStartsWith('https://phones.example.test/provision/', $phone->profileUrl());
        $this->assertStringEndsWith('/001122334466.cfg', $phone->profileUrl());
        $viewer = User::factory()->create();
        $tenant->members()->attach($viewer, ['role' => 'viewer']);
        $this->actingAs($viewer)->patch(route('tenants.phones.update', [$tenant, $phone]), [
            'name' => $phone->name, 'mac' => $phone->mac, 'endpoint' => $phone->endpoint,
            'profile_url' => 'https://other.example.test'.$path,
        ])->assertForbidden();
        $other = Tenant::factory()->create();
        $this->actingAs(User::factory()->create(['is_platform_admin' => true]))
            ->patch(route('tenants.phones.update', [$other, $phone]), [])->assertNotFound();
    }
}
