<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /** Full access, including data and user management. */
    public const ROLE_ADMIN = 'admin';

    /** Default role for self-registered accounts. */
    public const ROLE_USER = 'user';

    /** Maintains reference datasets. No access to the admin dashboard. */
    public const ROLE_TECHNICIAN = 'technician';

    /** Roles permitted to manage reference data. */
    public const DATA_ROLES = [self::ROLE_ADMIN, self::ROLE_TECHNICIAN];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
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
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function dashboardRoute(): string
    {
        return match (strtolower((string) $this->role)) {
            self::ROLE_ADMIN => 'admin.dashboard.index',
            default => 'user.dashboard.index',
        };
    }

    /**
     * Whether this account may reach the admin area.
     */
    public function isAdmin(): bool
    {
        return strtolower((string) $this->role) === self::ROLE_ADMIN;
    }

    /**
     * Whether this account may manage reference datasets.
     */
    public function canManageData(): bool
    {
        return in_array(strtolower((string) $this->role), self::DATA_ROLES, true);
    }
}
