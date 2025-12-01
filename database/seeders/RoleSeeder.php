<?php

namespace Database\Seeders;

use App\Models\Role;

use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        Role::insert([
            ['name' => 'admin'],
            ['name' => 'customer'],
            ['name' => 'guest'],
            ['name' => 'vendor'],
            ['name' => 'delivery'],
        ]);
    }
}
