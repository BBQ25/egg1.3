<?php

namespace App\Services;

use App\Models\Device;
use App\Models\ProductionBatch;
use App\Support\BatchCodeFormatter;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class AutomaticBatchLifecycleService
{
    public const INACTIVITY_MINUTES = 15;

    public function currentOpenBatch(Device $device, ?CarbonInterface $observedAt = null): ?ProductionBatch
    {
        $observedAt = $observedAt
            ? CarbonImmutable::instance($observedAt)
            : CarbonImmutable::now();

        $this->closeStaleOpenBatches($device, $observedAt);

        return $this->latestOpenBatch($device);
    }

    public function resolveForIngest(Device $device, ?string $batchCode, CarbonInterface $recordedAt): ?ProductionBatch
    {
        $recordedAt = CarbonImmutable::instance($recordedAt);
        $batchCode = $this->normalizeBatchCode($batchCode);

        $this->closeStaleOpenBatches($device, $recordedAt);

        if ($batchCode !== null) {
            return $this->resolveNamedBatch($device, $batchCode, $recordedAt);
        }

        return $this->resolveAutomaticBatch($device, $recordedAt);
    }

    private function resolveAutomaticBatch(Device $device, CarbonImmutable $recordedAt): ProductionBatch
    {
        $openBatch = $this->latestOpenBatch($device);

        if ($openBatch !== null) {
            return $this->touchBatchWindow($openBatch, $recordedAt);
        }

        return $this->createBatch(
            $device,
            $this->generateAutomaticBatchCode($device, $recordedAt),
            $recordedAt
        );
    }

    private function resolveNamedBatch(Device $device, string $batchCode, CarbonImmutable $recordedAt): ProductionBatch
    {
        $openBatch = $this->latestOpenBatch($device);

        if ($openBatch !== null && $openBatch->batch_code !== $batchCode) {
            $this->closeBatchFromLastActivity($openBatch);
            $openBatch = null;
        }

        if ($openBatch !== null && $openBatch->batch_code === $batchCode) {
            return $this->touchBatchWindow($openBatch, $recordedAt);
        }

        $existingBatch = ProductionBatch::query()
            ->where('device_id', (int) $device->id)
            ->where('farm_id', (int) $device->farm_id)
            ->where('batch_code', $batchCode)
            ->orderByDesc('id')
            ->first();

        if ($existingBatch !== null) {
            if ((string) $existingBatch->status === 'closed' && $this->isAutomaticBatchCode($batchCode)) {
                return $this->createBatch(
                    $device,
                    $this->generateAutomaticBatchCode($device, $recordedAt),
                    $recordedAt
                );
            }

            return $this->touchBatchWindow($existingBatch, $recordedAt, reopen: true);
        }

        return $this->createBatch($device, $batchCode, $recordedAt);
    }

    private function closeStaleOpenBatches(Device $device, CarbonImmutable $observedAt): void
    {
        $openBatches = ProductionBatch::query()
            ->where('device_id', (int) $device->id)
            ->where('farm_id', (int) $device->farm_id)
            ->where('status', 'open')
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->get();

        foreach ($openBatches as $batch) {
            if ($this->isBatchStale($batch, $observedAt)) {
                $this->closeBatchFromLastActivity($batch);
            }
        }
    }

    private function isBatchStale(ProductionBatch $batch, CarbonImmutable $observedAt): bool
    {
        $lastActivityAt = $this->lastActivityAt($batch);

        return $lastActivityAt
            ->addMinutes(self::INACTIVITY_MINUTES)
            ->lte($observedAt);
    }

    private function closeBatchFromLastActivity(ProductionBatch $batch): void
    {
        $endedAt = $this->lastActivityAt($batch);

        $batch->update([
            'status' => 'closed',
            'ended_at' => $endedAt,
        ]);
    }

    private function touchBatchWindow(ProductionBatch $batch, CarbonImmutable $recordedAt, bool $reopen = false): ProductionBatch
    {
        $updates = [];

        if ($reopen && $batch->status !== 'open') {
            $updates['status'] = 'open';
        }

        if ($batch->started_at === null || $recordedAt->lt(CarbonImmutable::instance($batch->started_at))) {
            $updates['started_at'] = $recordedAt;
        }

        if ($batch->ended_at === null || $recordedAt->gt(CarbonImmutable::instance($batch->ended_at))) {
            $updates['ended_at'] = $recordedAt;
        }

        if ($updates !== []) {
            $batch->fill($updates)->save();
        }

        return $batch->refresh();
    }

    private function createBatch(Device $device, string $batchCode, CarbonImmutable $recordedAt): ProductionBatch
    {
        return ProductionBatch::query()->create([
            'device_id' => (int) $device->id,
            'farm_id' => (int) $device->farm_id,
            'owner_user_id' => (int) $device->owner_user_id,
            'batch_code' => $batchCode,
            'status' => 'open',
            'started_at' => $recordedAt,
            'ended_at' => $recordedAt,
        ]);
    }

    private function latestOpenBatch(Device $device): ?ProductionBatch
    {
        return ProductionBatch::query()
            ->where('device_id', (int) $device->id)
            ->where('farm_id', (int) $device->farm_id)
            ->where('status', 'open')
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->first();
    }

    private function lastActivityAt(ProductionBatch $batch): CarbonImmutable
    {
        $latestRecordedAt = $batch->ingestEvents()->max('recorded_at');

        if ($latestRecordedAt !== null) {
            return CarbonImmutable::parse((string) $latestRecordedAt);
        }

        if ($batch->ended_at !== null) {
            return CarbonImmutable::instance($batch->ended_at);
        }

        return CarbonImmutable::instance($batch->started_at ?? now());
    }

    private function generateAutomaticBatchCode(Device $device, CarbonImmutable $recordedAt): string
    {
        $device->loadMissing('farm');

        $candidate = BatchCodeFormatter::build($device->farm?->farm_name, $recordedAt);
        $suffix = 1;

        while ($this->batchCodeExists($device, $candidate)) {
            $suffix++;
            $candidate = BatchCodeFormatter::build($device->farm?->farm_name, $recordedAt, $suffix);
        }

        return $candidate;
    }

    private function batchCodeExists(Device $device, string $batchCode): bool
    {
        return ProductionBatch::query()
            ->where('device_id', (int) $device->id)
            ->where('farm_id', (int) $device->farm_id)
            ->where('batch_code', $batchCode)
            ->exists();
    }

    private function isAutomaticBatchCode(string $batchCode): bool
    {
        return BatchCodeFormatter::matchesGeneratedPattern($batchCode);
    }

    private function normalizeBatchCode(?string $batchCode): ?string
    {
        $batchCode = trim((string) $batchCode);

        return $batchCode === '' ? null : $batchCode;
    }
}
