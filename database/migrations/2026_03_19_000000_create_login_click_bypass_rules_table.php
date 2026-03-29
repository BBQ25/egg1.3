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
        if (Schema::hasTable('login_click_bypass_rules')) {
            return;
        }

        Schema::create('login_click_bypass_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('rule_label', 120)->default('');
            $table->unsignedInteger('click_count');
            $table->unsignedInteger('window_seconds');
            $table->foreignId('target_user_id')->constrained('users')->cascadeOnDelete();
            $table->boolean('is_enabled')->default(true);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['click_count', 'window_seconds'], 'login_click_bypass_rule_pattern');
            $table->index(['is_enabled', 'target_user_id'], 'login_click_bypass_enabled_target');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('login_click_bypass_rules');
    }
};
