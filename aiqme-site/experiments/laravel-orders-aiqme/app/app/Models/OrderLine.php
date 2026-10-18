<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderLine extends Model
{
    protected $guarded = [];

    protected $casts = ['meta' => 'array', 'unit_price' => 'integer', 'total' => 'integer'];

    public function purchasable(): BelongsTo
    {
        return $this->belongsTo(StlPart::class, 'stl_part_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
