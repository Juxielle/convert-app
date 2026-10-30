<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UsageEvent extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['billable' => 'boolean'];
    }
}
