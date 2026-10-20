<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Профиль «Addresses & company» клиента (§2.5): один на пользователя, все поля необязательны.
 * Подставляется в форму заявки (W-12); выпущенные счета хранят свой снимок (§3.5).
 */
#[Fillable(['name', 'company', 'company_code', 'vat_code', 'address', 'city', 'postcode'])]
class CustomerProfile extends Model
{
    /** Поля формы прототипа (address-form) по порядку. */
    public const FIELDS = ['name', 'company', 'company_code', 'vat_code', 'address', 'city', 'postcode'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
