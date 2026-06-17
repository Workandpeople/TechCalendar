<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable([
    'first_name',
    'last_name',
    'email',
    'password',
    'must_change_password',
    'notification_mail_enabled',
    'notification_push_enabled',
    'role',
    'admin',
    'phone',
    'address',
    'department_code',
    'latitude',
    'longitude',
    'day_start_time',
    'day_end_time',
    'break_duration_minutes',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, SoftDeletes;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => 'integer',
            'admin' => 'boolean',
            'must_change_password' => 'boolean',
            'notification_mail_enabled' => 'boolean',
            'notification_push_enabled' => 'boolean',
            'latitude' => 'float',
            'longitude' => 'float',
            'break_duration_minutes' => 'integer',
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'deleted_at' => 'datetime',
        ];
    }

    protected function fullName(): Attribute
    {
        return Attribute::get(fn (): string => trim("{$this->first_name} {$this->last_name}"));
    }

    protected function assignedDepartmentCodes(): Attribute
    {
        return Attribute::get(function (): string {
            $codes = $this->relationLoaded('departments')
                ? $this->departments->pluck('code')
                : $this->departments()->pluck('departments.code');

            $codes = $codes
                ->filter()
                ->map(fn ($code): string => mb_strtoupper(trim((string) $code)))
                ->unique()
                ->sort()
                ->values();

            if ($codes->isEmpty() && filled($this->department_code)) {
                $codes->push(mb_strtoupper((string) $this->department_code));
            }

            return $codes->implode(',');
        });
    }

    protected function fullNameWithDepartments(): Attribute
    {
        return Attribute::get(function (): string {
            if ((int) $this->role !== 2) {
                return $this->full_name;
            }

            return trim($this->full_name.' ('.($this->assigned_department_codes ?: '--').')');
        });
    }

    protected function initials(): Attribute
    {
        return Attribute::get(function (): string {
            $first = mb_substr((string) $this->first_name, 0, 1);
            $last = mb_substr((string) $this->last_name, 0, 1);

            return mb_strtoupper($first.$last);
        });
    }

    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class)->withTimestamps();
    }

    public function departments(): BelongsToMany
    {
        return $this->belongsToMany(Department::class, 'department_user', 'user_id', 'department_code')
            ->withTimestamps();
    }

    public function absences(): HasMany
    {
        return $this->hasMany(TechnicianAbsence::class, 'technician_id');
    }

    public function mobilePushTokens(): HasMany
    {
        return $this->hasMany(MobilePushToken::class);
    }
}
