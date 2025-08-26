<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Models\TelegramNotification;
use Illuminate\Support\Facades\Http;
use App\Models\Product;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;


class NotificationService
{
    /** Notify the buyer + admins when an order is created */
    public function notifyOrderCreated(Order $order): void
    {
        $user = $order->user;

        // 1) Notify the buyer (DB)
        $user->notifications()->create([
            'type' => 'order_created',
            'data' => [
                'title'   => 'Order Placed',
                'message' => "Your order {$order->order_number} has been placed.",
                'order_id'=> $order->id,
                'status'  => $order->status,
                'total'   => (string)$order->total,
            ],
        ]);

        // Buyer (Email)
        $this->sendEmail(
            $user->email,
            'Order Confirmation',
            "Your order {$order->order_number} has been placed successfully. Total: {$order->total}"
        );

        // 2) Notify admins (DB)
        User::admins()->get()->each(function ($admin) use ($order) {
            $admin->notifications()->create([
                'type' => 'order_created_admin',
                'data' => [
                    'title'   => 'New Order',
                    'message' => "New order {$order->order_number} placed by user #{$order->user_id}.",
                    'order_id'=> $order->id,
                    'status'  => $order->status,
                    'total'   => (string)$order->total,
                ],
            ]);

            $this->sendEmail(
                $admin->email,
                'New Order Placed',
                "Order {$order->order_number} was placed by user #{$order->user_id}. Total: {$order->total}"
            );
        });


        // 4) Optional: Telegram to buyer (if enabled)
        $this->maybeSendTelegramForUser(
            $user,
            "📦 Order Placed: {$order->order_number}\nTotal: {$order->total}\nStatus: {$order->status}"
        );

        // 5) Optional: Telegram to admins
        $this->maybeBroadcastTelegramToAdmins(
            "📦 New Order: {$order->order_number}\nUser ID: {$order->user_id}\nTotal: {$order->total}"
        );
    }

    /** Notify buyer + admins when a payment is created/initiated */
    public function notifyPaymentCreated(Payment $payment, string $currency): void
    {
        $order = $payment->order;
        $user  = $order->user;

        // 1) Buyer (DB)
        $user->notifications()->create([
            'type' => 'payment_created',
            'data' => [
                'title'       => 'Payment Initiated',
                'message'     => "Payment {$payment->amount} {$currency} for order {$order->order_number} is initiated.",
                'order_id'    => $order->id,
                'payment_id'  => $payment->id,
                'status'      => $payment->status,
                'method'      => $payment->payment_method,
            ],
        ]);

        // Buyer (Email)
        $this->sendEmail(
            $user->email,
            'Payment Initiated',
            "Your payment of {$payment->amount} {$currency} for order {$order->order_number} has been initiated."
        );

        // 2) Admins (DB)
        User::admins()->get()->each(function ($admin) use ($payment, $order, $currency) {
            $admin->notifications()->create([
                'type' => 'payment_created_admin',
                'data' => [
                    'title'      => 'Payment Initiated',
                    'message'    => "Payment {$payment->amount} {$currency} for order {$order->order_number} ({$payment->payment_method}).",
                    'order_id'   => $order->id,
                    'payment_id' => $payment->id,
                    'status'     => $payment->status,
                ],
            ]);

            $this->sendEmail(
                $admin->email,
                'Payment Initiated',
                "Payment {$payment->amount} {$currency} for order {$order->order_number} ({$payment->payment_method}) has been initiated."
            );
        });

        // 4) Telegram to buyer
        $this->maybeSendTelegramForUser(
            $user,
            "💳 Payment Initiated\nOrder: {$order->order_number}\nAmount: {$payment->amount} {$currency}\nMethod: {$payment->payment_method}"
        );

        // 5) Telegram to admins
        $this->maybeBroadcastTelegramToAdmins(
            "💳 Payment Initiated\nOrder: {$order->order_number}\nAmount: {$payment->amount} {$currency}\nMethod: {$payment->payment_method}"
        );
    }

