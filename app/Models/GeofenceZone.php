<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GeofenceZone extends Model
{
    use HasFactory;

    protected $fillable = [
        'shape_type',
        'center_latitude',
        'center_longitude',
        'radius_meters',
        'bounds_north',
        'bounds_south',
        'bounds_east',
        'bounds_west',
        'vertices_json',
        'is_active',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'center_latitude' => 'decimal:7',
            'center_longitude' => 'decimal:7',
            'bounds_north' => 'decimal:7',
            'bounds_south' => 'decimal:7',
            'bounds_east' => 'decimal:7',
            'bounds_west' => 'decimal:7',
            'radius_meters' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }
}

