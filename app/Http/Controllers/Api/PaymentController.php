<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use App\Services\KhqrService;
use Stripe\Stripe;
use Stripe\PaymentIntent;
use App\Models\Order;
use App\Models\Payment;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Auth;
use App\Models\BusinessSetting;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class PaymentController
{
    protected $khqrService;
    protected NotificationService $notify;

    // Cache durations in seconds
    private $cacheDurations = [
        'payment_list' => 300, // 5 minutes (payments change frequently)
        'payment_detail' => 600, // 10 minutes
        'payment_status' => 30, // 30 seconds for status checks
        'khqr_data' => 300, // 5 minutes for KHQR data
    ];

    public function __construct(KhqrService $khqrService, NotificationService $notify)
    {
        $this->khqrService = $khqrService;
        $this->notify = $notify;
    }

    // Clear payment-related caches
    private function clearPaymentCaches($paymentId = null, $orderId = null)
    {
        Cache::forget('payments:list');

        if ($paymentId) {
            Cache::forget("payment:{$paymentId}");
            Cache::forget("payment:status:{$paymentId}");
        }

        if ($orderId) {
            Cache::forget("order:{$orderId}");
            Cache::forget("payments:order:{$orderId}");
        }

        // Clear all payment listing caches
        $keys = Cache::getRedis()->keys('*payments:*');
        foreach ($keys as $key) {
            Cache::forget(str_replace('laravel_database_', '', $key));
        }

        // Clear order stats cache
        Cache::forget('order:stats');
    }

    // Create payment record with Stripe or KHQR QR
    public function store(Request $request)
    {
        $validated = $request->validate([
            'order_id' => 'required|exists:orders,id',
            'payment_method' => 'required|in:stripe,khqr,cod',
            'amount' => 'required|numeric|min:0',
            'currency' => 'required|string|size:3',
            'transaction_id' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        $order = Order::findOrFail($validated['order_id']);

        // Fetch user's business settings
        $settings = BusinessSetting::first();

        if (!$settings) {
            return response()->json([
                'success' => false,
                'message' => 'Business settings not found for this user'
            ], 404);
        }

        $paymentData = [
            'order_id' => $order->id,
            'payment_method' => $validated['payment_method'],
            'amount' => $validated['amount'],
            'status' => 'pending',
            'notes' => $validated['notes'] ?? null,
        ];

        // Stripe Payment
        if ($validated['payment_method'] === 'stripe') {

            if (!$settings->stripe_enabled || !$settings->stripe_secret_key) {
                return response()->json([
                    'success' => false,
                    'message' => 'Stripe is not enabled or configured for this business'
                ], 400);
            }

            Stripe::setApiKey($settings->stripe_secret_key);

            $paymentIntent = PaymentIntent::create([
                'amount' => (int)($validated['amount'] * 100), // cents
                'currency' => strtolower($validated['currency']),
                'metadata' => ['order_id' => $order->id],
            ]);

            $paymentData['transaction_id'] = $paymentIntent->id;
            $paymentData['status'] = 'pending';

            $payment = Payment::create($paymentData);

            // Clear relevant caches
            $this->clearPaymentCaches(null, $order->id);

            $this->notify->notifyPaymentCreated($payment, $validated['currency']);

            return response()->json([
                'success' => true,
                'payment' => $payment,
                'client_secret' => $paymentIntent->client_secret,
            ]);
        }

        // KHQR Payment
        if ($validated['payment_method'] === 'khqr') {

            if (!$settings->khqr_enabled || !$settings->khqr_merchant_name || !$settings->khqr_merchant_account) {
                return response()->json([
                    'success' => false,
                    'message' => 'KHQR is not enabled or configured for this business'
                ], 400);
            }

            try {
                $cacheKey = "khqr:data:" . md5(serialize([
                    $settings->khqr_merchant_name,
                    $settings->khqr_merchant_account,
                    $validated['amount'],
                    $validated['currency'],
                    $order->order_number
                ]));

                $qrResult = Cache::remember($cacheKey, $this->cacheDurations['khqr_data'], function () use ($settings, $validated, $order) {
                    $result = $this->khqrService->generateIndividualKHQR(
                        $settings->khqr_merchant_name,
                        $settings->khqr_merchant_account,
                        $validated['amount'],
                        $validated['currency'],
                        $order->order_number
                    );

                    if (!$result['success']) {
                        throw new \Exception($result['error'] ?? 'KHQR generation failed');
                    }

                    return $result;
                });

                $paymentData['transaction_id'] = $qrResult['md5_hash'];
                $payment = Payment::create($paymentData);

                // Clear relevant caches
                $this->clearPaymentCaches(null, $order->id);

                $this->notify->notifyPaymentCreated($payment, $validated['currency']);

                return response()->json([
                    'success' => true,
                    'payment' => $payment,
                    'khqr_payload' => $qrResult['qr_code'],
                    'md5_hash' => $qrResult['md5_hash'],
                ]);
            } catch (\Exception $e) {
                return response()->json([
                    'success' => false,
                    'message' => 'KHQR generation failed',
                    'error' => $e->getMessage()
                ], 500);
            }
        }

        // COD
        if ($validated['payment_method'] === 'cod') {
            $paymentData['status'] = 'pending'; // or 'cod'
            $payment = Payment::create($paymentData);

            // Clear relevant caches
            $this->clearPaymentCaches(null, $order->id);

            $this->notify->notifyPaymentCreated($payment, $validated['currency']);

            return response()->json([
                'success' => true,
                'payment' => $payment,
                'message' => 'Payment created for Cash on Delivery'
            ]);
        }

        return response()->json(['success' => false, 'message' => 'Unsupported payment method'], 400);
    }

    public function checkKhqrPaymentStatus(Request $request)
    {
        $validated = $request->validate([
            'payment_id' => 'required|exists:payments,id',
            'md5_hash' => 'nullable|string'
        ]);

        $cacheKey = "payment:status:{$validated['payment_id']}:" . ($validated['md5_hash'] ?? 'default');

        try {
            // Use cache for frequent status checks with very short TTL
            $result = Cache::remember($cacheKey, $this->cacheDurations['payment_status'], function () use ($validated) {
                // Use the KhqrService to check payment status
                return $this->khqrService->checkPaymentStatus(
                    $validated['payment_id'],
                    $validated['md5_hash'] ?? null
                );
            });

            if (!$result['success']) {
                return response()->json($result, 400);
            }

            // If payment is completed, clear relevant caches
            if ($result['status'] === 'completed') {
                $this->clearPaymentCaches($validated['payment_id']);
                Cache::forget($cacheKey); // Clear status cache
            }

            return response()->json([
                'success' => true,
                'payment_status' => $result['status'],
                'transaction_status' => $result['status'],
                'payment' => $result['payment'],
                'message' => $result['status'] === 'completed'
                    ? 'Payment completed successfully'
                    : 'Payment still pending'
            ]);

        } catch (\Exception $e) {
            Log::error('KHQR Status Check Error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error checking payment status'
            ], 500);
        }
    }

    // List all payments
    public function index(Request $request)
    {
        $cacheKey = 'payments:list:' . md5(serialize($request->all()));

        $payments = Cache::remember($cacheKey, $this->cacheDurations['payment_list'], function () use ($request) {
            $query = Payment::with('order');

            // Filter by payment method
            if ($request->filled('payment_method')) {
                $query->where('payment_method', $request->payment_method);
            }

            // Filter by status
            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            // Search
            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('transaction_id', 'LIKE', "%{$search}%")
                      ->orWhere('payment_method', 'LIKE', "%{$search}%")
                      ->orWhereHas('order', function($orderQuery) use ($search) {
                          $orderQuery->where('order_number', 'LIKE', "%{$search}%");
                      });
                });
            }

            // Sorting
            $sortBy = $request->get('sort_by', 'created_at');
            $sortOrder = $request->get('sort_order', 'desc');
            $query->orderBy($sortBy, $sortOrder);

            // Pagination
            $perPage = $request->get('per_page', 20);
            return $query->paginate($perPage);
        });

        return response()->json($payments);
    }

    // Show specific payment
    public function show(Payment $payment)
    {
        $cacheKey = "payment:{$payment->id}";

        $paymentData = Cache::remember($cacheKey, $this->cacheDurations['payment_detail'], function () use ($payment) {
            return $payment->load('order.user', 'order.items');
        });

        return response()->json($paymentData);
    }

    // Update specific payment
    public function update(Request $request, Payment $payment)
    {
        $oldStatus = $payment->status;

        $validated = $request->validate([
            'payment_method' => 'sometimes|string',
            'amount' => 'sometimes|numeric|min:0',
            'transaction_id' => 'sometimes|string',
            'status' => 'sometimes|in:pending,completed,failed,refunded',
            'notes' => 'nullable|string',
            'paid_at' => 'nullable|date',
        ]);

        $payment->update($validated);

        // Clear relevant caches
        $this->clearPaymentCaches($payment->id, $payment->order_id);

        if (array_key_exists('status', $validated) && $validated['status'] !== $oldStatus) {
            $this->notify->notifyPaymentStatusChanged($payment);
        }

        return response()->json(['success' => true, 'payment' => $payment]);
    }

    public function destroy(Payment $payment)
    {
        $paymentId = $payment->id;
        $orderId = $payment->order_id;

        $payment->delete();

        // Clear relevant caches
        $this->clearPaymentCaches($paymentId, $orderId);

        return response()->json(['success' => true, 'message' => 'Payment deleted']);
    }

    // Get payments by order
    public function getPaymentsByOrder($orderId)
    {
        $cacheKey = "payments:order:{$orderId}";

        $payments = Cache::remember($cacheKey, $this->cacheDurations['payment_list'], function () use ($orderId) {
            return Payment::where('order_id', $orderId)
                ->orderBy('created_at', 'desc')
                ->get();
        });

        return response()->json($payments);
    }

    // Get payment statistics
    public function getPaymentStats()
    {
        $cacheKey = 'payment:stats';

        $stats = Cache::remember($cacheKey, 300, function () { // 5 minutes cache
            return [
                'total_payments' => Payment::count(),
                'total_revenue' => Payment::where('status', 'completed')->sum('amount'),
                'pending_payments' => Payment::where('status', 'pending')->count(),
                'completed_payments' => Payment::where('status', 'completed')->count(),
                'failed_payments' => Payment::where('status', 'failed')->count(),
                'stripe_payments' => Payment::where('payment_method', 'stripe')->count(),
                'khqr_payments' => Payment::where('payment_method', 'khqr')->count(),
                'cod_payments' => Payment::where('payment_method', 'cod')->count(),
            ];
        });

        return response()->json($stats);
    }

    // Get recent payments
    public function getRecentPayments($limit = 10)
    {
        $cacheKey = "payments:recent:{$limit}";

        $payments = Cache::remember($cacheKey, 300, function () use ($limit) { // 5 minutes cache
            return Payment::with('order')
                ->orderBy('created_at', 'desc')
                ->limit($limit)
                ->get();
        });

        return response()->json($payments);
    }

    // Verify payment (for webhooks or manual verification)
    public function verifyPayment(Payment $payment)
    {
        $cacheKey = "payment:verify:{$payment->id}";

        $verificationResult = Cache::remember($cacheKey, 60, function () use ($payment) { // 1 minute cache for verification
            try {
                // Simulate payment verification logic
                $isValid = $payment->status === 'completed' &&
                          $payment->transaction_id &&
                          $payment->amount > 0;

                return [
                    'success' => $isValid,
                    'payment_id' => $payment->id,
                    'status' => $payment->status,
                    'amount' => $payment->amount,
                    'is_verified' => $isValid,
                    'verified_at' => now()->toISOString(),
                ];
            } catch (\Exception $e) {
                return [
                    'success' => false,
                    'error' => $e->getMessage(),
                    'is_verified' => false,
                ];
            }
        });

        return response()->json($verificationResult);
    }

    // Cache debugging methods
    public function debugPaymentCache($key)
    {
        $exists = Cache::has($key);
        $data = Cache::get($key);
        $ttl = Cache::getRedis()->ttl(Cache::getPrefix() . $key);

        return [
            'key' => $key,
            'exists' => $exists,
            'ttl_seconds' => $ttl,
            'data_sample' => $exists ? (is_array($data) ? array_slice($data, 0, 2) : $data) : null
        ];
    }

    public function getPaymentCacheKeys()
    {
        $keys = Cache::getRedis()->keys('*payment*');

        $cacheInfo = [];
        foreach ($keys as $key) {
            $cleanKey = str_replace('laravel_database_', '', $key);
            $ttl = Cache::getRedis()->ttl($key);
            $size = strlen(serialize(Cache::get($cleanKey)));
            $cacheInfo[] = [
                'key' => $cleanKey,
                'ttl' => $ttl,
                'size_bytes' => $size,
                'size_human' => $this->formatBytes($size)
            ];
        }

        return $cacheInfo;
    }

    private function formatBytes($bytes, $precision = 2)
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);

        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}
