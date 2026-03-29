<?php

namespace App\Models;

use App\Support\EggUid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeviceIngestEvent extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'device_id',
        'farm_id',
        'owner_user_id',
        'production_batch_id',
        'egg_uid',
        'batch_code',
        'weight_grams',
        'size_class',
        'recorded_at',
        'source_ip',
        'raw_payload_json',
    ];

    protected function casts(): array
    {
        return [
            'weight_grams' => 'decimal:2',
            'recorded_at' => 'datetime',
            'created_at' => 'datetime',
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

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function productionBatch(): BelongsTo
    {
        return $this->belongsTo(ProductionBatch::class);
    }

    public function evaluationMeasurements(): HasMany
    {
        return $this->hasMany(EvaluationRunMeasurement::class);
    }
}
