<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class AdminUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $email = config('privacy.admin_email');

        if (!$email) {
            $this->command->error('No admin email is configured.');

            return;
        }

        $existingUser = User::where('email', $email)->first();

        if ($existingUser) {
            $this->command->info('A user with the configured admin email already exists.');

            return;
        }

        $user = new User;
        $name = config('privacy.admin_name');
        $password = Str::password();

        $user->name = $name;
        $user->email = $email;
        $user->password = $password;
        $user->role = UserRole::Admin;

        $user->save();

        $this->command->info('The admin user ' . $name . ' has been created.');
        $this->command->warn('Generated password (will only be shown once): ' . $password);
    }
}
