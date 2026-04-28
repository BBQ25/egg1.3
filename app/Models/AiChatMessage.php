<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiChatMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'session_id',
        'user_id',
        'sender',
        'role_key',
        'intent',
        'status',
        'content',
        'scoped_data_json',
        'sources_json',
    ];

    protected function casts(): array
    {
        return [
            'scoped_data_json' => 'array',
            'sources_json' => 'array',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(AiChatSession::class, 'session_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function actionDrafts(): HasMany
    {
        return $this->hasMany(AiChatActionDraft::class, 'message_id');
    }
}
