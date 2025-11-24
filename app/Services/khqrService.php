<?php

namespace App\Services;

use KHQR\BakongKHQR;
use KHQR\Models\IndividualInfo;
use KHQR\Helpers\KHQRData;
use Exception;
use App\Models\Payment;
use Illuminate\Support\Facades\Log;

class KhqrService
{
    protected $bakongKHQR;

    public function __construct()
    {
        $token = env('BAKONG_API_TOKEN');
        if ($token) {
            $this->bakongKHQR = new BakongKHQR($token);
        }
    }

    /**
     * Generate KHQR for individual payments
     */
    public function generateIndividualKHQR($merchantName, $merchantAccount, $amount, $currency, $orderId = null)
    {
        try {
            $currencyConstant = $this->mapCurrency($currency);

            $individualInfo = new IndividualInfo(
                bakongAccountID: $merchantAccount,
                merchantName: $merchantName,
                merchantCity: 'Phnom Penh',
                currency: $currencyConstant,
                amount: $amount
            );

            $response = BakongKHQR::generateIndividual($individualInfo);

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
    public function checkPaymentStatus($paymentId, $md5Hash)
    {
        try {
            $payment = Payment::with('order')->find($paymentId);

            if (!$payment) {
                return ['success' => false, 'error' => 'Payment not found'];
            }

            // Append payment to response ALWAYS
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
            if ($md5Hash && $this->bakongKHQR) {
                $response = $this->bakongKHQR->checkTransactionByMD5($md5Hash);

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
    public function checkBakongAccount($accountId)
    {
        try {
            $response = BakongKHQR::checkBakongAccount($accountId);

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
    public function decode($qr)
    {
        return BakongKHQR::decode($qr);
    }

    /**
     * Only used if your app needs CRC verification
     */
    public function verify($qr)
    {
        return BakongKHQR::verify($qr);
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