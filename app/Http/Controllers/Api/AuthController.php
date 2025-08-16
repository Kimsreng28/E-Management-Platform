<?php

namespace App\Http\Controllers\Api;

use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Laravel\Socialite\Facades\Socialite;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Hash;
use SocialiteProviders\Manager\Config;
use SocialiteProviders\Telegram\Provider as TelegramProvider;

class AuthController extends Controller
{
    // Register a new user
    public function register(Request $request){
        // Validate the request data
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                'unique:users',
            ],
            'password' => 'required|string|min:8|confirmed',
            'phone' => 'nullable|string|max:15',
            'role' => 'required|string|exists:roles,name',
        ]);

        // Role assignment
        $role = Role::where('name', $validated['role'])->first();

        // Create the user
        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => bcrypt($validated['password']),
            'phone' => $validated['phone'] ?? null,
            'role_id' => $role->id,
        ]);

        // Token generation
        $token = $user->createToken('api_token')->plainTextToken;

        // Return the response
        return response()->json([
            'message' => 'User registered successfully',
            'user' => $user,
            'token' => $token,
        ], 201);
    }

    // Login a user
    public function login(Request $request){
        // Validate the request data
        $validated = $request->validate([
            'email' => 'required|string|email',
            'phone' => 'nullable|string|max:15',
            'password' => 'required|string',
        ]);

        // Require at least one of email or phone
        if (empty($validated['email']) && empty($validated['phone'])) {
            return response()->json(['message' => 'Either email or phone is required'], 422);
        }

        // user authentication where email or phone matches
        $user = User::where(function ($query) use ($validated) {
            if (!empty($validated['email'])) {
                $query->where('email', $validated['email']);
            }
            if (!empty($validated['phone'])) {
                $query->orWhere('phone', $validated['phone']);
            }
        })->first();

        if (!$user || !password_verify($validated['password'], $user->password)) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        // Token generation
        $token = $user->createToken('api_token')->plainTextToken;

        // Return the response
        return response()->json([
            'message' => 'User logged in successfully',
            'user' => $user,
            'token' => $token,
        ], 200);
    }

    // Logout a user
    public function logout(Request $request){
        // Revoke the token
        $request->user()->tokens()->delete();

        // Return the response
        return response()->json(['message' => 'User logged out successfully'], 200);
    }

    // Get the authenticated user
    public function me(Request $request){
        // Return the authenticated user
        return response()->json($request->user()->load('role'), 200);
    }

    // Forgot Password
    public function forgotPassword(Request $request){

        // Validate the request data
        $validated = $request->validate([
            'email' => 'required|email|exists:users,email',
        ]);

        // Generate OTP
        $otp = rand(100000, 999999);

        // Record the OTP in the database
        DB::table('password_resets')->updateOrInsert(
            ['email' => $validated['email']],
            ['otp' => $otp, 'created_at' => Carbon::now()]
        );

        // Send OTP email
        try {
            Mail::raw("Your OTP code for password reset is: $otp", function ($message) use ($validated) {
                $message->to($validated['email']);
                $message->subject('Password Reset OTP');
            });
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to send OTP email',
                'error' => $e->getMessage()
            ], 500);
        }

        // Response message
        return response()->json(['message' => 'OTP sent to your email successfully'], 200);
    }


    // Reset Password
    public function resetPassword(Request $request){
        // Validate the request data
        $validated = $request->validate([
            'email' => 'required|email|exists:users,email',
            'otp' => 'required|string',
            'password' => 'required|string|min:8|confirmed',
        ]);

        // Record the OTP in the database
        $record = DB::table('password_resets')
            ->where('email', $validated['email'])
            ->where('otp', $validated['otp'])
            ->first();

        // Check if OTP exists
        if (!$record) {
            return response()->json(['message' => 'Invalid OTP'], 401);
        }

        // Update the password for the user in the database
        User::where('email', $validated['email'])
            ->update(['password' => bcrypt($validated['password'])]);

        // Remove the reset record from the database
        DB::table('password_resets')->where('email', $validated['email'])->delete();

        // Return the response
        return response()->json(['message' => 'Password has been reset successfully'], 200);
    }

    // Verify OTP
    public function verifyOtp(Request $request){
        // Validate the request data
        $validated = $request->validate([
            'email' => 'required|email|exists:users,email',
            'otp' => 'required',
        ]);

        // Record the OTP in the database
        $record = DB::table('password_resets')
            ->where('email', $validated['email'])
            ->where('otp', $validated['otp'])
            ->first();

        // Check if OTP exists
        if (!$record) {
            return response()->json(['message' => 'Invalid OTP'], 401);
        }

        // Check if OTP expired
        if (Carbon::parse($record->created_at)->addMinutes(10)->isPast()) {
            return response()->json(['message' => 'OTP expired'], 400);
        }

        // Return the response
        return response()->json(['message' => 'OTP verified successfully'], 200);
    }

    // Google login
    public function googleRedirect(){
        try {
            return Socialite::driver('google')->redirect();
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ], 500);
        }
    }

    // Google callback
    public function googleCallback()
    {
        try {
            $driver  = Socialite::driver('google');
            $googleUser = $driver->stateless()->user();

            $user = User::firstOrCreate([
                'email' => $googleUser->getEmail()
            ], [
                'name' => $googleUser->getName(),
                'provider' => 'google',
                'provider_id' => $googleUser->getId(),
                'role_id' => Role::where('name', 'customer')->first()->id,
                'avatar' => $googleUser->getAvatar(),
                'is_verified' => true,
            ]);

            $token = $user->createToken('api_token')->plainTextToken;

            $locale = 'en'; // or detect dynamically
            $frontendUrl = "http://localhost:3000/{$locale}/auth/callback?token=" . urlencode($token);
            return redirect($frontendUrl);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ], 500);
        }
    }

    // Telegram redirect
    public function telegramRedirect()
    {
        try {
            return Socialite::driver('telegram')->redirect();
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ], 500);
        }
    }

    public function telegramSpaLogin(Request $request)
    {
        try {
            // Get raw input
            $input = $request->getContent();
            if ($input) {
                $input = json_decode($input, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new \Exception('Invalid JSON data: ' . json_last_error_msg());
                }
            } else {
                $input = $request->all();
            }

            Log::debug('Telegram Auth Data:', $input);

            if (empty($input)) {
                throw new \Exception('No authentication data received');
            }

            // Required fields
            $requiredFields = ['id', 'hash', 'auth_date'];
            foreach ($requiredFields as $field) {
                if (!isset($input[$field])) {
                    throw new \Exception("Missing required field: $field");
                }
            }

            // Verify Telegram hash
            $this->verifyTelegramHash($input);

            // Optional: check expiration (1 day)
            if (time() - (int)$input['auth_date'] > 86400) {
                throw new \Exception('Telegram auth data expired');
            }

            // Create or update user
            $user = $this->findOrCreateUser($input);
            $token = $user->createToken('telegram_token')->plainTextToken;

            return response()->json([
                'status' => 'success',
                'token' => $token,
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'avatar' => $user->avatar
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Telegram Auth Failed:', [
                'error' => $e->getMessage(),
                'input' => $request->all()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 400);
        }
    }

    private function verifyTelegramHash(array $telegramUser): void
    {
        if (!isset($telegramUser['hash'])) {
            throw new \Exception('Missing Telegram hash');
        }

        $checkHash = $telegramUser['hash'];
        unset($telegramUser['hash']);

        // Convert all values to strings
        $telegramUser = array_map(fn($v) => (string)$v, $telegramUser);

        // Sort keys by ASCII
        ksort($telegramUser);

        // Build data check string
        $dataCheckString = implode("\n", array_map(
            fn($k, $v) => $k . '=' . $v,
            array_keys($telegramUser),
            $telegramUser
        ));

        // Calculate HMAC
        $botToken = config('services.telegram.client_secret');
        $secretKey = hash('sha256', $botToken, true);
        $hash = hash_hmac('sha256', $dataCheckString, $secretKey);

        // Compare hashes
        if (!hash_equals(strtolower($checkHash), strtolower($hash))) {
            Log::debug('Telegram hash mismatch', [
                'checkHash' => $checkHash,
                'calculatedHash' => $hash,
                'dataCheckString' => $dataCheckString
            ]);
            throw new \Exception('Invalid Telegram data hash');
        }
    }

    private function findOrCreateUser(array $telegramUser): User
    {
        return User::updateOrCreate(
            ['provider' => 'telegram', 'provider_id' => $telegramUser['id']],
            [
                'name' => $telegramUser['first_name'] ?? $telegramUser['username'] ?? 'Telegram User',
                'email' => $telegramUser['email'] ?? ($telegramUser['id'] . '@telegram.user'),
                'avatar' => $telegramUser['photo_url'] ?? null,
                'password' => Hash::make(Str::random(16)),
                'role_id' => Role::where('name', 'customer')->first()->id,
                'is_verified' => true,
                'last_login_at' => now(),
            ]
        );
    }
}