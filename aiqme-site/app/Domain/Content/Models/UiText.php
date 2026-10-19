<?php

namespace App\Domain\Content\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Переопределение строки интерфейса (ui_text, §4.1): ключ lang/ ("shell.nav.services") → значение на локали. */
#[Fillable(['locale', 'key', 'value', 'revision', 'updated_by'])]
class UiText extends Model
{
    protected function casts(): array
    {
        return ['revision' => 'integer'];
    }
}
