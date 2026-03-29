<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\UserRole;
use App\Enums\UserRegistrationStatus;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'full_name',
        'first_name',
        'middle_name',
        'last_name',
        'address',
        'username',
        'password_hash',
        'role',
        'is_active',
        'registration_status',
        'approved_by_user_id',
        'approved_at',
        'denied_by_user_id',
        'denied_at',
        'denial_reason',
        'deactivated_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password_hash',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password_hash' => 'hashed',
            'role' => UserRole::class,
            'is_active' => 'boolean',
            'registration_status' => UserRegistrationStatus::class,
            'approved_at' => 'datetime',
            'denied_at' => 'datetime',
            'deactivated_at' => 'datetime',
        ];
    }

    /**
     * The model has a single timestamp column (`created_at`) in this schema.
     *
     * @var string|null
     */
    public const UPDATED_AT = null;

    public function getAuthPassword(): string
    {
        return $this->password_hash;
    }

    public function getAuthPasswordName(): string
    {
        return 'password_hash';
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::ADMIN;
    }

    public function isOwner(): bool
    {
        return $this->role === UserRole::OWNER;
    }

    public function isStaff(): bool
    {
        return $this->role === UserRole::WORKER;
    }

    public function isCustomer(): bool
    {
        return $this->role === UserRole::CUSTOMER;
    }

    public function isActive(): bool
    {
        return $this->is_active;
    }

    public function isApproved(): bool
    {
        return $this->registration_status === UserRegistrationStatus::APPROVED;
    }

    public function isPendingApproval(): bool
    {
        return $this->registration_status === UserRegistrationStatus::PENDING;
    }

    public function isDenied(): bool
    {
        return $this->registration_status === UserRegistrationStatus::DENIED;
    }

    public function ownedFarms(): HasMany
    {
        return $this->hasMany(Farm::class, 'owner_user_id');
    }

    public function staffFarms(): BelongsToMany
    {
        return $this->belongsToMany(Farm::class, 'farm_staff_assignments', 'user_id', 'farm_id');
    }

    public function premisesZone(): HasOne
    {
        return $this->hasOne(UserPremisesZone::class, 'user_id');
    }

    public function intakeRecords(): HasMany
    {
        return $this->hasMany(EggIntakeRecord::class, 'created_by_user_id');
    }

    public function registeredDevices(): HasMany
    {
        return $this->hasMany(Device::class, 'owner_user_id');
    }

    public function deviceIngestEvents(): HasMany
    {
        return $this->hasMany(DeviceIngestEvent::class, 'owner_user_id');
    }

    public function productionBatches(): HasMany
    {
        return $this->hasMany(ProductionBatch::class, 'owner_user_id');
    }

    public function evaluationRuns(): HasMany
    {
        return $this->hasMany(EvaluationRun::class, 'owner_user_id');
    }

    public function performedEvaluationRuns(): HasMany
    {
        return $this->hasMany(EvaluationRun::class, 'performed_by_user_id');
    }

    public function farmChangeRequests(): HasMany
    {
        return $this->hasMany(FarmChangeRequest::class, 'owner_user_id');
    }
}
