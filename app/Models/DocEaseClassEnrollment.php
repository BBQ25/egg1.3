<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocEaseClassEnrollment extends Model
{
    protected $connection = 'doc_ease';

    protected $table = 'class_enrollments';

    protected $primaryKey = 'id';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'class_record_id',
        'student_id',
        'enrollment_date',
        'status',
        'grade',
        'remarks',
        'created_by',
        'class_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'class_record_id' => 'integer',
            'student_id' => 'integer',
            'created_by' => 'integer',
            'class_id' => 'integer',
            'grade' => 'decimal:2',
            'enrollment_date' => 'date',
        ];
    }

    public function classRecord(): BelongsTo
    {
        return $this->belongsTo(DocEaseClassRecord::class, 'class_record_id');
    }
}

