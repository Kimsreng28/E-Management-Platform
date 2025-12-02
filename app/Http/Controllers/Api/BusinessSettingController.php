<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use App\Models\BusinessSetting;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;

class BusinessSettingController extends Controller
{
    // Admin role check
    private function isAdmin()
    {
        return Auth::check() && Auth::user()->role_id === 1;
    }

    // Vendor role check
    private function isVendor()
    {
        return Auth::check() && Auth::user()->role_id === 4;
    }

    public function index()
    {
        // Each user (admin or vendor) can only access their own settings
        $settings = BusinessSetting::where('user_id', Auth::id())->first();

        if (!$settings) {
            return response()->json([
                'success' => false,
                'message' => 'Business settings not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'settings' => $settings,
        ]);
    }

    public function store(Request $request)
    {
        // Base validation rules for all users
        $baseRules = [
            'business_name' => 'required|string|max:255',
            'tax_id' => 'nullable|string|max:50',
            'currency' => 'required|string|size:3',
            'tax_rate' => 'required|numeric|min:0|max:100',
            'invoice_prefix' => 'required|string|max:10',
            'invoice_starting_number' => 'required|integer|min:1',
            'inventory_management' => 'required|boolean',
            'low_stock_threshold' => 'required|integer|min:1',
            'business_hours' => 'required|array',
            'business_hours.open' => 'required|date_format:H:i',
            'business_hours.close' => 'required|date_format:H:i',
            'business_hours.days_open' => 'required|array',
            'business_hours.days_open.*' => 'integer|between:0,6',
        ];

        // Payment settings rules - available for both admin and vendors
        $paymentRules = [
            'stripe_enabled' => 'required|boolean',
            'stripe_public_key' => 'required_if:stripe_enabled,true|nullable|string',
            'stripe_secret_key' => 'required_if:stripe_enabled,true|nullable|string',
            'stripe_webhook_secret' => 'nullable|string',

            'khqr_enabled' => 'required|boolean',
            'khqr_merchant_name' => 'required_if:khqr_enabled,true|nullable|string|max:255',
            'khqr_merchant_account' => 'required_if:khqr_enabled,true|nullable|string|max:255',
            'khqr_api_token' => 'required_if:khqr_enabled,true|nullable|string',

            'paypal_enabled' => 'required|boolean',
            'paypal_client_id' => 'required_if:paypal_enabled,true|nullable|string',
            'paypal_client_secret' => 'required_if:paypal_enabled,true|nullable|string',
            'paypal_sandbox' => 'required_if:paypal_enabled,true|boolean',
        ];

        // Combine rules - both admin and vendors can use payment settings
        $validationRules = array_merge($baseRules, $paymentRules);

        $validator = Validator::make($request->all(), $validationRules);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();
        $data['user_id'] = Auth::id();

        // Update or create settings - both admin and vendors can save payment settings
        $settings = BusinessSetting::updateOrCreate(
            ['user_id' => Auth::id()],
            $data
        );

        return response()->json([
            'success' => true,
            'message' => 'Business settings saved successfully',
            'settings' => $settings,
        ]);
    }

    public function getPaymentSettings()
    {
        // Both admin and vendors can access their own payment settings
        $settings = BusinessSetting::where('user_id', Auth::id())
            ->select([
                'stripe_enabled',
                'stripe_public_key',
                'khqr_enabled',
                'khqr_merchant_name',
                'khqr_merchant_account',
                'khqr_api_token',
                'paypal_enabled',
                'paypal_client_id',
                'paypal_sandbox',
            ])
            ->first();

        if (!$settings) {
            return response()->json([
                'success' => false,
                'message' => 'Payment settings not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'settings' => $settings,
        ]);
    }

    // New method to get user role for frontend
    public function getUserRole()
    {
        $user = Auth::user();

        return response()->json([
            'success' => true,
            'role_id' => $user->role_id,
            'is_admin' => $this->isAdmin(),
            'is_vendor' => $this->isVendor(),
        ]);
    }

    // New method to get vendor payment settings (for admin to view vendor settings)
    public function getVendorPaymentSettings($vendorId)
    {
        // Only admin can view vendor payment settings
        if (!$this->isAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access to vendor payment settings',
            ], 403);
        }

        $settings = BusinessSetting::where('user_id', $vendorId)
            ->select([
                'stripe_enabled',
                'stripe_public_key',
                'khqr_enabled',
                'khqr_merchant_name',
                'khqr_merchant_account',
                'khqr_api_token',
                'paypal_enabled',
                'paypal_client_id',
                'paypal_sandbox',
                'business_name',
            ])
            ->first();

        if (!$settings) {
            return response()->json([
                'success' => false,
                'message' => 'Vendor payment settings not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'settings' => $settings,
        ]);
    }
}
