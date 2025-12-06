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
        Schema::table('deliveries', function (Blueprint $table) {
            $table->decimal('agent_rating', 2, 1)->nullable()->after('delivery_options');
            $table->text('agent_rating_comment')->nullable()->after('agent_rating');
            $table->timestamp('agent_rated_at')->nullable()->after('agent_rating_comment');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            $table->dropColumn(['agent_rating', 'agent_rating_comment', 'agent_rated_at']);
        });
    }
};