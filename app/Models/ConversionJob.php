<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ConversionJob extends Model
{
    use HasUuids;

    public const ACTIVE = ['uploaded', 'queued', 'processing'];

    protected $guarded = [];

    protected $hidden = ['owner_key', 'session_token_hash', 'input_path', 'output_path', 'queue_id'];

    protected function casts(): array
    {
        return [
            'history_expires_at' => 'datetime', 'priority' => 'boolean',
            'options_json' => 'array', 'expires_at' => 'datetime', 'started_at' => 'datetime',
            'heartbeat_at' => 'datetime', 'completed_at' => 'datetime',
            'cancel_requested_at' => 'datetime', 'delete_requested_at' => 'datetime',
        ];
    }
}
