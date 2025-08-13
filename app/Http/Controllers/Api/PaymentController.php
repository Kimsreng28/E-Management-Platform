<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use App\Services\KhqrService;
use Stripe\Stripe;
use Stripe\PaymentIntent;
use App\Models\Order;
use App\Models\Payment;

class PaymentController
{
    protected $khqrService;

    public function __construct(KhqrService $khqrService)
    {
        $this->khqrService = $khqrService;
    }

    // Create payment record with Stripe or KHQR QR
    public function store(Request $request)
    {
        $validated = $request->validate([
            'order_id' => 'required|exists:orders,id',
            'payment_method' => 'required|in:stripe,khqr',
            'amount' => 'required|numeric|min:0',
            'currency' => 'required|string|size:3',
            'transaction_id' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        $user = $request->user();
        $order = Order::findOrFail($validated['order_id']);

        if ($order->user_id !== $user->id && !$user->hasRole('admin')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $paymentData = [
            'order_id' => $order->id,
            'payment_method' => $validated['payment_method'],
            'amount' => $validated['amount'],
            'status' => 'pending',
            'notes' => $validated['notes'] ?? null,
        ];

        if ($validated['payment_method'] === 'stripe') {
            // Initialize Stripe
            Stripe::setApiKey(env('STRIPE_SECRET'));

            // Create PaymentIntent
            $paymentIntent = PaymentIntent::create([
                'amount' => (int)($validated['amount'] * 100), // cents
                'currency' => strtolower($validated['currency']),
                'metadata' => ['order_id' => $order->id],
            ]);

            $paymentData['transaction_id'] = $paymentIntent->id;
            $paymentData['status'] = 'pending';

            $payment = Payment::create($paymentData);

            return response()->json([
                'success' => true,
                'payment' => $payment,
                'client_secret' => $paymentIntent->client_secret,
            ]);
        }

        if ($validated['payment_method'] === 'khqr') {
            // Generate KHQR code payload
            try {
                $merchantName = env('KHQR_MERCHANT_NAME', 'Merchant');
                $merchantAccount = env('KHQR_MERCHANT_ACCOUNT', 'account');
                $currency = $validated['currency'];
                $amount = $validated['amount'];
                $orderId = $order->order_number;

                $qrPayload = $this->khqrService->generateValidKHQR($merchantName, $merchantAccount, $amount, $currency, $orderId);

                $payment = Payment::create($paymentData);

                return response()->json([
                    'success' => true,
                    'payment' => $payment,
                    'khqr_payload' => $qrPayload,
                ]);
            } catch (\Exception $e) {
                return response()->json(['success' => false, 'message' => 'KHQR generation failed', 'error' => $e->getMessage()], 500);
            }
        }

        return response()->json(['success' => false, 'message' => 'Unsupported payment method'], 400);
    }

    // List all
    public function index()
    {
        $payments = Payment::with('order')->paginate(20);

        return response()->json($payments);
    }

    // Show specific payment
    public function show(Payment $payment)
    {
        return response()->json($payment);
    }

    // Update specific payment
    public function update(Request $request, Payment $payment)
    {
        $validated = $request->validate([
            'payment_method' => 'sometimes|string',
            'amount' => 'sometimes|numeric|min:0',
            'transaction_id' => 'sometimes|string',
            'status' => 'sometimes|in:pending,completed,failed,refunded',
            'notes' => 'nullable|string',
            'paid_at' => 'nullable|date',
        ]);

        $payment->update($validated);

        return response()->json(['success' => true, 'payment' => $payment]);
    }

    public function destroy(Payment $payment)
    {
        $payment->delete();

        return response()->json(['success' => true, 'message' => 'Payment deleted']);
    }

}
