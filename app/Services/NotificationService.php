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
use App\Events\NotificationCreated;

class NotificationService
{
    /** Notify the buyer + admins when an order is created */
    /** Enhanced order creation notification */
    public function notifyOrderCreated(Order $order): void
    {
        $user = $order->user;

        // Detailed notification data
        $orderData = [
            'order_number' => $order->order_number,
            'total' => number_format($order->total, 2),
            'status' => $order->status,
            'items_count' => $order->items->count(),
            'created_at' => $order->created_at->format('M j, Y g:i A'),
            'shipping_method' => 'Standard Shipping', // You can customize this
            'estimated_delivery' => $order->created_at->addDays(5)->format('M j, Y'),
        ];

        // 1) Buyer Notification (Database)
        $notification = $user->notifications()->create([
            'type' => 'order_created',
            'data' => [
                'title' => '🎉 Order Confirmed!',
                'message' => "Your order #{$order->order_number} has been successfully placed.",
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'status' => $order->status,
                'total' => number_format($order->total, 2),
                'items_count' => $order->items->count(),
                'created_at' => $order->created_at->toISOString(),
            ],
            'read_at' => null,
        ]);

        // Trigger event
        event(new NotificationCreated($notification));

        // 2) Buyer Email (Detailed)
        $this->sendOrderConfirmationEmail($user, $order);

        // 3) Admin Notifications
        User::admins()->get()->each(function ($admin) use ($order, $orderData) {
            // Database notification
            $notification = $admin->notifications()->create([
                'type' => 'order_created_admin',
                'data' => [
                    'title' => '📦 New Order Received',
                    'message' => "New order #{$order->order_number} from {$order->user->name}",
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'customer_name' => $order->user->name,
                    'customer_email' => $order->user->email,
                    'total' => number_format($order->total, 2),
                    'items_count' => $order->items->count(),
                    'status' => $order->status,
                ],
                'read_at' => null,
            ]);

            // Trigger event
            event(new NotificationCreated($notification));

            // Admin email
            $this->sendAdminOrderNotification($admin, $order);
        });

        // 4) Vendor Notifications
        $this->notifyVendorsForOrder($order);

        // 5) Telegram notifications
        $this->sendOrderTelegramNotifications($order);
    }

    /** Notify vendors about new orders containing their products */
    protected function notifyVendorsForOrder(Order $order): void
    {
        // Get unique vendors from order items
        $vendors = [];

        foreach ($order->items as $item) {
            if ($item->product && $item->product->vendor) {
                $vendor = $item->product->vendor;
                $vendorId = $vendor->id;

                if (!isset($vendors[$vendorId])) {
                    $vendors[$vendorId] = [
                        'vendor' => $vendor,
                        'items' => []
                    ];
                }

                $vendors[$vendorId]['items'][] = $item;
            }
        }

        // Notify each vendor
        foreach ($vendors as $vendorData) {
            $vendor = $vendorData['vendor'];
            $vendorItems = $vendorData['items'];

            // Database notification for vendor
            $notification = $vendor->notifications()->create([
                'type' => 'order_created_vendor',
                'data' => [
                    'title' => '📦 New Order for Your Products',
                    'message' => "You have new order #{$order->order_number} containing your products",
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'customer_name' => $order->user->name,
                    'customer_email' => $order->user->email,
                    'items_count' => count($vendorItems),
                    'total_items_value' => collect($vendorItems)->sum('total_price'),
                    'status' => $order->status,
                ],
                'read_at' => null,
            ]);

            // Trigger event
            event(new NotificationCreated($notification));

            // Vendor email
            $this->sendVendorOrderNotification($vendor, $order, $vendorItems);

            // Vendor Telegram
            $this->sendVendorTelegramNotification($vendor, $order, $vendorItems);
        }
    }

