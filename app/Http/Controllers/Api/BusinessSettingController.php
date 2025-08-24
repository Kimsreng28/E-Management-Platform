<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use App\Models\BusinessSetting;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;

class BusinessSettingController extends Controller
{
    public function index()
    {
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
        $validator = Validator::make($request->all(), [
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

            // Payment & Integration Settings
            'stripe_enabled' => 'required|boolean',
            'stripe_public_key' => 'required_if:stripe_enabled,true|nullable|string',
            'stripe_secret_key' => 'required_if:stripe_enabled,true|nullable|string',
            'stripe_webhook_secret' => 'nullable|string',

            'khqr_enabled' => 'required|boolean',
            'khqr_merchant_name' => 'required_if:khqr_enabled,true|nullable|string|max:255',
            'khqr_merchant_account' => 'required_if:khqr_enabled,true|nullable|string|max:255',

            'paypal_enabled' => 'required|boolean',
            'paypal_client_id' => 'required_if:paypal_enabled,true|nullable|string',
            'paypal_client_secret' => 'required_if:paypal_enabled,true|nullable|string',
            'paypal_sandbox' => 'required_if:paypal_enabled,true|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();
        $data['user_id'] = Auth::id();

        // Update or create settings
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
        $settings = BusinessSetting::where('user_id', Auth::id())
            ->select([
                'stripe_enabled',
                'stripe_public_key',
                'khqr_enabled',
                'khqr_merchant_name',
                'khqr_merchant_account',
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
}