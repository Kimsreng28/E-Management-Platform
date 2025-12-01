<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Role;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get the admin role
        $adminRole = Role::where('name', 'admin')->first();

        if (!$adminRole) {
            $this->command->error('Admin role not found! Please run RoleSeeder first.');
            return;
        }

        $admins = [
            [
                'name' => env('ADMIN_NAME', 'Admin'),
                'email' => env('ADMIN_EMAIL', 'admin@example.com'),
                'password' => env('ADMIN_PASSWORD', 'admin123'),
                'phone' => env('ADMIN_PHONE', '+855123456789'),
            ],
            [
                'name' => env('ADMIN_OTHER_NAME', 'Admin Manager'),
                'email' => env('ADMIN_OTHER_EMAIL', 'adminmanager@example.com'),
                'password' => env('ADMIN_OTHER_PASSWORD', 'admin1234'),
                'phone' => env('ADMIN_OTHER_PHONE', '+855987654321'),
            ]
        ];

        foreach ($admins as $adminData) {
            // Check if admin already exists
            $existingAdmin = User::where('email', $adminData['email'])->first();

            if (!$existingAdmin) {
                User::create([
                    'name' => $adminData['name'],
                    'email' => $adminData['email'],
                    'password' => Hash::make($adminData['password']),
                    'phone' => $adminData['phone'],
                    'role_id' => $adminRole->id,
                    'is_verified' => true,
                    'is_active' => true,
                    'email_verified_at' => now(),
                ]);

                $this->command->info("Admin {$adminData['name']} created successfully!");
            } else {
                $this->command->info("Admin {$adminData['name']} already exists.");
            }
        }

        $this->command->info('Admin seeding completed!');
    }
}
