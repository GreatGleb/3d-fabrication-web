<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StlPart extends Model
{
    protected $guarded = [];

    protected $casts = ['parameters' => 'array'];
}