    /** Notify on payment status change (optional but useful) */
    public function notifyPaymentStatusChanged(Payment $payment): void
    {
        $order = $payment->order;
        $user  = $order->user;

        // Buyer (DB)
        $user->notifications()->create([
            'type' => 'payment_status',
            'data' => [
                'title'      => 'Payment Status Updated',
                'message'    => "Payment status for order {$order->order_number} changed to {$payment->status}.",
                'order_id'   => $order->id,
                'payment_id' => $payment->id,
                'status'     => $payment->status,
            ],
        ]);

        $this->sendEmail(
            $user->email,
            'Payment Status Updated',
            "Your payment for order {$order->order_number} is now {$payment->status}."
        );

        // Admins (DB)
        User::admins()->get()->each(function ($admin) use ($payment, $order) {
            $admin->notifications()->create([
                'type' => 'payment_status_admin',
                'data' => [
                    'title'      => 'Payment Status Updated',
                    'message'    => "Payment status for order {$order->order_number} changed to {$payment->status}.",
                    'order_id'   => $order->id,
                    'payment_id' => $payment->id,
                    'status'     => $payment->status,
                ],
            ]);

            $this->sendEmail(
                $admin->email,
                'Payment Status Updated',
                "Payment status for order {$order->order_number} changed to {$payment->status}."
            );
        });


        // Telegram
        $this->maybeSendTelegramForUser(
            $user,
            "🔔 Payment Status Updated\nOrder: {$order->order_number}\nStatus: {$payment->status}"
        );
        $this->maybeBroadcastTelegramToAdmins(
            "🔔 Payment Status Updated\nOrder: {$order->order_number}\nStatus: {$payment->status}"
        );
    }

    /** Send Telegram to a specific user if enabled */
    protected function maybeSendTelegramForUser(User $user, string $message): void
    {
        $settings = $user->notificationSettings;
        if (!$settings || !$settings->telegram || empty($settings->telegram_chat_id)) {
            return;
        }

        $this->sendTelegram($settings->telegram_chat_id, $message);
    }

    /** Broadcast Telegram to all admins who have Telegram enabled */
    protected function maybeBroadcastTelegramToAdmins(string $message): void
    {
        User::admins()->get()->each(function ($admin) use ($message) {
            $settings = $admin->notificationSettings;
            if ($settings && $settings->telegram && !empty($settings->telegram_chat_id)) {
                $this->sendTelegram($settings->telegram_chat_id, $message);
            }
        });
    }

    /** Send Telegram to stock alert */
    public function notifyStockAlert(Product $product): void
    {
        // Determine alert type
        if ($product->stock <= 0) {
            $type = 'out_of_stock';
            $title = 'Product Out of Stock';
            $message = "⚠️ The product '{$product->name}' is now out of stock.";
            $telegramMessage = "🛑 OUT OF STOCK: {$product->name} (Stock: 0)";
        } elseif ($product->stock <= $product->low_stock_threshold) {
            $type = 'low_stock';
            $title = 'Low Stock Alert';
            $message = "⚠️ The product '{$product->name}' has low stock: {$product->stock} remaining.";
            $telegramMessage = "⚠️ LOW STOCK: {$product->name} - Only {$product->stock} left";
        } else {
            $type = 'in_stock';
            $title = 'Product Back in Stock';
            $message = "✅ The product '{$product->name}' is back in stock with {$product->stock} available.";
            $telegramMessage = "✅ BACK IN STOCK: {$product->name} (Stock: {$product->stock})";
        }

        // Notify all admins
        $admins = User::admins()->get();

        if ($admins->isEmpty()) {
            return;
        }

        foreach ($admins as $admin) {
            // Create database notification for admin
            $admin->notifications()->create([
                'type' => $type,
                'notification_type' => 'admin', // Explicitly set as admin notification
                'data' => [
                    'title' => $title,
                    'message' => $message,
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'current_stock' => $product->stock,
                    'threshold' => $product->low_stock_threshold
                ],
            ]);

            // Email
            $this->sendEmail(
                $admin->email,
                $title,
                $message
            );

            // Send Telegram notification if enabled
            $settings = $admin->notificationSettings;
            if ($settings && $settings->telegram && !empty($settings->telegram_chat_id)) {
                $this->sendTelegram($settings->telegram_chat_id, $telegramMessage);
            }
        }
    }

    protected function sendEmail(string $to, string $subject, string $body): void
    {
        try {
            Mail::raw($body, function ($message) use ($to, $subject) {
                $message->to($to)->subject($subject);
            });
        } catch (\Exception $e) {
            Log::error("Failed to send email: " . $e->getMessage());
        }
    }

    /** Low-level Telegram sender + DB record */
    protected function sendTelegram(string $chatId, string $message, ?int $orderId = null): void
    {
        $botToken = config('services.telegram.bot_token', env('TELEGRAM_TOKEN'));
        if (!$botToken) {
            return;
        }

        $url = "https://api.telegram.org/bot{$botToken}/sendMessage";

        // Save in DB
        $tn = TelegramNotification::create([
            'order_id' => $orderId,
            'chat_id'  => $chatId,
            'message'  => $message,
            'is_sent'  => false,
        ]);

        // Call Telegram API
        $response = Http::post($url, [
            'chat_id' => $chatId,
            'text'    => $message,
        ]);

        if ($response->successful()) {
            $tn->update([
                'is_sent' => true,
                'sent_at' => now(),
            ]);
        }
    }
}
