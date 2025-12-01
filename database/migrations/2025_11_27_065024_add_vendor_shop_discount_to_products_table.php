<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
{
    Schema::table('products', function (Blueprint $table) {
        // Add vendor and shop references
        $table->foreignId('vendor_id')->nullable()->constrained('users')->onDelete('cascade');
        $table->foreignId('shop_id')->nullable()->constrained('shops')->onDelete('cascade');

        // Add discount period columns
        $table->timestamp('discount_start_at')->nullable();
        $table->timestamp('discount_end_at')->nullable();
    });
}


    /**
     * Reverse the migrations.
     */
    public function down()
{
    Schema::table('products', function (Blueprint $table) {
        // Drop foreign keys
        $table->dropForeign(['vendor_id']);
        $table->dropForeign(['shop_id']);

        // Drop columns
        $table->dropColumn([
            'vendor_id',
            'shop_id',
            'discount_start_at',
            'discount_end_at'
        ]);
    });
}

};
