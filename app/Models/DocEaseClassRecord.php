<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DocEaseClassRecord extends Model
{
    protected $connection = 'doc_ease';

    protected $table = 'class_records';

    protected $primaryKey = 'id';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'subject_id',
        'teacher_id',
        'record_type',
        'section',
        'room_number',
        'schedule',
        'max_students',
        'description',
        'academic_year',
        'semester',
        'year_level',
        'created_by',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'subject_id' => 'integer',
            'teacher_id' => 'integer',
            'max_students' => 'integer',
            'created_by' => 'integer',
        ];
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(DocEaseSubject::class, 'subject_id');
    }

    public function teacherAssignments(): HasMany
    {
        return $this->hasMany(DocEaseTeacherAssignment::class, 'class_record_id');
    }

    public function classEnrollments(): HasMany
    {
        return $this->hasMany(DocEaseClassEnrollment::class, 'class_record_id');
    }
}

