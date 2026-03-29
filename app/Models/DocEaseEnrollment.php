<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocEaseEnrollment extends Model
{
    protected $connection = 'doc_ease';

    protected $table = 'enrollments';

    protected $primaryKey = 'id';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'student_no',
        'subject_id',
        'academic_year',
        'semester',
        'section',
        'enrollment_date',
        'status',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'subject_id' => 'integer',
            'enrollment_date' => 'datetime',
        ];
    }
}

