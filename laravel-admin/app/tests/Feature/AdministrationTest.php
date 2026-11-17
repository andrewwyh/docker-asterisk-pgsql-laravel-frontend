<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\RealtimeStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class AdministrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_requires_authentication(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
        $this->post('/logout')->assertRedirect('/login');
    }

    public function test_administrator_can_sign_in_and_sign_out(): void
    {
        $user = User::factory()->create(['password' => Hash::make('a-private-test-password')]);

        $this->post('/login', ['email' => strtoupper($user->email), 'password' => 'a-private-test-password'])
            ->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
        $this->get('/dashboard')->assertOk()->assertSee('Disabled');
        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_failed_login_never_flashes_the_password(): void
    {
        $this->from('/login')->post('/login', ['email' => 'nobody@example.com', 'password' => 'private-value'])
            ->assertRedirect('/login')->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertArrayNotHasKey('password', session()->getOldInput());
    }

    public function test_repeated_failed_logins_are_limited_even_with_a_correct_password(): void
    {
        $user = User::factory()->create(['password' => Hash::make('a-private-test-password')]);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'incorrect'])
                ->assertSessionHasErrors('email');
        }
        $this->post('/login', ['email' => $user->email, 'password' => 'a-private-test-password'])
            ->assertSessionHasErrors(['email' => 'Too many attempts. Please try again in a minute.']);
        $this->assertGuest();
    }

    public function test_disabled_realtime_does_not_attempt_a_connection(): void
    {
        DB::shouldReceive('connection')->never();
        $this->assertSame('disabled', app(RealtimeStatus::class)->check());
    }

    public function test_connection_failure_is_redacted(): void
    {
        config(['realtime.enabled' => true]);
        $connection = Mockery::mock();
        $connection->shouldReceive('select')->with('SELECT 1')->once()
            ->andThrow(new RuntimeException('sensitive connection details'));
        DB::shouldReceive('connection')->with('asterisk')->once()->andReturn($connection);

        $this->assertSame('unavailable', app(RealtimeStatus::class)->check());
    }

    public function test_application_storage_is_explicitly_separate(): void
    {
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame('sqlite', (new User)->getConnectionName());
        $this->assertSame('on', config('database.connections.asterisk.server_options.default_transaction_read_only'));
        $this->assertTrue(DB::connection('sqlite')->getSchemaBuilder()->hasTable('users'));
        $this->assertFalse(DB::connection('sqlite')->getSchemaBuilder()->hasTable('ps_endpoints'));
    }

    public function test_readiness_uses_only_the_application_database(): void
    {
        $this->get('/up')->assertOk();
        DB::connection('sqlite')->getSchemaBuilder()->drop('migrations');
        $this->get('/up')->assertStatus(500)->assertDontSee('SQLSTATE');
    }

    public function test_there_is_no_public_registration(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register')->assertNotFound();
    }

    public function test_admin_command_hashes_password_in_application_store(): void
    {
        $this->artisan('admin:create')
            ->expectsQuestion('Name', 'Administrator')
            ->expectsQuestion('Email', 'ADMIN@example.com')
            ->expectsQuestion('Password (at least 12 characters)', 'a-private-test-password')
            ->expectsOutput('Administrator created.')
            ->assertSuccessful();

        $user = User::where('email', 'admin@example.com')->firstOrFail();
        $this->assertTrue(Hash::check('a-private-test-password', $user->password));
    }
}
