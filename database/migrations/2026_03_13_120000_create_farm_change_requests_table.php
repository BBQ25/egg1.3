<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('farm_change_requests')) {
            return;
        }

        Schema::create('farm_change_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('farm_id')->nullable();
            $table->unsignedInteger('owner_user_id');
            $table->enum('request_type', ['CLAIM', 'LOCATION_UPDATE']);
            $table->enum('status', ['PENDING', 'APPROVED', 'REJECTED'])->default('PENDING');
            $table->string('farm_name', 120);
            $table->string('location', 160);
            $table->string('sitio', 120);
            $table->string('barangay', 120);
            $table->string('municipality', 120);
            $table->string('province', 120);
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->boolean('inside_general_geofence')->nullable();
            $table->string('admin_notes', 255)->nullable();
            $table->unsignedInteger('reviewed_by_user_id')->nullable();
            $table->timestamp('submitted_at')->useCurrent();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->index(['owner_user_id', 'status'], 'idx_farm_change_owner_status');
            $table->index(['status', 'submitted_at'], 'idx_farm_change_status_submitted');
            $table->index('farm_id', 'idx_farm_change_farm');
            $table->index('reviewed_by_user_id', 'idx_farm_change_reviewer');

            $table->foreign('farm_id', 'fk_farm_change_farm')
                ->references('id')
                ->on('farms')
                ->cascadeOnUpdate()
                ->nullOnDelete();

            $table->foreign('owner_user_id', 'fk_farm_change_owner')
                ->references('id')
                ->on('users')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->foreign('reviewed_by_user_id', 'fk_farm_change_reviewer')
                ->references('id')
                ->on('users')
                ->cascadeOnUpdate()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('farm_change_requests');
    }
};
