<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

class DocEaseUser extends Authenticatable
{
    protected $connection = 'doc_ease';

    protected $table = 'users';

    protected $primaryKey = 'id';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'username',
        'useremail',
        'password',
        'role',
        'is_active',
        'campus_id',
        'is_superadmin',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'campus_id' => 'integer',
            'is_superadmin' => 'boolean',
        ];
    }

    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    public function getAuthPassword(): string
    {
        return (string) ($this->password ?? '');
    }

    public function normalizedRole(): string
    {
        $role = strtolower(trim((string) ($this->role ?? 'student')));
        if ($role === 'user') {
            return 'student';
        }

        if (in_array($role, ['admin', 'teacher', 'student'], true)) {
            return $role;
        }

        return 'student';
    }

    public function isAdminRole(): bool
    {
        return $this->normalizedRole() === 'admin';
    }

    public function canSignIn(): bool
    {
        if ($this->isAdminRole()) {
            return true;
        }

        return (bool) $this->is_active;
    }
}

