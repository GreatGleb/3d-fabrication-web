<?php

namespace App\Domain\Forms\Models;

use App\Domain\Content\Models\ContentItem;
use App\Domain\Integrations\Models\IntegrationEvent;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Регистрация на обучение (§2.5, W-09): поля формы прототипа (training-form) и снимок названия и даты обучения —
 * запись обучения потом может измениться или исчезнуть.
 */
#[Fillable(['locale', 'content_item_id', 'training_title', 'training_date', 'name', 'email', 'format', 'participants'])]
class TrainingRegistration extends Model
{
    /** Варианты «Participation» прототипа. */
    public const FORMATS = ['online', 'aiqme'];

    /** @return BelongsTo<ContentItem, $this> */
    public function training(): BelongsTo
    {
        return $this->belongsTo(ContentItem::class, 'content_item_id');
    }

    /** Событие outbox заявки: статус письма для админки. @return HasOne<IntegrationEvent, $this> */
    public function event(): HasOne
    {
        return $this->hasOne(IntegrationEvent::class, 'entity_id')->where('entity_type', 'training_registrations');
    }
}
