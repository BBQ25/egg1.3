<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocEaseSubject extends Model
{
    protected $connection = 'doc_ease';

    protected $table = 'subjects';

    protected $primaryKey = 'id';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'subject_code',
        'subject_name',
        'description',
        'course',
        'major',
        'academic_year',
        'semester',
        'units',
        'type',
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
            'units' => 'decimal:1',
            'created_by' => 'integer',
        ];
    }
}

