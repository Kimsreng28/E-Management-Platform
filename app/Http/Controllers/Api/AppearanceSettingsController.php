<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class AppearanceSettingsController extends Controller
{
    public function index()
    {
        $settings = Auth::user()->appearanceSetting()->firstOrCreate(
            ['user_id' => Auth::id()],
            [
                'language' => 'en',
                'dark_mode' => false,
                'currency' => 'USD',
                'timezone' => 'UTC'
            ]
        );

        return response()->json($settings);
    }

    public function update(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'language' => 'sometimes|in:en,kh',
            'dark_mode' => 'sometimes|boolean',
            'currency' => 'sometimes|in:USD,KHR',
            'timezone' => 'sometimes|timezone',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $settings = Auth::user()->appearanceSetting()->updateOrCreate(
            ['user_id' => Auth::id()],
            $validator->validated()
        );

        return response()->json($settings);
    }
}
