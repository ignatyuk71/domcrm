<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    public const ROLE_OWNER = 'owner';
    public const ROLE_OPERATOR = 'operator';
    public const ROLE_PACKER = 'packer';

    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

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
        'is_active',
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
            'is_active' => 'boolean',
        ];
    }

    public static function roleOptions(): array
    {
        return [
            self::ROLE_OWNER => 'Власник',
            self::ROLE_OPERATOR => 'Оператор',
            self::ROLE_PACKER => 'Пакувальник',
        ];
    }

    public function roleKey(): string
    {
        return $this->role ?: self::ROLE_OWNER;
    }

    public function isActive(): bool
    {
        $value = $this->getAttribute('is_active');

        return $value === null ? true : (bool) $value;
    }

    public function isOwner(): bool
    {
        return $this->roleKey() === self::ROLE_OWNER;
    }

    public function hasAnyRole(array $roles): bool
    {
        return in_array($this->roleKey(), $roles, true);
    }
}
