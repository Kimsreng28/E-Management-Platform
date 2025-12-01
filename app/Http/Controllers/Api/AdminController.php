<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use App\Models\Role;
use App\Models\Permission;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AdminController extends Controller
{
    public function assignRole(Request $request, User $user)
    {
        $validator = Validator::make($request->all(), [
            'role_id' => 'required|exists:roles,id'
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $user->update(['role_id' => $request->role_id]);

        // If assigning vendor role, create empty shop if doesn't exist
        if ($request->role_id == 4 && !$user->shop) { // 4 = vendor
            $user->shop()->create([
                'name' => $user->name . "'s Shop",
                'slug' => Str::slug($user->name) . '-shop-' . uniqid(),
                'is_active' => false
            ]);
        }

        return response()->json([
            'message' => 'Role assigned successfully',
            'user' => $user->load('role')
        ]);
    }

    public function getPermissions()
    {
        $permissions = Permission::all()->groupBy('group');

        return response()->json($permissions);
    }

    public function getRolePermissions(Role $role)
    {
        return response()->json([
            'role' => $role,
            'permissions' => $role->permissions
        ]);
    }
}
