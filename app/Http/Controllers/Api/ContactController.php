<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use App\Models\ContactMessage;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Log;
use App\Notifications\ContactFormNotification;

class ContactController extends Controller
{
    public function store(Request $request)
    {
        Log::info('=== CONTACT FORM SUBMISSION START ===');

        try {
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'email' => 'required|email|max:255',
                'subject' => 'required|string|max:255',
                'message' => 'required|string',
            ]);

            if ($validator->fails()) {
                Log::warning('Contact form validation failed', $validator->errors()->toArray());
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Store the message in database
            $contactMessage = ContactMessage::create($validator->validated());
            Log::info('Contact message saved to database', [
                'id' => $contactMessage->id,
                'from' => $contactMessage->email,
                'subject' => $contactMessage->subject
            ]);

            // Get admin users to notify
            $adminUsers = User::admins()->get();

            Log::info('Admin users retrieved', [
                'count' => $adminUsers->count(),
                'emails' => $adminUsers->pluck('email')->toArray()
            ]);

            // Send notification to all admins
            if ($adminUsers->count() > 0) {
                foreach ($adminUsers as $admin) {
                    try {
                        Log::info('Sending notification to admin', ['admin_email' => $admin->email]);
                        $admin->notify(new ContactFormNotification($contactMessage));
                        Log::info(' Notification sent successfully to: ' . $admin->email);
                    } catch (\Exception $e) {
                        Log::error(' Failed to send notification to: ' . $admin->email, [
                            'error' => $e->getMessage()
                        ]);
                    }
                }
            } else {
                Log::warning('No admin users found to send notifications to');
            }

            Log::info('=== CONTACT FORM SUBMISSION COMPLETED ===');

            return response()->json([
                'success' => true,
                'message' => 'Your message has been sent successfully. We will get back to you soon.',
                'data' => $contactMessage
            ], 201);

        } catch (\Exception $e) {
            Log::error('=== CONTACT FORM SUBMISSION FAILED ===', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to send message. Please try again.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
