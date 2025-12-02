<?php

namespace App\Services;

use KHQR\BakongKHQR;
use KHQR\Models\IndividualInfo;
use KHQR\Helpers\KHQRData;
use Exception;
use App\Models\Payment;
use App\Models\BusinessSetting;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class KhqrService
{
    protected $bakongKHQR;

    public function __construct($userId = null)
    {
        // $token = env('BAKONG_API_TOKEN');
        // if ($token) {
        //     $this->bakongKHQR = new BakongKHQR($token);
        // }
        $token = $this->getUserToken($userId);
        if ($token) {
            $this->bakongKHQR = new BakongKHQR($token);
        }
    }

    /**
     * Get KHQR API token from user's business settings
     */
    private function getUserToken($userId = null)
    {
        try {
            if (!$userId) {
                // Get current authenticated user
                $userId = Auth::id();
            }

            $settings = BusinessSetting::where('user_id', $userId)->first();

            if ($settings && $settings->khqr_enabled && $settings->khqr_api_token) {
                return $settings->khqr_api_token;
            }

            // Fallback to env if no user-specific token
            return env('BAKONG_API_TOKEN');

        } catch (Exception $e) {
            Log::error('Error getting KHQR token', ['error' => $e->getMessage()]);
            return env('BAKONG_API_TOKEN');
        }
    }

    /**
     * Generate KHQR for individual payments
     */
    public function generateIndividualKHQR($merchantName, $merchantAccount, $amount, $currency, $orderId = null, $userId = null)
    {
        try {
            // Get user-specific token
            $token = $this->getUserToken($userId);
            if (!$token) {
                throw new Exception('KHQR API token not configured');
            }

            $currencyConstant = $this->mapCurrency($currency);

            $individualInfo = new IndividualInfo(
                bakongAccountID: $merchantAccount,
                merchantName: $merchantName,
                merchantCity: 'Phnom Penh',
                currency: $currencyConstant,
                amount: $amount
            );

            // Initialize with user-specific token
            $bakongKHQR = new BakongKHQR($token);
            $response = $bakongKHQR->generateIndividual($individualInfo);

            if ($response->status['code'] !== 0) {
                throw new Exception($response->status['message'] ?? 'Unknown error');
            }

            return [
                'success' => true,
                'qr_code' => $response->data['qr'],
                'md5_hash' => $response->data['md5'],
                'order_id' => $orderId
            ];

        } catch (Exception $e) {
            Log::error('KHQR Error', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Check payment status using MD5 hash
     */
    public function checkPaymentStatus($paymentId, $md5Hash, $userId = null)
    {
        try {
            $payment = Payment::with('order')->find($paymentId);

            if (!$payment) {
                return ['success' => false, 'error' => 'Payment not found'];
            }

            // Get user-specific token
            $token = $this->getUserToken($userId);
            if (!$token) {
                throw new Exception('KHQR API token not configured');
            }

            // Initialize with user-specific token
            $bakongKHQR = new BakongKHQR($token);

            $baseResponse = [
                'success' => true,
                'payment' => $payment,
            ];

            // Already completed
            if ($payment->status === 'completed') {
                return array_merge($baseResponse, [
                    'status' => 'completed',
                ]);
            }

            // If user provided md5 hash, check with Bakong
            if ($md5Hash && $bakongKHQR) {
                $response = $bakongKHQR->checkTransactionByMD5($md5Hash);

                if (!empty($response['data'])) {
                    $payment->update([
                        'status' => 'completed',
                        'transaction_id' => $md5Hash,
                        'paid_at' => now(),
                    ]);

                    if ($payment->order) {
                        $payment->order->update(['status' => 'processing']);
                    }

                    // Return updated payment model
                    $payment->refresh();

                    return array_merge($baseResponse, [
                        'payment' => $payment,
                        'status' => 'completed',
                    ]);
                }
            }

            // Still pending
            return array_merge($baseResponse, [
                'status' => 'pending',
            ]);

        } catch (Exception $e) {
            Log::error('PaymentCheck Error', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Check if Bakong account exists
     */
    public function checkBakongAccount($accountId, $userId = null)
    {
        try {
            // Get user-specific token
            $token = $this->getUserToken($userId);
            if (!$token) {
                throw new Exception('KHQR API token not configured');
            }

            // Initialize with user-specific token
            $bakongKHQR = new BakongKHQR($token);
            $response = $bakongKHQR->checkBakongAccount($accountId);

            return [
                'success' => true,
                'exists' => $response->data['bakongAccountExists'] ?? false
            ];

        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Only used if your app needs decoding
     */
    public function decode($qr, $userId = null)
    {
        $token = $this->getUserToken($userId);
        $bakongKHQR = new BakongKHQR($token);
        return $bakongKHQR->decode($qr);
    }

    /**
     * Only used if your app needs CRC verification
     */
    public function verify($qr, $userId = null)
    {
        $token = $this->getUserToken($userId);
        $bakongKHQR = new BakongKHQR($token);
        return $bakongKHQR->verify($qr);
    }

    /**
     * Map currency code to KHQR package constants
     */
    private function mapCurrency($currency)
    {
        return match (strtoupper($currency)) {
            'KHR' => KHQRData::CURRENCY_KHR,
            'USD' => KHQRData::CURRENCY_USD,
            default => KHQRData::CURRENCY_USD,
        };
    }
}
