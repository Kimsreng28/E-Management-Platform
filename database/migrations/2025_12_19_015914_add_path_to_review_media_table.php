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
        Schema::table('review_media', function (Blueprint $table) {
            if (!Schema::hasColumn('review_media', 'path')) {
                $table->string('path')->after('review_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('review_media', function (Blueprint $table) {
            $table->dropColumn('path');
        });
    }
};