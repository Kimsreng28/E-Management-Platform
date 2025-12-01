<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\Log;

class EmailVerificationController extends Controller
{
    /**
     * Verify user email
     */
    public function verify(Request $request, $id, $hash)
    {
        $user = User::find($id);

        if (!$user) {
            return $this->redirectToFrontend('error', 'User not found');
        }

        if (!hash_equals((string) $hash, sha1($user->getEmailForVerification()))) {
            return $this->redirectToFrontend('error', 'Invalid verification link');
        }

        if ($user->hasVerifiedEmail()) {
            return $this->redirectToFrontend('success', 'Email already verified');
        }

        if ($user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        return $this->redirectToFrontend('success', 'Email verified successfully');
    }

    /**
     * Resend verification email
     */
    public function resend(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found'
            ], 404);
        }

        if ($user->hasVerifiedEmail()) {
            return response()->json([
                'success' => false,
                'message' => 'Email already verified'
            ], 400);
        }

        try {
            $user->sendEmailVerificationNotification();

            Log::info('Verification email sent to: ' . $user->email);

            return response()->json([
                'success' => true,
                'message' => 'Verification link sent to your email'
            ], 200);

        } catch (\Exception $e) {
            Log::error('Failed to send verification email: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to send verification email. Please try again.',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Check verification status
     */
    public function checkVerificationStatus(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'email_verified' => $user->hasVerifiedEmail(),
            'email' => $user->email,
        ]);
    }

    /**
     * Redirect to frontend with appropriate status and message
     */
    private function redirectToFrontend(string $status, string $message)
    {
        $frontendUrl = config('app.frontend_url', 'http://127.0.0.1:3000', env('NEXT_PUBLIC_FRONTEND_URL', 'http://localhost:3000'), env('FRONTEND_URL'));
        $locale = 'en'; // You can make this dynamic if needed

        $url = "{$frontendUrl}/{$locale}/auth/verification-{$status}?message=" . urlencode($message);

        return redirect()->away($url);
    }

    /**
     * Alternative method that works for both API and web
     */
    public function verifyWithResponse(Request $request, $id, $hash)
    {
        $user = User::find($id);

        if (!$user) {
            return $this->apiOrRedirect($request, 'User not found', false);
        }

        if (!hash_equals((string) $hash, sha1($user->getEmailForVerification()))) {
            return $this->apiOrRedirect($request, 'Invalid verification link', false);
        }

        if ($user->hasVerifiedEmail()) {
            return $this->apiOrRedirect($request, 'Email already verified', true);
        }

        if ($user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        return $this->apiOrRedirect($request, 'Email verified successfully', true);
    }

    /**
     * Handle both API JSON responses and web redirects
     */
    private function apiOrRedirect(Request $request, string $message, bool $success)
    {
        // Check if this is an API request (expects JSON)
        if ($request->expectsJson() || $request->wantsJson() || $request->is('api/*')) {
            return response()->json([
                'message' => $message,
                'success' => $success
            ], $success ? 200 : 400);
        }

        // Web request - redirect to frontend
        $status = $success ? 'success' : 'error';
        return $this->redirectToFrontend($status, $message);
    }
}