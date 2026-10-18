<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductionState extends Model
{
    protected $guarded = [];

    protected $casts = ['confirmed_at' => 'datetime', 'started_at' => 'datetime'];
}
