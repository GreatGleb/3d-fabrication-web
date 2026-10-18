<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    protected $guarded = [];

    protected $casts = ['total' => 'integer', 'sub_total' => 'integer', 'placed_at' => 'datetime', 'confirmed_at' => 'datetime', 'started_at' => 'datetime'];

    public function lines(): HasMany
    {
        return $this->hasMany(OrderLine::class)->orderBy('id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function captures(): HasMany
    {
        return $this->transactions()->whereType('capture');
    }

    public function refunds(): HasMany
    {
        return $this->transactions()->whereType('refund');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
