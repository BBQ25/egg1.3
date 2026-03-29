<?php

use App\Support\EggSizeClass;
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
        if (!Schema::hasTable('devices')) {
            Schema::create('devices', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('owner_user_id');
                $table->unsignedInteger('farm_id');
                $table->string('module_board_name', 120);
                $table->string('primary_serial_no', 120)->unique();
                $table->text('main_technical_specs')->nullable();
                $table->text('processing_memory')->nullable();
                $table->text('gpio_interfaces')->nullable();
                $table->string('api_key_hash');
                $table->boolean('is_active')->default(true);
                $table->timestamp('last_seen_at')->nullable();
                $table->string('last_seen_ip', 45)->nullable();
                $table->timestamp('deactivated_at')->nullable();
                $table->unsignedInteger('created_by_user_id')->nullable();
                $table->unsignedInteger('updated_by_user_id')->nullable();
                $table->timestamps();

                $table->index(['owner_user_id', 'farm_id'], 'idx_devices_owner_farm');
                $table->index(['is_active', 'last_seen_at'], 'idx_devices_status_seen');

                $table->foreign('owner_user_id', 'fk_devices_owner')
                    ->references('id')
                    ->on('users')
                    ->cascadeOnUpdate()
                    ->cascadeOnDelete();

                $table->foreign('farm_id', 'fk_devices_farm')
                    ->references('id')
                    ->on('farms')
                    ->cascadeOnUpdate()
                    ->cascadeOnDelete();

                $table->foreign('created_by_user_id', 'fk_devices_created_by')
                    ->references('id')
                    ->on('users')
                    ->cascadeOnUpdate()
                    ->nullOnDelete();

                $table->foreign('updated_by_user_id', 'fk_devices_updated_by')
                    ->references('id')
                    ->on('users')
                    ->cascadeOnUpdate()
                    ->nullOnDelete();
            });
        }

        if (!Schema::hasTable('device_serial_aliases')) {
            Schema::create('device_serial_aliases', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('device_id');
                $table->string('serial_no', 120)->unique();
                $table->timestamp('created_at')->useCurrent();

                $table->index('device_id', 'idx_device_aliases_device');
                $table->foreign('device_id', 'fk_device_aliases_device')
                    ->references('id')
                    ->on('devices')
                    ->cascadeOnUpdate()
                    ->cascadeOnDelete();
            });
        }

        if (!Schema::hasTable('device_ingest_events')) {
            Schema::create('device_ingest_events', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('device_id');
                $table->unsignedInteger('farm_id');
                $table->unsignedInteger('owner_user_id');
                $table->string('egg_uid', 80)->nullable();
                $table->string('batch_code', 80)->nullable();
                $table->decimal('weight_grams', 8, 2);
                $table->enum('size_class', EggSizeClass::values());
                $table->timestamp('recorded_at');
                $table->string('source_ip', 45)->nullable();
                $table->longText('raw_payload_json')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index(['device_id', 'recorded_at'], 'idx_ingest_device_date');
                $table->index(['farm_id', 'recorded_at'], 'idx_ingest_farm_date');
                $table->index(['owner_user_id', 'recorded_at'], 'idx_ingest_owner_date');
                $table->index(['size_class', 'recorded_at'], 'idx_ingest_size_date');

                $table->foreign('device_id', 'fk_ingest_device')
                    ->references('id')
                    ->on('devices')
                    ->cascadeOnUpdate()
                    ->cascadeOnDelete();

                $table->foreign('farm_id', 'fk_ingest_farm')
                    ->references('id')
                    ->on('farms')
                    ->cascadeOnUpdate()
                    ->cascadeOnDelete();

                $table->foreign('owner_user_id', 'fk_ingest_owner')
                    ->references('id')
                    ->on('users')
                    ->cascadeOnUpdate()
                    ->cascadeOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('device_ingest_events');
        Schema::dropIfExists('device_serial_aliases');
        Schema::dropIfExists('devices');
    }
};
