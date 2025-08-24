<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BusinessSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'business_name',
        'tax_id',
        'currency',
        'tax_rate',
        'invoice_prefix',
        'invoice_starting_number',
        'inventory_management',
        'low_stock_threshold',
        'business_hours',
        'stripe_enabled',
        'stripe_public_key',
        'stripe_secret_key',
        'stripe_webhook_secret',
        'khqr_enabled',
        'khqr_merchant_name',
        'khqr_merchant_account',
        'paypal_enabled',
        'paypal_client_id',
        'paypal_client_secret',
        'paypal_sandbox',
    ];

    protected $casts = [
        'business_hours' => 'array',
        'inventory_management' => 'boolean',
        'stripe_enabled' => 'boolean',
        'khqr_enabled' => 'boolean',
        'paypal_enabled' => 'boolean',
        'paypal_sandbox' => 'boolean',
        'tax_rate' => 'float',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
