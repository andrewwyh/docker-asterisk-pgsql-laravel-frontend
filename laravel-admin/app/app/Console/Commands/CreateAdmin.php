<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class CreateAdmin extends Command
{
    protected $signature = 'admin:create';

    protected $description = 'Create an administrator in the application database using a hidden password prompt';

    public function handle(): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('Run interactively so the password can be entered privately.');

            return self::FAILURE;
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

        User::create($validator->validated());
        $this->info('Administrator created.');

        return self::SUCCESS;
    }
}
