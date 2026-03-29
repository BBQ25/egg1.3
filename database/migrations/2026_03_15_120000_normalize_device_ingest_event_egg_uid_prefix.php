<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('device_ingest_events') || !Schema::hasColumn('device_ingest_events', 'egg_uid')) {
            return;
        }

        DB::table('device_ingest_events')
            ->select(['id', 'egg_uid'])
            ->whereNotNull('egg_uid')
            ->orderBy('id')
            ->chunkById(200, function ($events): void {
                foreach ($events as $event) {
                    $normalized = $this->normalizeEggUid($event->egg_uid);

                    if ($normalized !== $event->egg_uid) {
                        DB::table('device_ingest_events')
                            ->where('id', $event->id)
                            ->update([
                                'egg_uid' => $normalized,
                            ]);
                    }
                }
            });
    }

    public function down(): void
    {
        // Irreversible data normalization.
    }

    private function normalizeEggUid(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        if (preg_match('/^egg(?:[-_\s]+)?(.*)$/i', $trimmed, $matches) === 1) {
            $suffix = trim((string) ($matches[1] ?? ''));
        } else {
            $suffix = $trimmed;
        }

        $suffix = ltrim($suffix, "-_ \t\n\r\0\x0B");

        if ($suffix === '') {
            return 'egg-';
        }

        return strtolower('egg-' . $suffix);
    }
};
