<?php

namespace App\Http\Controllers\Api;

use Illuminate\Routing\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use App\Models\User;
use App\Models\UserProfile;
use App\Models\Address;

class ProfileController extends Controller
{
    // Get user profile data
    public function show(Request $request)
    {
        $user = $request->user();
        $profile = $user->profile ?? new UserProfile();
        $defaultAddress = $user->addresses()->where('is_default', true)->first();

        $profileData = [
            'user' => [
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'avatar' => $user->avatar,
            ],
            'profile' => [
                'bio' => $profile->bio,
                'birth_date' => $profile->birth_date,
                'gender' => $profile->gender,
                'website' => $profile->website,
                'social_links' => $profile->social_links,
            ],
            'address' => $defaultAddress ? [
                'label' => $defaultAddress->label,
                'recipient_name' => $defaultAddress->recipient_name,
                'phone' => $defaultAddress->phone,
                'address_line_1' => $defaultAddress->address_line_1,
                'address_line_2' => $defaultAddress->address_line_2,
                'city' => $defaultAddress->city,
                'state' => $defaultAddress->state,
                'postal_code' => $defaultAddress->postal_code,
                'country' => $defaultAddress->country,
                'latitude' => $defaultAddress->latitude,
                'longitude' => $defaultAddress->longitude,
            ] : null
        ];

        return response()->json($profileData);
    }

    // Update profile information
    public function update(Request $request)
    {
        $user = $request->user();

        // Validate the request data (partial updates allowed)
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:users,email,'.$user->id,
            'phone' => 'sometimes|string|unique:users,phone,'.$user->id,
            'bio' => 'nullable|string|max:500',
            'birth_date' => 'nullable|date',
            'gender' => 'nullable|string|in:male,female,other',
            'website' => 'nullable|url',
            'social_links' => 'nullable|json',
            'avatar' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:5120',

            'address' => 'nullable|array',
            'address.label' => 'nullable|string|max:100',
            'address.recipient_name' => 'nullable|string|max:255',
            'address.phone' => 'nullable|string|max:20',
            'address.address_line_1' => 'nullable|string|max:255',
            'address.address_line_2' => 'nullable|string|max:255',
            'address.city' => 'nullable|string|max:100',
            'address.state' => 'nullable|string|max:100',
            'address.postal_code' => 'nullable|string|max:20',
            'address.country' => 'nullable|string|max:100',
            'address.latitude' => 'nullable|numeric|between:-90,90',
            'address.longitude' => 'nullable|numeric|between:-180,180',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        // Update user basic info if provided
        $userData = $request->only(['name', 'email', 'phone']);
        if (!empty($userData)) {
            $user->update($userData);
        }

        // Handle avatar upload
        if ($request->hasFile('avatar')) {
            $path = $request->file('avatar')->store('avatars', 'public');
            $user->avatar = $path;
            $user->save();
        }

        // Update or create profile
        $profileData = $request->only(['bio', 'birth_date', 'gender', 'website', 'social_links']);
        if (!empty($profileData)) {
            $user->profile()->updateOrCreate(['user_id' => $user->id], $profileData);
        }

        // Handle address
        if ($request->has('address')) {
            $addressData = $request->address;
            $addressData['is_default'] = true;

            // Update existing default address or create new one
            $user->addresses()->updateOrCreate(
                ['is_default' => true, 'user_id' => $user->id],
                $addressData
            );
        }

        return response()->json([
            'message' => 'Profile updated successfully',
            'user' => $user->fresh(['profile', 'addresses']),
        ]);
    }
}