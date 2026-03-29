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
        if (!Schema::hasTable('app_settings')) {
            Schema::create('app_settings', function (Blueprint $table) {
                $table->string('setting_key', 100)->primary();
                $table->string('setting_value', 255);
                $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            });
        }

        if (!Schema::hasTable('farms')) {
            Schema::create('farms', function (Blueprint $table) {
                $table->increments('id');
                $table->string('farm_name', 120);
                $table->string('location', 160)->nullable();
                $table->string('sitio', 120)->nullable();
                $table->string('barangay', 120)->nullable();
                $table->string('municipality', 120)->nullable();
                $table->string('province', 120)->nullable();
                $table->decimal('latitude', 10, 7)->nullable();
                $table->decimal('longitude', 10, 7)->nullable();
                $table->unsignedInteger('owner_user_id')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamp('created_at')->useCurrent();
                $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

                $table->index('owner_user_id', 'idx_owner');
                $table->foreign('owner_user_id', 'fk_farms_owner')
                    ->references('id')
                    ->on('users')
                    ->cascadeOnUpdate()
                    ->nullOnDelete();
            });
        }

        if (!Schema::hasTable('egg_items')) {
            Schema::create('egg_items', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('farm_id');
                $table->string('item_code', 40);
                $table->string('egg_type', 80);
                $table->enum('size_class', ['Reject', 'Peewee', 'Pullet', 'Small', 'Medium', 'Large', 'Extra-Large', 'Jumbo']);
                $table->decimal('unit_cost', 10, 2)->default(0);
                $table->decimal('selling_price', 10, 2)->default(0);
                $table->integer('reorder_level')->default(50);
                $table->integer('current_stock')->default(0);
                $table->timestamp('created_at')->useCurrent();
                $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

                $table->unique(['farm_id', 'item_code'], 'idx_farm_item_code');
                $table->index(['egg_type', 'size_class'], 'idx_egg_type_size');
                $table->index(['current_stock', 'reorder_level'], 'idx_stock_levels');
                $table->index('farm_id', 'idx_egg_items_farm');
                $table->index('item_code', 'idx_item_code_lookup');
                $table->foreign('farm_id', 'fk_egg_items_farm')
                    ->references('id')
                    ->on('farms')
                    ->cascadeOnUpdate()
                    ->cascadeOnDelete();
            });
        }

        if (!Schema::hasTable('stock_movements')) {
            Schema::create('stock_movements', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('item_id');
                $table->enum('movement_type', ['IN', 'OUT', 'ADJUSTMENT']);
                $table->integer('quantity');
                $table->integer('stock_before');
                $table->integer('stock_after');
                $table->decimal('unit_cost', 10, 2)->default(0);
                $table->string('reference_no', 80);
                $table->string('notes', 255)->nullable();
                $table->date('movement_date');
                $table->timestamp('created_at')->useCurrent();

                $table->index('movement_date', 'idx_movement_date');
                $table->index(['item_id', 'movement_date'], 'idx_item_date');
                $table->index(['movement_type', 'movement_date'], 'idx_type_date');
                $table->foreign('item_id', 'fk_stock_item')
                    ->references('id')
                    ->on('egg_items')
                    ->cascadeOnUpdate()
                    ->cascadeOnDelete();
            });
        }

        if (!Schema::hasTable('egg_intake_records')) {
            Schema::create('egg_intake_records', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('farm_id');
                $table->unsignedInteger('item_id');
                $table->unsignedBigInteger('movement_id');
                $table->enum('source', ['MANUAL', 'ESP32']);
                $table->string('egg_type', 80);
                $table->string('size_class', 20);
                $table->decimal('weight_grams', 8, 2);
                $table->integer('quantity');
                $table->integer('stock_before');
                $table->integer('stock_after');
                $table->string('reference_no', 80);
                $table->string('notes', 255)->nullable();
                $table->text('payload_json')->nullable();
                $table->unsignedInteger('created_by_user_id')->nullable();
                $table->timestamp('recorded_at')->useCurrent();
                $table->timestamp('created_at')->useCurrent();

                $table->index(['farm_id', 'recorded_at'], 'idx_intake_farm_date');
                $table->index(['source', 'recorded_at'], 'idx_intake_source_date');
                $table->index('item_id', 'fk_intake_item');
                $table->index('movement_id', 'fk_intake_movement');
                $table->index('created_by_user_id', 'fk_intake_created_by');
                $table->foreign('farm_id', 'fk_intake_farm')
                    ->references('id')
                    ->on('farms')
                    ->cascadeOnUpdate()
                    ->cascadeOnDelete();
                $table->foreign('item_id', 'fk_intake_item')
                    ->references('id')
                    ->on('egg_items')
                    ->cascadeOnUpdate()
                    ->cascadeOnDelete();
                $table->foreign('movement_id', 'fk_intake_movement')
                    ->references('id')
                    ->on('stock_movements')
                    ->cascadeOnUpdate()
                    ->cascadeOnDelete();
                $table->foreign('created_by_user_id', 'fk_intake_created_by')
                    ->references('id')
                    ->on('users')
                    ->cascadeOnUpdate()
                    ->nullOnDelete();
            });
        }

        if (!Schema::hasTable('farm_staff_assignments')) {
            Schema::create('farm_staff_assignments', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('farm_id');
                $table->unsignedInteger('user_id');
                $table->timestamp('created_at')->useCurrent();

                $table->unique(['farm_id', 'user_id'], 'uq_farm_user');
                $table->index('user_id', 'idx_staff_user');
                $table->foreign('farm_id', 'fk_staff_farm')
                    ->references('id')
                    ->on('farms')
                    ->cascadeOnUpdate()
                    ->cascadeOnDelete();
                $table->foreign('user_id', 'fk_staff_user')
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
        Schema::dropIfExists('farm_staff_assignments');
        Schema::dropIfExists('egg_intake_records');
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('egg_items');
        Schema::dropIfExists('farms');
        Schema::dropIfExists('app_settings');
    }
};
