<?php

namespace App\Domain\Forms\Models;

use App\Domain\Integrations\Models\IntegrationEvent;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Заявка контактной формы (§2.5, W-09). service — ключ услуги (scanning, …) или equipment. attachments — список
 * приватных файлов {path, name, size, sha256}: path назначает сервер (UUID на диске local), name — исходное имя.
 */
#[Fillable(['locale', 'name', 'company', 'email', 'phone', 'service', 'description', 'deadline', 'attachments'])]
class ContactRequest extends Model
{
    protected function casts(): array
    {
        return ['deadline' => 'date', 'attachments' => 'array'];
    }

    /** Событие outbox заявки: статус письма для админки. @return HasOne<IntegrationEvent, $this> */
    public function event(): HasOne
    {
        return $this->hasOne(IntegrationEvent::class, 'entity_id')->where('entity_type', 'contact_requests');
    }
}
