<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PaymentAttempt extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $hidden = ['checkout_url', 'request_payload'];

    protected function casts(): array
    {
        return ['verified_at' => 'datetime', 'request_payload' => 'array'];
    }
}
