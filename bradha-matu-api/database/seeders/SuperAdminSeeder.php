<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('SUPERADMIN_EMAIL');
        $password = env('SUPERADMIN_PASSWORD');

        if (! is_string($email) || $email === '' || ! is_string($password) || $password === '') {
            throw new RuntimeException('Set SUPERADMIN_EMAIL and SUPERADMIN_PASSWORD before running the superadmin seeder.');
        }

        User::updateOrCreate(
            ['email' => $email],
            [
                'name' => env('SUPERADMIN_NAME', 'Network Administrator'),
                'password' => $password,
                'role' => 'super_admin',
                'email_verified_at' => now(),
            ],
        );
    }
}
