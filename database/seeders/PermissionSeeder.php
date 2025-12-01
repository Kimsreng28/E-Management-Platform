<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            // Product permissions
            ['name' => 'view_products', 'group' => 'product', 'description' => 'View products'],
            ['name' => 'create_products', 'group' => 'product', 'description' => 'Create products'],
            ['name' => 'edit_products', 'group' => 'product', 'description' => 'Edit products'],
            ['name' => 'delete_products', 'group' => 'product', 'description' => 'Delete products'],
            ['name' => 'manage_own_products', 'group' => 'product', 'description' => 'Manage own products only'],

            // Order permissions
            ['name' => 'view_orders', 'group' => 'order', 'description' => 'View orders'],
            ['name' => 'manage_orders', 'group' => 'order', 'description' => 'Manage orders'],
            ['name' => 'view_own_orders', 'group' => 'order', 'description' => 'View own orders only'],

            // User permissions
            ['name' => 'manage_users', 'group' => 'user', 'description' => 'Manage users'],
            ['name' => 'assign_roles', 'group' => 'user', 'description' => 'Assign roles to users'],

            // Shop permissions
            ['name' => 'manage_shop', 'group' => 'shop', 'description' => 'Manage shop'],
            ['name' => 'view_shop_analytics', 'group' => 'shop', 'description' => 'View shop analytics'],

            // Delivery permissions
            ['name' => 'manage_deliveries', 'group' => 'delivery', 'description' => 'Manage deliveries'],
            ['name' => 'update_delivery_status', 'group' => 'delivery', 'description' => 'Update delivery status'],
            ['name' => 'view_delivery_locations', 'group' => 'delivery', 'description' => 'View delivery locations'],

            // Category permissions
            ['name' => 'manage_categories', 'group' => 'category', 'description' => 'Manage categories'],

            // Coupon permissions
            ['name' => 'manage_coupons', 'group' => 'coupon', 'description' => 'Manage coupons'],

            // Review permissions
            ['name' => 'manage_reviews', 'group' => 'review', 'description' => 'Manage reviews'],
            ['name' => 'approve_reviews', 'group' => 'review', 'description' => 'Approve reviews'],
        ];

        // Use updateOrCreate to avoid duplicates
        foreach ($permissions as $permission) {
            Permission::updateOrCreate(
                ['name' => $permission['name']], // Check if exists by name
                $permission // Data to create/update
            );
        }

        // Assign permissions to roles
        $admin = Role::where('name', 'admin')->first();
        $vendor = Role::where('name', 'vendor')->first();
        $delivery = Role::where('name', 'delivery')->first();
        $customer = Role::where('name', 'customer')->first();

        if ($admin) {
            // Admin gets all permissions
            $admin->permissions()->sync(Permission::pluck('id'));
        }

        if ($vendor) {
            // Vendor permissions
            $vendorPermissions = Permission::whereIn('name', [
                'view_products', 'create_products', 'edit_products', 'delete_products', 'manage_own_products',
                'view_own_orders', 'manage_shop', 'view_shop_analytics'
            ])->pluck('id');
            $vendor->permissions()->sync($vendorPermissions);
        }

        if ($delivery) {
            // Delivery permissions
            $deliveryPermissions = Permission::whereIn('name', [
                'view_orders', 'update_delivery_status', 'view_delivery_locations'
            ])->pluck('id');
            $delivery->permissions()->sync($deliveryPermissions);
        }

        if ($customer) {
            // Customer permissions
            $customerPermissions = Permission::whereIn('name', [
                'view_products', 'view_own_orders'
            ])->pluck('id');
            $customer->permissions()->sync($customerPermissions);
        }
    }
}