    /** Send order notification email to vendor */
    protected function sendVendorOrderNotification(User $vendor, Order $order, array $vendorItems): void
    {
        $subject = "New Order #{$order->order_number} - Your Products";

        $itemsHtml = '';
        $totalValue = 0;

        foreach ($vendorItems as $item) {
            $itemTotal = $item->unit_price * $item->quantity;
            $totalValue += $itemTotal;
            $itemsHtml .= "<li>{$item->product_name} (Qty: {$item->quantity}) - $" . number_format($item->unit_price, 2) . " each = $" . number_format($itemTotal, 2) . "</li>";
        }

        $emailContent = "
            <h2>📦 New Order for Your Products</h2>
            <p>Hello {$vendor->name},</p>
            <p>You have received a new order containing your products.</p>
            <p><b>Order Details:</b></p>
            <p>Order #: <b>{$order->order_number}</b></p>
            <p>Customer: {$order->user->name} ({$order->user->email})</p>
            <p><b>Your Products in this Order:</b></p>
            <ul>{$itemsHtml}</ul>
            <p><b>Total Value of Your Products:</b> $" . number_format($totalValue, 2) . "</p>
            <p>Order Status: {$order->status}</p>
            <p>Placed on: {$order->created_at->format('M j, Y g:i A')}</p>
            <p>Please prepare the items for shipping.</p>
        ";

        try {
            Mail::send([], [], function ($message) use ($vendor, $subject, $emailContent) {
                $message->to($vendor->email)
                    ->subject($subject)
                    ->html($emailContent);
            });
        } catch (\Exception $e) {
            Log::error("Vendor order notification email failed: " . $e->getMessage());
        }
    }

    /** Send Telegram notification to vendor */
    protected function sendVendorTelegramNotification(User $vendor, Order $order, array $vendorItems): void
    {
        $itemsCount = count($vendorItems);
        $totalValue = collect($vendorItems)->sum('total_price');

        $vendorMessage = "📦 *NEW ORDER - YOUR PRODUCTS*

        🆔 *Order #:* {$order->order_number}
        👤 *Customer:* {$order->user->name}
        📧 *Email:* {$order->user->email}
        📦 *Your Items:* {$itemsCount} product(s)
        💰 *Total Value:* \$" . number_format($totalValue, 2) . "
        📅 *Order Date:* {$order->created_at->format('M j, Y g:i A')}
        🏷️ *Status:* {$order->status}

        _Please prepare your items for shipping._";

        $this->maybeSendTelegramForUser($vendor, $vendorMessage, $order->id);
    }

    /** Notify about order status changes to buyer, admins, and vendors */
    public function notifyOrderStatusChanged(Order $order, string $oldStatus = null): void
    {
        $user = $order->user;
        $newStatus = $order->status;

        // Status change messages
        $statusMessages = [
            'pending' => ['title' => '⏳ Order Pending', 'message' => 'Your order is being processed'],
            'processing' => ['title' => '🔄 Order Processing', 'message' => 'We are preparing your order'],
            'shipped' => ['title' => '🚚 Order Shipped', 'message' => 'Your order has been shipped'],
            'delivered' => ['title' => '✅ Order Delivered', 'message' => 'Your order has been delivered'],
            'completed' => ['title' => '🎉 Order Completed', 'message' => 'Your order is complete'],
            'cancelled' => ['title' => '❌ Order Cancelled', 'message' => 'Your order has been cancelled'],
            'refunded' => ['title' => '💸 Order Refunded', 'message' => 'Your order has been refunded'],
        ];

        $statusInfo = $statusMessages[$newStatus] ?? ['title' => 'Order Updated', 'message' => 'Your order status has been updated'];

        // 1) Buyer Notification
        $notification = $user->notifications()->create([
            'type' => 'order_status_changed',
            'data' => [
                'title' => $statusInfo['title'],
                'message' => "Order #{$order->order_number} is now {$newStatus}. " . $statusInfo['message'],
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'changed_at' => now()->toISOString(),
            ],
            'read_at' => null,
        ]);

        event(new NotificationCreated($notification));

        // Buyer email
        $this->sendOrderStatusEmail($user, $order, $oldStatus, $newStatus);

        // 2) Admin Notifications
        User::admins()->get()->each(function ($admin) use ($order, $oldStatus, $newStatus) {
            $admin->notifications()->create([
                'type' => 'order_status_changed_admin',
                'data' => [
                    'title' => '📊 Order Status Updated',
                    'message' => "Order #{$order->order_number} status changed from {$oldStatus} to {$newStatus}",
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'customer_name' => $order->user->name,
                    'old_status' => $oldStatus,
                    'new_status' => $newStatus,
                ],
            ]);

            $this->sendAdminStatusEmail($admin, $order, $oldStatus, $newStatus);
        });

        // 3) Vendor Notifications
        $this->notifyVendorsOrderStatusChanged($order, $oldStatus, $newStatus);

        // 4) Telegram notifications
        $this->sendOrderStatusTelegramNotifications($order, $oldStatus, $newStatus);
    }

