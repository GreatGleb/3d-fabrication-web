<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Настройка сайта (§2.5): ключ → JSON-значение (calculator — W-11). */
#[Fillable(['key', 'value'])]
class Setting extends Model
{
    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected function casts(): array
    {
        return ['value' => 'array'];
    }
}
