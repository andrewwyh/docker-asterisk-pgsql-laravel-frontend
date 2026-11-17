<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class CreateAdmin extends Command
{
    protected $signature = 'admin:create {--tenant= : Assign a tenant context instead of platform access} {--role=viewer : Tenant role: viewer or admin}';

    protected $description = 'Create an administrator in the application database using a hidden password prompt';

    public function handle(): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('Run interactively so the password can be entered privately.');

            return self::FAILURE;
        }

        $tenant = null;
        if ($this->option('tenant') !== null) {
            $tenant = Tenant::where('context', $this->option('tenant'))->first();
            if (! $tenant || ! in_array($this->option('role'), ['viewer', 'admin'], true)) {
                $this->error('Specify an existing tenant context and role viewer or admin.');

                return self::FAILURE;
            }
        }

        $attributes = [
            'name' => $this->ask('Name'),
            'email' => Str::lower((string) $this->ask('Email')),
            'password' => $this->secret('Password (at least 12 characters)'),
        ];
        $validator = Validator::make($attributes, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:sqlite.users,email'],
            'password' => ['required', 'string', Password::min(12)],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        DB::connection('sqlite')->transaction(function () use ($validator, $tenant): void {
            $user = new User($validator->validated());
            $user->forceFill(['is_platform_admin' => $tenant === null])->save();
            if ($tenant !== null) {
                $tenant->members()->attach($user->id, ['role' => $this->option('role')]);
            }
            DB::connection('sqlite')->table('audit_events')->insert([
                'actor_id' => null, 'tenant_id' => $tenant?->id, 'action' => 'account.created_by_console',
                'details' => json_encode(['user_id' => $user->id, 'platform_admin' => $tenant === null, 'role' => $tenant ? $this->option('role') : null]),
                'created_at' => now(),
            ]);
        });
        $this->info('Administrator created.');

        return self::SUCCESS;
    }
}
