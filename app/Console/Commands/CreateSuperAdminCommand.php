<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

final class CreateSuperAdminCommand extends Command
{
    protected $signature = 'app:create-super-admin
        {--email= : Super-admin email address}
        {--name= : Full name}
        {--password= : Password (min 10 chars; prompted securely if omitted)}';

    protected $description = 'Create or promote a Platform Super-Admin user for the /platform Filament panel.';

    public function handle(): int
    {
        $email = trim((string) ($this->option('email') ?: $this->ask('Super-Admin email address')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Please provide a valid email address.');

            return self::FAILURE;
        }

        $name = trim((string) ($this->option('name') ?: $this->ask('Full name', 'Platform Super-Admin')));
        $password = (string) ($this->option('password') ?: $this->secret('Password (minimum 10 characters)'));

        if (strlen($password) < 10) {
            $this->error('Password must be at least 10 characters.');

            return self::FAILURE;
        }

        $user = User::withoutGlobalScopes()->updateOrCreate(
            ['email' => strtolower($email)],
            [
                'tenant_id' => null,
                'name' => $name !== '' ? $name : 'Platform Super-Admin',
                'password' => Hash::make($password),
                'role' => UserRole::SUPER_ADMIN,
                'languages_json' => ['en', 'sn', 'nd'],
                'is_available_for_handoff' => false,
            ]
        );

        $this->info("Platform Super-Admin ready: {$user->email} (role: super_admin). Sign in at /platform.");

        return self::SUCCESS;
    }
}
