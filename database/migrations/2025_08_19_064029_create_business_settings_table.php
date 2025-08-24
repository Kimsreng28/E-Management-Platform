<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('business_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('business_name');
            $table->string('tax_id')->nullable();
            $table->string('currency')->default('USD');
            $table->decimal('tax_rate', 5, 2)->default(10.00);
            $table->string('invoice_prefix')->default('INV-');
            $table->integer('invoice_starting_number')->default(1001);
            $table->boolean('inventory_management')->default(true);
            $table->integer('low_stock_threshold')->default(5);
            $table->json('business_hours')->nullable();

            // Payment & Integration Settings
            $table->boolean('stripe_enabled')->default(false);
            $table->string('stripe_public_key')->nullable();
            $table->string('stripe_secret_key')->nullable();
            $table->string('stripe_webhook_secret')->nullable();

            $table->boolean('khqr_enabled')->default(false);
            $table->string('khqr_merchant_name')->nullable();
            $table->string('khqr_merchant_account')->nullable();

            $table->boolean('paypal_enabled')->default(false);
            $table->string('paypal_client_id')->nullable();
            $table->string('paypal_client_secret')->nullable();
            $table->boolean('paypal_sandbox')->default(true);

            $table->timestamps();

            $table->unique(['user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('business_settings');
    }
};
