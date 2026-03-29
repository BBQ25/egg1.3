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
        if (Schema::hasTable('farm_premises_zones')) {
            return;
        }

        Schema::create('farm_premises_zones', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('farm_id');
            $table->enum('shape_type', ['CIRCLE', 'RECTANGLE', 'SQUARE', 'POLYGON']);
            $table->decimal('center_latitude', 10, 7)->nullable();
            $table->decimal('center_longitude', 10, 7)->nullable();
            $table->unsignedInteger('radius_meters')->nullable();
            $table->decimal('bounds_north', 10, 7)->nullable();
            $table->decimal('bounds_south', 10, 7)->nullable();
            $table->decimal('bounds_east', 10, 7)->nullable();
            $table->decimal('bounds_west', 10, 7)->nullable();
            $table->longText('vertices_json')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('created_by_user_id')->nullable();
            $table->unsignedInteger('updated_by_user_id')->nullable();
            $table->timestamps();

            $table->unique('farm_id', 'uq_farm_premises_farm');
            $table->index(['is_active', 'shape_type'], 'idx_farm_premises_active_shape');
            $table->index('created_by_user_id', 'idx_farm_premises_created_by');
            $table->index('updated_by_user_id', 'idx_farm_premises_updated_by');

            $table->foreign('farm_id', 'fk_farm_premises_farm')
                ->references('id')
                ->on('farms')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->foreign('created_by_user_id', 'fk_farm_premises_created_by')
                ->references('id')
                ->on('users')
                ->cascadeOnUpdate()
                ->nullOnDelete();

            $table->foreign('updated_by_user_id', 'fk_farm_premises_updated_by')
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
        if (!Schema::hasTable('farm_premises_zones')) {
            return;
        }

        Schema::table('farm_premises_zones', function (Blueprint $table) {
            $table->dropForeign('fk_farm_premises_farm');
            $table->dropForeign('fk_farm_premises_created_by');
            $table->dropForeign('fk_farm_premises_updated_by');
            $table->dropUnique('uq_farm_premises_farm');
            $table->dropIndex('idx_farm_premises_active_shape');
            $table->dropIndex('idx_farm_premises_created_by');
            $table->dropIndex('idx_farm_premises_updated_by');
        });

        Schema::dropIfExists('farm_premises_zones');
    }
};

