<?php

namespace App\Models;

use App\Support\EggUid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EvaluationRunMeasurement extends Model
{
    use HasFactory;

    protected $fillable = [
        'evaluation_run_id',
        'device_ingest_event_id',
        'egg_uid',
        'batch_code',
        'reference_weight_grams',
        'automated_weight_grams',
        'manual_size_class',
        'automated_size_class',
        'weight_error_grams',
        'absolute_error_grams',
        'class_match',
        'measured_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'reference_weight_grams' => 'decimal:2',
            'automated_weight_grams' => 'decimal:2',
            'weight_error_grams' => 'decimal:2',
            'absolute_error_grams' => 'decimal:2',
            'class_match' => 'boolean',
            'measured_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function getEggUidAttribute(?string $value): ?string
    {
        return EggUid::normalize($value);
    }

    public function setEggUidAttribute(mixed $value): void
    {
        $this->attributes['egg_uid'] = EggUid::normalize($value === null ? null : (string) $value);
    }

    public function evaluationRun(): BelongsTo
    {
        return $this->belongsTo(EvaluationRun::class);
    }

    public function deviceIngestEvent(): BelongsTo
    {
        return $this->belongsTo(DeviceIngestEvent::class);
    }
}
