<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use App\Models\Address;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;

class AddressController extends Controller
{
    // Display a listing of addresses.
    public function getAllUserAddresses()
    {
        $user = Auth::user();
        $addresses = $user->addresses()->latest()->get();

        return response()->json([
            'suscess' => true,
            'addresses' => $addresses
        ], 200);
    }

    // Store a newly created address
    public function createAddress(Request $request)
    {
        $user = Auth::user();

        // Validate the request data
        $validated = $request->validate([
            'label' => ['required', 'string', 'max:255'],
            'recipient_name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:20'],
            'address_line_1' => ['required', 'string', 'max:1000'],
            'address_line_2' => ['nullable', 'string', 'max:1000'],
            'city' => ['required', 'string', 'max:255'],
            'state' => ['required', 'string', 'max:255'],
            'postal_code' => ['required', 'string', 'max:20'],
            'country' => ['sometimes', 'string', 'max:255'],
            'is_default' => ['sometimes', 'boolean'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        if ($request->input('is_default', false)) {
            $user->addresses()->update(['is_default' => false]);
        }

        if (empty($validated['latitude']) || empty($validated['longitude'])) {
            $coordinates = $this->geocodeAddress(
                $validated['address_line_1'],
                $validated['address_line_2'] ?? '',
                $validated['city'],
                $validated['state'],
                $validated['postal_code'],
                $validated['country'] ?? 'Cambodia'
            );

            if ($coordinates) {
                $validated['latitude'] = $coordinates['lat'];
                $validated['longitude'] = $coordinates['lng'];
            } else {
                // Use fallback coordinates for the city
                $fallbackCoords = $this->getMockCoordinates($validated['city']);
                $validated['latitude'] = $fallbackCoords['lat'];
                $validated['longitude'] = $fallbackCoords['lng'];
            }
        }

        $address = $user->addresses()->create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Address created successfully',
            'data' => $address
        ], 201);
    }

    // Display the specified address.
    public function getAddressByAddress(Address $address)
    {
        if ($address->user_id !== Auth::id()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        return response()->json([
            'success' => true,
            'data' => $address
        ]);
    }

    // Update the specified address in storage.
    public function updateAddress(Request $request, Address $address)
    {
        if ($address->user_id !== Auth::id()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        $validated = $request->validate([
            'label' => ['sometimes', 'string', 'max:255'],
            'recipient_name' => ['sometimes', 'string', 'max:255'],
            'phone' => ['sometimes', 'string', 'max:20'],
            'address_line_1' => ['sometimes', 'string', 'max:1000'],
            'address_line_2' => ['nullable', 'string', 'max:1000'],
            'city' => ['sometimes', 'string', 'max:255'],
            'state' => ['sometimes', 'string', 'max:255'],
            'postal_code' => ['sometimes', 'string', 'max:20'],
            'country' => ['sometimes', 'string', 'max:255'],
            'is_default' => ['sometimes', 'boolean'],
            'latitude' => ['sometimes', 'numeric'],
            'longitude' => ['sometimes', 'numeric'],
        ]);

        // If setting as default, unset other defaults for the user
        if ($request->input('is_default', false)) {
            $address->user->addresses()
                ->where('id', '!=', $address->id)
                ->update(['is_default' => false]);
        }

        $addressChanged = $this->checkIfAddressChanged($address, $validated);

        if ($addressChanged && (empty($validated['latitude']) || empty($validated['longitude']))) {
            $coordinates = $this->geocodeAddress(
                $validated['address_line_1'] ?? $address->address_line_1,
                $validated['address_line_2'] ?? $address->address_line_2,
                $validated['city'] ?? $address->city,
                $validated['state'] ?? $address->state,
                $validated['postal_code'] ?? $address->postal_code,
                $validated['country'] ?? $address->country ?? 'Cambodia'
            );

            if ($coordinates) {
                $validated['latitude'] = $coordinates['lat'];
                $validated['longitude'] = $coordinates['lng'];
            }
        }

        $address->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Address updated successfully',
            'data' => $address
        ]);
    }

    // Remove the specified address from storage.
    public function deleteAddress(Address $address)
    {
        if ($address->user_id !== Auth::id()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        $address->forceDelete();

        return response()->json([
            'success' => true,
            'message' => 'Address deleted successfully'
        ]);
    }

    // Set an address as default
    public function setDefaultAddress(Address $address)
    {
        if ($address->user_id !== Auth::id()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        $address->user->addresses()
            ->where('id', '!=', $address->id)
            ->update(['is_default' => false]);

        $address->update(['is_default' => true]);

        return response()->json([
            'success' => true,
            'message' => 'Address set as default successfully',
            'data' => $address
        ]);
    }

    public function updateCoordinates(Request $request, Address $address)
    {
        if ($address->user_id !== Auth::id()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $address->update([
            'latitude' => $request->latitude,
            'longitude' => $request->longitude,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Coordinates updated successfully',
            'data' => $address
        ]);
    }
}