     /** Notify vendors about order status changes */
    protected function notifyVendorsOrderStatusChanged(Order $order, string $oldStatus, string $newStatus): void
    {
        $vendors = [];

        foreach ($order->items as $item) {
            if ($item->product && $item->product->vendor) {
                $vendor = $item->product->vendor;
                $vendorId = $vendor->id;

                if (!isset($vendors[$vendorId])) {
                    $vendors[$vendorId] = $vendor;
                }
            }
        }

        foreach ($vendors as $vendor) {
            // Database notification for vendor
            $vendor->notifications()->create([
                'type' => 'order_status_changed_vendor',
                'data' => [
                    'title' => '📊 Order Status Updated',
                    'message' => "Order #{$order->order_number} containing your products changed from {$oldStatus} to {$newStatus}",
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'customer_name' => $order->user->name,
                    'old_status' => $oldStatus,
                    'new_status' => $newStatus,
                ],
            ]);

            // Vendor email
            $this->sendVendorStatusEmail($vendor, $order, $oldStatus, $newStatus);

            // Vendor Telegram
            $this->sendVendorStatusTelegram($vendor, $order, $oldStatus, $newStatus);
        }
    }

    /** Send order status email to buyer */
    protected function sendOrderStatusEmail(User $user, Order $order, string $oldStatus, string $newStatus): void
    {
        $subject = "Order #{$order->order_number} Status Updated to " . ucfirst($newStatus);

        $emailContent = "
            <h2>Order Status Updated</h2>
            <p>Hi {$user->name},</p>
            <p>The status of your order <b>#{$order->order_number}</b> has been updated.</p>
            <p><b>Previous Status:</b> " . ucfirst($oldStatus) . "</p>
            <p><b>Current Status:</b> " . ucfirst($newStatus) . "</p>
            <p><b>Update Time:</b> " . now()->format('M j, Y g:i A') . "</p>
        ";

        try {
            Mail::send([], [], function ($message) use ($user, $subject, $emailContent) {
                $message->to($user->email)
                    ->subject($subject)
                    ->html($emailContent);
            });
        } catch (\Exception $e) {
            Log::error("Order status email failed: " . $e->getMessage());
        }
    }

    /** Send order status email to admin */
    protected function sendAdminStatusEmail(User $admin, Order $order, string $oldStatus, string $newStatus): void
    {
        $subject = "Order #{$order->order_number} Status Changed";

        $emailContent = "
            <h2>Order Status Changed</h2>
            <p>Order #: <b>{$order->order_number}</b></p>
            <p>Customer: {$order->user->name} ({$order->user->email})</p>
            <p><b>Status Changed:</b> {$oldStatus} → {$newStatus}</p>
            <p><b>Change Time:</b> " . now()->format('M j, Y g:i A') . "</p>
        ";

        try {
            Mail::send([], [], function ($message) use ($admin, $subject, $emailContent) {
                $message->to($admin->email)
                    ->subject($subject)
                    ->html($emailContent);
            });
        } catch (\Exception $e) {
            Log::error("Admin status email failed: " . $e->getMessage());
        }
    }

