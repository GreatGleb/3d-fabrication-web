<?php

namespace App\Domain\Content\Models;

use App\Domain\Content\ContentType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Перевод элемента на локаль. title/slug/fields/SEO — опубликованная версия (published_revision), её видит сайт;
 * revision — номер последней сохранённой версии (черновика) для проверки одновременной правки (§4.2).
 */
#[Fillable(['content_item_id', 'type', 'locale', 'revision'])]
class ContentTranslation extends Model
{
    protected function casts(): array
    {
        return [
            'type' => ContentType::class,
            'fields' => 'array',
            'revision' => 'integer',
            'published_revision' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ContentItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(ContentItem::class, 'content_item_id');
    }

    /** @return HasMany<ContentRevision, $this> */
    public function revisions(): HasMany
    {
        return $this->hasMany(ContentRevision::class);
    }

    public function latestRevision(): ?ContentRevision
    {
        return $this->revisions()->where('revision', $this->revision)->first();
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null;
    }

    public function field(string $name, mixed $default = null): mixed
    {
        return $this->fields[$name] ?? $default;
    }

    /** @param  Builder<ContentTranslation>  $query */
    public function scopePublished(Builder $query): void
    {
        $query->whereNotNull('published_at');
    }
}
