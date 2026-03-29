<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Device extends Model
{
    use HasFactory;

    protected $hidden = [
        'api_key_hash',
        'api_key_encrypted',
    ];

    protected $fillable = [
        'owner_user_id',
        'farm_id',
        'module_board_name',
        'primary_serial_no',
        'main_technical_specs',
        'processing_memory',
        'gpio_interfaces',
        'api_key_hash',
        'api_key_encrypted',
        'is_active',
        'last_seen_at',
        'last_seen_ip',
        'deactivated_at',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_seen_at' => 'datetime',
            'deactivated_at' => 'datetime',
            'api_key_encrypted' => 'encrypted',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class, 'farm_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(DeviceSerialAlias::class);
    }

    public function ingestEvents(): HasMany
    {
        return $this->hasMany(DeviceIngestEvent::class);
    }

    public function productionBatches(): HasMany
    {
        return $this->hasMany(ProductionBatch::class);
    }

    public function evaluationRuns(): HasMany
    {
        return $this->hasMany(EvaluationRun::class);
    }

    public static function normalizeSerial(string $serial): string
    {
        return strtoupper(trim($serial));
    }
}
