<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EggIntakeRecord extends Model
{
    use HasFactory;

    protected $fillable = [
        'farm_id',
        'item_id',
        'movement_id',
        'source',
        'egg_type',
        'size_class',
        'weight_grams',
        'quantity',
        'stock_before',
        'stock_after',
        'reference_no',
        'notes',
        'payload_json',
        'created_by_user_id',
        'recorded_at',
    ];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'weight_grams' => 'decimal:2',
            'recorded_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(EggItem::class, 'item_id');
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'movement_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}