    /** Send order status email to vendor */
    protected function sendVendorStatusEmail(User $vendor, Order $order, string $oldStatus, string $newStatus): void
    {
        $subject = "Order #{$order->order_number} Status Updated";

        $emailContent = "
            <h2>Order Status Updated - Your Products</h2>
            <p>Hello {$vendor->name},</p>
            <p>The status of order <b>#{$order->order_number}</b> containing your products has been updated.</p>
            <p>Customer: {$order->user->name} ({$order->user->email})</p>
            <p><b>Status Changed:</b> {$oldStatus} → {$newStatus}</p>
            <p><b>Update Time:</b> " . now()->format('M j, Y g:i A') . "</p>
        ";

        try {
            Mail::send([], [], function ($message) use ($vendor, $subject, $emailContent) {
                $message->to($vendor->email)
                    ->subject($subject)
                    ->html($emailContent);
            });
        } catch (\Exception $e) {
            Log::error("Vendor status email failed: " . $e->getMessage());
        }
    }

    /** Send Telegram notifications for order status change */
    protected function sendOrderStatusTelegramNotifications(Order $order, string $oldStatus, string $newStatus): void
    {
        $user = $order->user;

        // Buyer message
        $buyerMessage = "📊 *Order Status Updated*

        🆔 *Order #:* {$order->order_number}
        🔄 *Status Changed:* {$oldStatus} → {$newStatus}
        📅 *Updated:* " . now()->format('M j, Y g:i A') . "

        _We'll keep you updated on your order progress._";

        $this->maybeSendTelegramForUser($user, $buyerMessage, $order->id);

        // Admin message
        $adminMessage = "📊 *ORDER STATUS UPDATED*

        🆔 *Order #:* {$order->order_number}
        👤 *Customer:* {$order->user->name}
        🔄 *Status:* {$oldStatus} → {$newStatus}
        📅 *Updated:* " . now()->format('M j, Y g:i A');

        $this->maybeBroadcastTelegramToAdmins($adminMessage, $order->id);
    }

    /** Send vendor status Telegram */
    protected function sendVendorStatusTelegram(User $vendor, Order $order, string $oldStatus, string $newStatus): void
    {
        $vendorMessage = "📊 *ORDER STATUS UPDATED - YOUR PRODUCTS*

        🆔 *Order #:* {$order->order_number}
        👤 *Customer:* {$order->user->name}
        🔄 *Status:* {$oldStatus} → {$newStatus}
        📅 *Updated:* " . now()->format('M j, Y g:i A');

        $this->maybeSendTelegramForUser($vendor, $vendorMessage, $order->id);
    }

    /** Enhanced order confirmation email */
    protected function sendOrderConfirmationEmail(User $user, Order $order): void
    {
        $subject = "Order Confirmation #{$order->order_number}";

        $itemsHtml = '';

        foreach ($order->items as $item) {
            $itemsHtml .= "<li>{$item->product_name} (Qty: {$item->quantity}) - $" . number_format($item->unit_price, 2) . "</li>";
        }

        $emailContent = "
            <h2>🎉 Order Confirmed!</h2>
            <p>Hi {$user->name},</p>
            <p>Your order <b>#{$order->order_number}</b> has been successfully placed.</p>
            <p><b>Order Details:</b></p>
            <ul>
                {$itemsHtml}
            </ul>
            <p><b>Total:</b> $" . number_format($order->total, 2) . "</p>
            <p>Status: {$order->status}</p>
            <p>Placed on: {$order->created_at->format('M j, Y g:i A')}</p>
            <p>Thank you for shopping with us!</p>
        ";

        try {
            Mail::send([], [], function ($message) use ($user, $subject, $emailContent) {
                $message->to($user->email)
                    ->subject($subject)
                    ->html($emailContent);
            });
        } catch (\Exception $e) {
            Log::error("Order confirmation email failed: " . $e->getMessage());
        }
    }

    /** Admin order notification email */
    protected function sendAdminOrderNotification(User $admin, Order $order): void
    {
        $subject = "New Order #{$order->order_number} - {$order->user->name}";

        $itemsHtml = '';
        foreach ($order->items as $item) {
            $itemsHtml .= "<li>{$item->product_name} (Qty: {$item->quantity}) - $" . number_format($item->unit_price, 2) . "</li>";
        }

        $emailContent = "
            <h2>📦 New Order Received</h2>
            <p>Order #: <b>{$order->order_number}</b></p>
            <p>Customer: {$order->user->name} ({$order->user->email})</p>
            <p><b>Order Details:</b></p>
            <ul>{$itemsHtml}</ul>
            <p><b>Total:</b> $" . number_format($order->total, 2) . "</p>
            <p>Status: {$order->status}</p>
            <p>Placed on: {$order->created_at->format('M j, Y g:i A')}</p>
        ";

        try {
            Mail::send([], [], function ($message) use ($admin, $subject, $emailContent) {
                $message->to($admin->email)
                    ->subject($subject)
                    ->html($emailContent);
            });
        } catch (\Exception $e) {
            Log::error("Admin order notification email failed: " . $e->getMessage());
        }
    }

