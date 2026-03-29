<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('evaluation_runs')) {
            Schema::create('evaluation_runs', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('farm_id');
                $table->unsignedBigInteger('device_id');
                $table->unsignedInteger('owner_user_id');
                $table->unsignedInteger('performed_by_user_id')->nullable();
                $table->string('run_code', 80);
                $table->string('title', 150);
                $table->string('status', 20)->default('in_progress');
                $table->unsignedInteger('sample_size_target')->nullable();
                $table->timestamp('started_at');
                $table->timestamp('ended_at')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index(['farm_id', 'started_at'], 'idx_evaluation_runs_farm_started');
                $table->index(['device_id', 'status'], 'idx_evaluation_runs_device_status');
                $table->unique(['device_id', 'run_code'], 'uq_evaluation_runs_device_code');

                $table->foreign('farm_id', 'fk_evaluation_runs_farm')
                    ->references('id')
                    ->on('farms')
                    ->cascadeOnUpdate()
                    ->cascadeOnDelete();

                $table->foreign('device_id', 'fk_evaluation_runs_device')
                    ->references('id')
                    ->on('devices')
                    ->cascadeOnUpdate()
                    ->cascadeOnDelete();

                $table->foreign('owner_user_id', 'fk_evaluation_runs_owner')
                    ->references('id')
                    ->on('users')
                    ->cascadeOnUpdate()
                    ->cascadeOnDelete();

                $table->foreign('performed_by_user_id', 'fk_evaluation_runs_performer')
                    ->references('id')
                    ->on('users')
                    ->cascadeOnUpdate()
                    ->nullOnDelete();
            });
        }

        if (!Schema::hasTable('evaluation_run_measurements')) {
            Schema::create('evaluation_run_measurements', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('evaluation_run_id');
                $table->unsignedBigInteger('device_ingest_event_id')->nullable();
                $table->string('egg_uid', 80)->nullable();
                $table->string('batch_code', 80)->nullable();
                $table->decimal('reference_weight_grams', 8, 2);
                $table->decimal('automated_weight_grams', 8, 2)->nullable();
                $table->string('manual_size_class', 20);
                $table->string('automated_size_class', 20)->nullable();
                $table->decimal('weight_error_grams', 8, 2)->nullable();
                $table->decimal('absolute_error_grams', 8, 2)->nullable();
                $table->boolean('class_match')->default(false);
                $table->timestamp('measured_at');
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index(['evaluation_run_id', 'measured_at'], 'idx_eval_measurements_run_measured');
                $table->index(['device_ingest_event_id'], 'idx_eval_measurements_event');

                $table->foreign('evaluation_run_id', 'fk_eval_measurements_run')
                    ->references('id')
                    ->on('evaluation_runs')
                    ->cascadeOnUpdate()
                    ->cascadeOnDelete();

                $table->foreign('device_ingest_event_id', 'fk_eval_measurements_event')
                    ->references('id')
                    ->on('device_ingest_events')
                    ->cascadeOnUpdate()
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('evaluation_run_measurements');
        Schema::dropIfExists('evaluation_runs');
    }
};
