<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Farm extends Model
{
    use HasFactory;

    protected $fillable = [
        'farm_name',
        'location',
        'sitio',
        'barangay',
        'municipality',
        'province',
        'latitude',
        'longitude',
        'owner_user_id',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'is_active' => 'boolean',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function eggItems(): HasMany
    {
        return $this->hasMany(EggItem::class);
    }

    public function staffUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'farm_staff_assignments', 'farm_id', 'user_id');
    }

    public function intakeRecords(): HasMany
    {
        return $this->hasMany(EggIntakeRecord::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    public function deviceIngestEvents(): HasMany
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

    public function premisesZone(): HasOne
    {
        return $this->hasOne(FarmPremisesZone::class, 'farm_id');
    }

    public function changeRequests(): HasMany
    {
        return $this->hasMany(FarmChangeRequest::class, 'farm_id');
    }
}
