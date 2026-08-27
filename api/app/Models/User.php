<?php

namespace App\Models;

use App\Models\Concerns\RecordsActivity;
use App\Domain\Role;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, RecordsActivity;

    /** Groups this model's entries in the activity log. */
    public const ACTIVITY_LOG_NAME = 'users';

    protected $fillable = ['name', 'email', 'role', 'is_active'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'is_active' => 'boolean',
            'password' => 'hashed',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === Role::ADMIN;
    }

    public function canWriteInventory(): bool
    {
        return in_array($this->role, Role::CAN_WRITE_INVENTORY, true);
    }

    /** Wire format for the Vue client. Never includes credentials. */
    public function toClientArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role,
            'roleLabel' => Role::label($this->role),
            'isActive' => (bool) $this->is_active,
            'canWriteInventory' => $this->canWriteInventory(),
            'lastLoginAt' => $this->last_login_at?->toIso8601ZuluString('millisecond'),
            'createdAt' => $this->created_at?->toIso8601ZuluString('millisecond'),
        ];
    }
}
