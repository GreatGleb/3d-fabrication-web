<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BasketLine extends Model
{
    protected $guarded = [];

    public function part(): BelongsTo
    {
        return $this->belongsTo(StlPart::class, 'stl_part_id');
    }
}
