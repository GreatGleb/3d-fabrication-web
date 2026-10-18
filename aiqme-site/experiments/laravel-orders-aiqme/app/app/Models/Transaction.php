<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    protected $guarded = [];

    protected $casts = ['amount' => 'integer', 'success' => 'boolean', 'meta' => 'array', 'captured_at' => 'datetime'];
}
