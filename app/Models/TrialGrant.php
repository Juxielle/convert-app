<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TrialGrant extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['granted_at' => 'datetime', 'ends_at' => 'datetime'];
    }
}
