<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <title>Invoice {{ $order->order_number }}</title>

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Hanuman:wght@400;700&family=Inria+Sans:ital,wght@0,300;0,400;0,600;0,700;1,400&display=swap"
        rel="stylesheet">

    <style>
        body {
            font-family: "Inria Sans", Arial, sans-serif;
            margin: 0;
            background: #f9fafb;
            color: #111827;
            font-size: 14px;
        }

        .invoice-box {
            max-width: 900px;
            margin: 40px auto;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0px 4px 20px rgba(0, 0, 0, 0.08);
            padding: 40px 50px;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid #e5e7eb;
        }

        .company-logo {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .logo {
            width: 70px;
            height: 70px;
            object-fit: contain;
            border-radius: 8px;
        }

        .company-info h1 {
            font-family: "Hanuman", serif;
            font-size: 32px;
            font-weight: 700;
            color: #2563eb;
            margin: 0 0 5px 0;
        }

        .company-info p {
            margin: 2px 0;
            font-size: 13px;
            color: #6b7280;
        }

        .invoice-info {
            text-align: right;
        }

        .invoice-info h2 {
            font-family: "Hanuman", serif;
            font-size: 24px;
            font-weight: 700;
            color: #111827;
            margin: 0 0 10px 0;
        }

        .invoice-info p {
            margin: 3px 0;
            font-size: 13px;
            color: #374151;
        }

        .top-info {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 40px;
        }

        .top-info div {
            font-size: 14px;
            line-height: 1.6;
        }

        .section-title {
            font-size: 16px;
            font-weight: 700;
            margin-bottom: 6px;
            color: #374151;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 30px;
        }

        table th {
            text-align: left;
            padding: 12px;
            background: #f3f4f6;
            font-weight: 600;
            font-size: 14px;
            border-bottom: 1px solid #e5e7eb;
        }

        table td {
            padding: 12px;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: top;
        }

        .item-name {
            font-weight: 500;
            color: #111827;
        }

        .item-price {
            text-align: right;
            font-weight: 500;
        }

        .summary {
            width: 40%;
            float: right;
            margin-top: 20px;
        }

        .summary td {
            padding: 8px;
        }

        .summary tr td:first-child {
            font-weight: 500;
            color: #374151;
        }

        .summary tr.total td {
            font-size: 16px;
            font-weight: 700;
            color: #111827;
            border-top: 2px solid #e5e7eb;
        }

        .footer {
            margin-top: 60px;
            text-align: center;
            font-size: 12px;
            color: #6b7280;
            padding-top: 20px;
            border-top: 1px solid #e5e7eb;
        }

        .contact-info {
            margin-top: 8px;
            font-size: 11px;
            color: #9ca3af;
        }
    </style>
</head>

<body>
    <div class="invoice-box">
        <!-- Header with Logo -->
        <div class="header">
            <div class="company-logo">
                <img src="{{ public_path('images/logo.png') }}" alt="Company Logo" class="logo">
                <div class="company-info">
                    <h1>EMP Inc.</h1>
                    <p>123 Main Street, Phnom Penh</p>
                    <p>Email: info@emp-platform.com | Phone: +123 456 789</p>
                </div>
            </div>
            <div class="invoice-info">
                <h2>INVOICE</h2>
                <p>Invoice #: <strong>{{ $order->order_number }}</strong></p>
                <p>Date: {{ $order->created_at->format('M d, Y') }}</p>
                <p>Status: <strong>{{ ucfirst($order->status) }}</strong></p>
            </div>
        </div>

        <!-- Billing and Shipping Information -->
        <div class="top-info">
            <div>
                <p class="section-title">Billed To</p>
                <p>{{ $order->user->name }}<br>
                    {{ $order->billingAddress->address_line_1 }}<br>
                    @if ($order->billingAddress->address_line_2)
                        {{ $order->billingAddress->address_line_2 }}<br>
                    @endif
                    {{ $order->billingAddress->city }}, {{ $order->billingAddress->state }}
                    {{ $order->billingAddress->postal_code }}<br>
                    {{ $order->billingAddress->country }}
                </p>
            </div>
            <div>
                <p class="section-title">Shipped To</p>
                <p>{{ $order->shippingAddress->recipient_name }}<br>
                    {{ $order->shippingAddress->address_line_1 }}<br>
                    @if ($order->shippingAddress->address_line_2)
                        {{ $order->shippingAddress->address_line_2 }}<br>
                    @endif
                    {{ $order->shippingAddress->city }}, {{ $order->shippingAddress->state }}
                    {{ $order->shippingAddress->postal_code }}<br>
                    {{ $order->shippingAddress->country }}
                </p>
            </div>
        </div>

        <!-- Order Items -->
        <table>
            <thead>
                <tr>
                    <th style="width:70%">Item</th>
                    <th style="width:30%; text-align:right">Price</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($order->items as $item)
                    <tr>
                        <td class="item-name">{{ $item->product_name }} (x{{ $item->quantity }})</td>
                        <td class="item-price">${{ number_format($item->total_price, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <!-- Order Summary -->
        <table class="summary">
            <tr>
                <td>Subtotal:</td>
                <td style="text-align:right">${{ number_format($order->subtotal, 2) }}</td>
            </tr>
            @if ($order->discount_amount > 0)
                <tr>
                    <td>Discount:</td>
                    <td style="text-align:right">- ${{ number_format($order->discount_amount, 2) }}</td>
                </tr>
            @endif
            <tr>
                <td>Tax:</td>
                <td style="text-align:right">${{ number_format($order->tax_amount, 2) }}</td>
            </tr>
            <tr>
                <td>Shipping:</td>
                <td style="text-align:right">${{ number_format($order->shipping_cost, 2) }}</td>
            </tr>
            <tr class="total">
                <td>Total:</td>
                <td style="text-align:right">${{ number_format($order->total, 2) }}</td>
            </tr>
        </table>

        <!-- Footer -->
        <div class="footer">
            Thank you for your purchase!
            <br>For support, contact us at <strong>support@example.com</strong>
            <div class="contact-info">
                © {{ date('Y') }} EMP Inc. All rights reserved.
            </div>
        </div>
    </div>
</body>

</html>