    /** Enhanced Telegram notifications */
    protected function sendOrderTelegramNotifications(Order $order): void
    {
        $user = $order->user;

        // Buyer Telegram message
        $buyerMessage = "🎉 *Order Confirmed!*

        📦 *Order #{$order->order_number}*
        💰 *Total:* \${$order->total}
        📅 *Order Date:* {$order->created_at->format('M j, Y')}
        🚚 *Status:* {$order->status}

        _We'll notify you when your order ships!_";

        $this->maybeSendTelegramForUser($user, $buyerMessage, $order->id);

        // Admin Telegram message (more detailed)
        $adminMessage = "📦 *NEW ORDER*

        🆔 *Order #:* {$order->order_number}
        👤 *Customer:* {$user->name}
        📧 *Email:* {$user->email}
        💰 *Amount:* \${$order->total}
        📦 *Items:* {$order->items->count()} items
        📅 *Order Date:* {$order->created_at->format('M j, Y g:i A')}
        🏷️ *Status:* {$order->status}

        📍 *Shipping Address:*
        {$this->formatAddressForTelegram($order->shippingAddress)}";

        $this->maybeBroadcastTelegramToAdmins($adminMessage, $order->id);
    }

    /** Format address for Telegram */
    protected function formatAddressForTelegram($address): string
    {
        if (!$address) return "Not specified";

        return "
        {$address->address_line_1}
        {$address->address_line_2}
        {$address->city}, {$address->state} {$address->postal_code}
        {$address->country}
        📞 {$address->phone}";
    }

    /** Enhanced payment notifications */
    public function notifyPaymentCreated(Payment $payment, string $currency): void
    {
        $order = $payment->order;
        $user = $order->user;

        $paymentData = [
            'amount' => number_format($payment->amount, 2),
            'currency' => $currency,
            'method' => $payment->payment_method,
            'status' => $payment->status,
            'transaction_id' => $payment->transaction_id,
        ];

        // Buyer notification
        $notification = $user->notifications()->create([
            'type' => 'payment_created',
            'data' => [
                'title' => '💳 Payment Received',
                'message' => "Payment of {$paymentData['amount']} {$currency} for order #{$order->order_number} has been received.",
                'order_id' => $order->id,
                'payment_id' => $payment->id,
                'amount' => $paymentData['amount'],
                'currency' => $currency,
                'method' => $paymentData['method'],
                'status' => $paymentData['status'],
            ],
        ]);

        // Trigger event
        event(new NotificationCreated($notification));

        // Send payment confirmation email
        $this->sendPaymentConfirmationEmail($user, $order, $payment, $currency);

        // Admin notifications
        User::admins()->get()->each(function ($admin) use ($order, $payment, $currency) {
            $notification = $admin->notifications()->create([
                'type' => 'payment_created_admin',
                'data' => [
                    'title' => '💳 Payment Received',
                    'message' => "Payment received for order #{$order->order_number}",
                    'order_id' => $order->id,
                    'payment_id' => $payment->id,
                    'amount' => $payment->amount,
                    'currency' => $currency,
                    'method' => $payment->payment_method,
                    'customer' => $order->user->name,
                ],
            ]);

            // Trigger event
            event(new NotificationCreated($notification));
        });

        // Telegram notifications
        $this->sendPaymentTelegramNotifications($order, $payment, $currency);
    }

