<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockMovement extends Model
{
    use HasFactory;

    protected $fillable = [
        'item_id',
        'movement_type',
        'quantity',
        'stock_before',
        'stock_after',
        'unit_cost',
        'reference_no',
        'notes',
        'movement_date',
    ];

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'unit_cost' => 'decimal:2',
            'movement_date' => 'date',
            'created_at' => 'datetime',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(EggItem::class);
    }

    public function intakeRecords(): HasMany
    {
        return $this->hasMany(EggIntakeRecord::class, 'movement_id');
    }
}

