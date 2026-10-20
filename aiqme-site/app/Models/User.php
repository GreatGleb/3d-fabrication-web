<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Mail\ResetPasswordLink;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Mail;
use Lunar\Base\LunarUser;
use Lunar\Base\Traits\LunarUser as HasLunarCustomers;

/**
 * Клиент или единственный администратор (is_admin). is_admin намеренно не входит в $fillable:
 * права выдаёт только команда aiqme:make-admin (§2.5, D-29).
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements LunarUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasLunarCustomers, Notifiable;

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
            'is_admin' => 'boolean',
        ];
    }

    /** @return HasOne<CustomerProfile, $this> */
    public function profile(): HasOne
    {
        return $this->hasOne(CustomerProfile::class);
    }

    /**
     * Письмо сброса пароля (W-07) — Mailable в очереди на языке страницы запроса, вместо уведомления Laravel.
     *
     * @param  string  $token
     */
    public function sendPasswordResetNotification($token): void
    {
        Mail::to($this)->queue(new ResetPasswordLink($this->email, $token, app()->getLocale()));
    }
}
