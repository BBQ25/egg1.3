<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EggItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'farm_id',
        'item_code',
        'egg_type',
        'size_class',
        'unit_cost',
        'selling_price',
        'reorder_level',
        'current_stock',
    ];

    protected function casts(): array
    {
        return [
            'unit_cost' => 'decimal:2',
            'selling_price' => 'decimal:2',
        ];
    }

    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'item_id');
    }

    public function intakeRecords(): HasMany
    {
        return $this->hasMany(EggIntakeRecord::class, 'item_id');
    }
}

