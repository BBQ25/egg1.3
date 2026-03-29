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
        Schema::table('users', function (Blueprint $table) {
            $table->string('first_name', 60)->nullable()->after('full_name');
            $table->string('middle_name', 60)->nullable()->after('first_name');
            $table->string('last_name', 60)->nullable()->after('middle_name');
            $table->string('address', 255)->nullable()->after('last_name');

            $table->enum('registration_status', ['PENDING', 'APPROVED', 'DENIED'])
                ->default('APPROVED')
                ->after('is_active');

            $table->unsignedInteger('approved_by_user_id')->nullable()->after('registration_status');
            $table->timestamp('approved_at')->nullable()->after('approved_by_user_id');
            $table->unsignedInteger('denied_by_user_id')->nullable()->after('approved_at');
            $table->timestamp('denied_at')->nullable()->after('denied_by_user_id');
            $table->string('denial_reason', 500)->nullable()->after('denied_at');

            $table->index('registration_status', 'idx_users_registration_status');
            $table->index('approved_by_user_id', 'idx_users_approved_by');
            $table->index('denied_by_user_id', 'idx_users_denied_by');

            $table->foreign('approved_by_user_id', 'fk_users_approved_by')
                ->references('id')
                ->on('users')
                ->cascadeOnUpdate()
                ->nullOnDelete();

            $table->foreign('denied_by_user_id', 'fk_users_denied_by')
                ->references('id')
                ->on('users')
                ->cascadeOnUpdate()
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign('fk_users_approved_by');
            $table->dropForeign('fk_users_denied_by');
            $table->dropIndex('idx_users_registration_status');
            $table->dropIndex('idx_users_approved_by');
            $table->dropIndex('idx_users_denied_by');

            $table->dropColumn([
                'first_name',
                'middle_name',
                'last_name',
                'address',
                'registration_status',
                'approved_by_user_id',
                'approved_at',
                'denied_by_user_id',
                'denied_at',
                'denial_reason',
            ]);
        });
    }
};