    /** Enhanced payment confirmation email */
    protected function sendPaymentConfirmationEmail(User $user, Order $order, Payment $payment, string $currency): void
    {
        $subject = "Payment Confirmation - Order #{$order->order_number}";

        $emailContent = "
            <h2>💳 Payment Confirmed!</h2>
            <p>Hi {$user->name},</p>
            <p>Payment of <b>{$payment->amount} {$currency}</b> for order #{$order->order_number} has been received.</p>
            <p>Payment Method: {$payment->payment_method}</p>
            <p>Status: {$payment->status}</p>
            <p>Transaction ID: {$payment->transaction_id}</p>
            <p>Thank you for your payment!</p>
        ";

        try {
            Mail::send([], [], function ($message) use ($user, $subject, $emailContent) {
                $message->to($user->email)
                    ->subject($subject)
                    ->html($emailContent);
            });
        } catch (\Exception $e) {
            Log::error("Payment confirmation email failed: " . $e->getMessage());
        }
    }

    /** Enhanced payment Telegram notifications */
    protected function sendPaymentTelegramNotifications(Order $order, Payment $payment, string $currency): void
    {
        $user = $order->user;

        $buyerMessage = "💳 *Payment Confirmed!*

        📦 *Order #:* {$order->order_number}
        💰 *Amount:* {$payment->amount} {$currency}
        🏦 *Method:* {$payment->payment_method}
        ✅ *Status:* {$payment->status}

        _Thank you for your payment!_";

        $this->maybeSendTelegramForUser($user, $buyerMessage, $order->id);

        $adminMessage = "💳 *PAYMENT RECEIVED*

        📦 *Order #:* {$order->order_number}
        👤 *Customer:* {$user->name}
        💰 *Amount:* {$payment->amount} {$currency}
        🏦 *Method:* {$payment->payment_method}
        🆔 *Transaction ID:* {$payment->transaction_id}
        ✅ *Status:* {$payment->status}";

        $this->maybeBroadcastTelegramToAdmins($adminMessage, $order->id);
    }

    /** Enhanced stock alert notifications */
    public function notifyStockAlert(Product $product): void
    {
        // Determine alert type and message
        if ($product->stock <= 0) {
            $alertType = 'out_of_stock';
            $title     = '🛑 Out of Stock';
            $message   = "Product '{$product->name}' is out of stock";
        } elseif ($product->stock <= $product->low_stock_threshold) {
            $alertType = 'low_stock';
            $title     = '⚠️ Low Stock';
            $message   = "Product '{$product->name}' has low stock: {$product->stock} remaining";
        } else {
            // No alert, skip
            return;
        }

        // Prepare notification data
        $notificationData = [
            'product_id'    => $product->id,
            'product_name'  => $product->name,
            'current_stock' => $product->stock,
            'threshold'     => $product->low_stock_threshold,
            'alert_type'    => $alertType,
        ];

        // 1) Notify Admins
        $admins = User::admins()->get();
        foreach ($admins as $admin) {
            $notification = $admin->notifications()->create([
                'type' => $alertType,
                'data' => array_merge([
                    'title'   => $title,
                    'message' => $message,
                ], $notificationData),
            ]);

            event(new NotificationCreated($notification));

            // Send stock alert email to admin
            $this->sendStockAlertEmail($admin, $product);

            // Send Telegram notification to admin
            $telegramMessage = $title . "\nProduct: {$product->name}\nCurrent Stock: {$product->stock}\nThreshold: {$product->low_stock_threshold}";
            $this->maybeSendTelegramForUser($admin, $telegramMessage);
        }

        // 2) Notify Vendor (if product has a vendor)
        if ($product->vendor) {
            $vendor = $product->vendor;

            // Database notification for vendor
            $vendorNotification = $vendor->notifications()->create([
                'type' => $alertType . '_vendor',
                'data' => array_merge([
                    'title'   => $title . ' - Your Product',
                    'message' => $message . ". Please restock soon.",
                ], $notificationData),
            ]);

            event(new NotificationCreated($vendorNotification));

            // Send stock alert email to vendor
            $this->sendVendorStockAlertEmail($vendor, $product);

            // Send Telegram notification to vendor
            $vendorTelegramMessage = $title . " - YOUR PRODUCT\nProduct: {$product->name}\nCurrent Stock: {$product->stock}\nThreshold: {$product->low_stock_threshold}\nPlease restock soon.";
            $this->maybeSendTelegramForUser($vendor, $vendorTelegramMessage);
        }
    }

