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
        if (!Schema::hasTable('devices') || Schema::hasColumn('devices', 'api_key_encrypted')) {
            return;
        }

        Schema::table('devices', function (Blueprint $table) {
            $table->longText('api_key_encrypted')->nullable()->after('api_key_hash');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('devices') || !Schema::hasColumn('devices', 'api_key_encrypted')) {
            return;
        }

        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn('api_key_encrypted');
        });
    }
};
