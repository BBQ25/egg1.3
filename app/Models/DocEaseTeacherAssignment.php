<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocEaseTeacherAssignment extends Model
{
    protected $connection = 'doc_ease';

    protected $table = 'teacher_assignments';

    protected $primaryKey = 'id';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'teacher_id',
        'teacher_role',
        'class_record_id',
        'assigned_by',
        'assigned_at',
        'status',
        'assignment_notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'teacher_id' => 'integer',
            'class_record_id' => 'integer',
            'assigned_by' => 'integer',
            'assigned_at' => 'datetime',
        ];
    }

    public function classRecord(): BelongsTo
    {
        return $this->belongsTo(DocEaseClassRecord::class, 'class_record_id');
    }
}