    /** Send stock alert email to vendor */
    protected function sendVendorStockAlertEmail(User $vendor, Product $product): void
    {
        $subject = $product->stock <= 0
            ? "🛑 Out of Stock: {$product->name}"
            : "⚠️ Low Stock Alert: {$product->name}";

        $statusText = $product->stock <= 0
            ? "Your product '{$product->name}' is out of stock"
            : "Your product '{$product->name}' has low stock: {$product->stock} remaining";

        $emailContent = "
            <h2>{$subject}</h2>
            <p>Hello {$vendor->name},</p>
            <p>{$statusText}</p>
            <p><b>Product Details:</b></p>
            <ul>
                <li>Product Name: {$product->name}</li>
                <li>Current Stock: {$product->stock}</li>
                <li>Low Stock Threshold: {$product->low_stock_threshold}</li>
                <li>Product SKU: {$product->sku}</li>
            </ul>
            <p>Please update your inventory soon to avoid sales disruption.</p>
            <p>Thank you,<br>Admin Team</p>
        ";

        try {
            Mail::send([], [], function ($message) use ($vendor, $subject, $emailContent) {
                $message->to($vendor->email)
                    ->subject($subject)
                    ->html($emailContent);
            });
            Log::info("Vendor stock alert email sent to: {$vendor->email}");
        } catch (\Exception $e) {
            Log::error("Vendor stock alert email failed: " . $e->getMessage());
        }
    }

    /** Enhanced stock alert email */
    protected function sendStockAlertEmail(User $admin, Product $product): void
    {
        $subject = $product->stock <= 0
            ? "🛑 Out of Stock: {$product->name}"
            : "⚠️ Low Stock Alert: {$product->name}";

        $statusText = $product->stock <= 0
            ? "Product '{$product->name}' is out of stock"
            : "Product '{$product->name}' has low stock: {$product->stock} remaining";

        $emailContent = "
            <h2>{$subject}</h2>
            <p>{$statusText}</p>
            <p>Current Stock: {$product->stock}</p>
            <p>Low Stock Threshold: {$product->low_stock_threshold}</p>
        ";

        try {
            Mail::send([], [], function ($message) use ($admin, $subject, $emailContent) {
                $message->to($admin->email)
                    ->subject($subject)
                    ->html($emailContent);
            });
        } catch (\Exception $e) {
            Log::error("Stock alert email failed: " . $e->getMessage());
        }
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

    /** Low-level Telegram sender + DB record with logging */
protected function sendTelegram(string $chatId, string $message, ?int $orderId = null): void
{
    $botToken = config('services.telegram.client_secret', env('TELEGRAM_TOKEN'));
    if (!$botToken) {
        Log::warning("Telegram bot token is missing, cannot send message.", [
            'chat_id' => $chatId,
            'message' => $message,
            'order_id' => $orderId,
        ]);
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

    Log::info("Sending Telegram message...", [
        'chat_id' => $chatId,
        'message' => $message,
        'order_id' => $orderId,
    ]);

    try {
        $response = Http::post($url, [
            'chat_id' => $chatId,
            'text'    => $message,
        ]);

        if ($response->successful()) {
            $tn->update([
                'is_sent' => true,
                'sent_at' => now(),
            ]);
            Log::info("Telegram message sent successfully.", [
                'chat_id' => $chatId,
                'message' => $message,
                'order_id' => $orderId,
                'response_status' => $response->status(),
                'response_body' => $response->body(),
            ]);
        } else {
            Log::error("Telegram API failed to send message.", [
                'chat_id' => $chatId,
                'message' => $message,
                'order_id' => $orderId,
                'status'  => $response->status(),
                'body'    => $response->body(),
            ]);
        }
    } catch (\Exception $e) {
        Log::error("Exception while sending Telegram message.", [
            'chat_id' => $chatId,
            'message' => $message,
            'order_id' => $orderId,
            'exception' => $e->getMessage(),
        ]);
    }
}

}
