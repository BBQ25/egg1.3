<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('production_batches')) {
            Schema::create('production_batches', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('device_id');
                $table->unsignedInteger('farm_id');
                $table->unsignedInteger('owner_user_id');
                $table->string('batch_code', 80);
                $table->string('status', 20)->default('open');
                $table->timestamp('started_at');
                $table->timestamp('ended_at')->nullable();
                $table->timestamps();

                $table->index(['device_id', 'batch_code'], 'idx_production_batches_device_code');
                $table->index(['farm_id', 'started_at'], 'idx_production_batches_farm_started');
                $table->index(['status', 'ended_at'], 'idx_production_batches_status_ended');

                $table->foreign('device_id', 'fk_production_batches_device')
                    ->references('id')
                    ->on('devices')
                    ->cascadeOnUpdate()
                    ->cascadeOnDelete();

                $table->foreign('farm_id', 'fk_production_batches_farm')
                    ->references('id')
                    ->on('farms')
                    ->cascadeOnUpdate()
                    ->cascadeOnDelete();

                $table->foreign('owner_user_id', 'fk_production_batches_owner')
                    ->references('id')
                    ->on('users')
                    ->cascadeOnUpdate()
                    ->cascadeOnDelete();
            });
        }

        if (Schema::hasTable('device_ingest_events') && !Schema::hasColumn('device_ingest_events', 'production_batch_id')) {
            Schema::table('device_ingest_events', function (Blueprint $table) {
                $table->unsignedBigInteger('production_batch_id')->nullable()->after('owner_user_id');
                $table->index(['production_batch_id', 'recorded_at'], 'idx_ingest_batch_date');
            });
        }

        $this->backfillProductionBatches();
    }

    public function down(): void
    {
        if (Schema::hasTable('device_ingest_events') && Schema::hasColumn('device_ingest_events', 'production_batch_id')) {
            Schema::table('device_ingest_events', function (Blueprint $table) {
                $table->dropIndex('idx_ingest_batch_date');
                $table->dropColumn('production_batch_id');
            });
        }

        Schema::dropIfExists('production_batches');
    }

    private function backfillProductionBatches(): void
    {
        if (!Schema::hasTable('device_ingest_events') || !Schema::hasTable('production_batches')) {
            return;
        }

        $groups = DB::table('device_ingest_events')
            ->select('device_id', 'farm_id', 'owner_user_id', 'batch_code')
            ->selectRaw('MIN(recorded_at) AS started_at')
            ->selectRaw('MAX(recorded_at) AS ended_at')
            ->whereNotNull('batch_code')
            ->where('batch_code', '<>', '')
            ->groupBy('device_id', 'farm_id', 'owner_user_id', 'batch_code')
            ->get();

        foreach ($groups as $group) {
            $existingId = DB::table('production_batches')
                ->where('device_id', $group->device_id)
                ->where('farm_id', $group->farm_id)
                ->where('owner_user_id', $group->owner_user_id)
                ->where('batch_code', $group->batch_code)
                ->value('id');

            if ($existingId === null) {
                $existingId = DB::table('production_batches')->insertGetId([
                    'device_id' => $group->device_id,
                    'farm_id' => $group->farm_id,
                    'owner_user_id' => $group->owner_user_id,
                    'batch_code' => $group->batch_code,
                    'status' => 'open',
                    'started_at' => $group->started_at,
                    'ended_at' => $group->ended_at,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('device_ingest_events')
                ->where('device_id', $group->device_id)
                ->where('farm_id', $group->farm_id)
                ->where('owner_user_id', $group->owner_user_id)
                ->where('batch_code', $group->batch_code)
                ->whereNull('production_batch_id')
                ->update([
                    'production_batch_id' => $existingId,
                ]);
        }
    }
};
