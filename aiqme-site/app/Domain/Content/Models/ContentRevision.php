<?php

namespace App\Domain\Content\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Сохранённая версия перевода (§4.2): последняя — черновик; восстановление старой создаёт новую. */
#[Fillable(['content_translation_id', 'revision', 'title', 'slug', 'fields', 'seo_title', 'seo_description', 'user_id', 'channel'])]
class ContentRevision extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'fields' => 'array',
            'revision' => 'integer',
        ];
    }
}
