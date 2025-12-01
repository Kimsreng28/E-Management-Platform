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
use App\Models\Product;

class PaymentController
{
    protected $khqrService;
    protected NotificationService $notify;

    public function __construct(KhqrService $khqrService, NotificationService $notify)
    {
        $this->khqrService = $khqrService;
        $this->notify = $notify;
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
        $settings = $this->getVendorSettingsFromOrder($order);

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

                $paymentData['transaction_id'] = $result['md5_hash'];
                $payment = Payment::create($paymentData);

                $this->notify->notifyPaymentCreated($payment, $validated['currency']);

                return response()->json([
                    'success' => true,
                    'payment' => $payment,
                    'khqr_payload' => $result['qr_code'],
                    'md5_hash' => $result['md5_hash'],
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

        try {
            // Use the KhqrService to check payment status
            $result = $this->khqrService->checkPaymentStatus(
                $validated['payment_id'],
                $validated['md5_hash'] ?? null
            );

            if (!$result['success']) {
                return response()->json($result, 400);
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

    private function getVendorSettingsFromOrder(Order $order)
    {
        // Get all unique vendor IDs from the order items
        $vendorIds = [];

        foreach ($order->items as $item) {
            if ($item->product && $item->product->vendor_id) { // Use vendor_id, not user_id
                $vendorIds[] = $item->product->vendor_id;
                Log::info('Payment - Found vendor for product', [
                    'product_id' => $item->product->id,
                    'vendor_id' => $item->product->vendor_id
                ]);
            }
        }

        $vendorIds = array_unique($vendorIds);

        Log::info('Payment - Vendor IDs found', ['vendor_ids' => $vendorIds]);

        // If order contains products from multiple vendors, use the first vendor's settings
        if (!empty($vendorIds)) {
            $vendorId = $vendorIds[0];
            $vendorSettings = BusinessSetting::where('user_id', $vendorId)->first();

            if ($vendorSettings) {
                Log::info('Using vendor settings for payment', [
                    'order_id' => $order->id,
                    'vendor_id' => $vendorId,
                    'vendor_settings_id' => $vendorSettings->id,
                    'business_name' => $vendorSettings->business_name
                ]);
                return $vendorSettings;
            }
        }

        // Fallback to admin settings if no vendor settings found
        $adminSettings = BusinessSetting::where('user_id', 1)->first();

        Log::info('Using admin settings as fallback for payment', [
            'order_id' => $order->id,
            'vendor_ids_found' => $vendorIds
        ]);

        return $adminSettings;
    }

    // List all payments
    public function index(Request $request)
    {
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
        $payments = $query->paginate($perPage);

        return response()->json($payments);
    }

    // Show specific payment
    public function show(Payment $payment)
    {
        $paymentData = $payment->load('order.user', 'order.items');

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

        if (array_key_exists('status', $validated) && $validated['status'] !== $oldStatus) {
            $this->notify->notifyPaymentStatusChanged($payment);
        }

        return response()->json(['success' => true, 'payment' => $payment]);
    }

    public function destroy(Payment $payment)
    {
        $payment->delete();

        return response()->json(['success' => true, 'message' => 'Payment deleted']);
    }

    // Get payments by order
    public function getPaymentsByOrder($orderId)
    {
        $payments = Payment::where('order_id', $orderId)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($payments);
    }

    // Get payment statistics
    public function getPaymentStats()
    {
        $stats = [
            'total_payments' => Payment::count(),
            'total_revenue' => Payment::where('status', 'completed')->sum('amount'),
            'pending_payments' => Payment::where('status', 'pending')->count(),
            'completed_payments' => Payment::where('status', 'completed')->count(),
            'failed_payments' => Payment::where('status', 'failed')->count(),
            'stripe_payments' => Payment::where('payment_method', 'stripe')->count(),
            'khqr_payments' => Payment::where('payment_method', 'khqr')->count(),
            'cod_payments' => Payment::where('payment_method', 'cod')->count(),
        ];

        return response()->json($stats);
    }

    // Get recent payments
    public function getRecentPayments($limit = 10)
    {
        $payments = Payment::with('order')
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();

        return response()->json($payments);
    }

    // Verify payment (for webhooks or manual verification)
    public function verifyPayment(Payment $payment)
    {
        try {
            // Simulate payment verification logic
            $isValid = $payment->status === 'completed' &&
                      $payment->transaction_id &&
                      $payment->amount > 0;

            $verificationResult = [
                'success' => $isValid,
                'payment_id' => $payment->id,
                'status' => $payment->status,
                'amount' => $payment->amount,
                'is_verified' => $isValid,
                'verified_at' => now()->toISOString(),
            ];

            return response()->json($verificationResult);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'is_verified' => false,
            ], 500);
        }
    }
}
