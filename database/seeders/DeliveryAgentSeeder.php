<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class DeliveryAgentSeeder extends Seeder
{
    public function run()
    {
        $agents = [
            [
                'name' => 'John Delivery',
                'email' => 'john.delivery@example.com',
                'password' => Hash::make('password'),
                'phone' => '1234567890',
                'role_id' => 5, // Delivery role
                'is_active' => true
            ],
            [
                'name' => 'Sarah Courier',
                'email' => 'sarah.courier@example.com',
                'password' => Hash::make('password'),
                'phone' => '0987654321',
                'role_id' => 5, // Delivery role
                'is_active' => true
            ],
            [
                'name' => 'Mike Logistics',
                'email' => 'mike.logistics@example.com',
                'password' => Hash::make('password'),
                'phone' => '5551234567',
                'role_id' => 5, // Delivery role
                'is_active' => true
            ]
        ];

        foreach ($agents as $agent) {
            User::create($agent);
        }
    }
}