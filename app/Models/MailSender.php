<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

#[Fillable([
    'name',
    'mail_host',
    'mail_port',
    'mail_username',
    'mail_password',
    'mail_encryption',
    'mail_from_address',
    'mail_from_name',
    'mail_admin_email',
    'logo_path',
    'is_active',
    'created_by_user_id',
    'updated_by_user_id',
])]
class MailSender extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'mail_port' => 'integer',
            'mail_password' => 'encrypted',
            'is_active' => 'boolean',
            'deleted_at' => 'datetime',
        ];
    }

    protected function logoUrl(): Attribute
    {
        return Attribute::get(function (): ?string {
            if (! $this->logo_path) {
                return null;
            }

            return Storage::disk('public')->url($this->logo_path);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function smtpConfig(): array
    {
        return [
            'transport' => 'smtp',
            'host' => $this->mail_host,
            'port' => $this->mail_port,
            'username' => $this->mail_username,
            'password' => $this->mail_password,
            'encryption' => $this->mail_encryption ?: null,
            'timeout' => null,
            'local_domain' => parse_url((string) config('app.url', 'http://localhost'), PHP_URL_HOST),
        ];
    }

    public function templates(): HasMany
    {
        return $this->hasMany(MailTemplate::class, 'mail_sender_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }
}
