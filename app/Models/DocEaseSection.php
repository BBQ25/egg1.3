<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocEaseSection extends Model
{
    protected $connection = 'doc_ease';

    protected $table = 'sections';

    protected $primaryKey = 'id';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'description',
        'status',
    ];
}

