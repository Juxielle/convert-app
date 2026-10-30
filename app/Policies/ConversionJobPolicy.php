<?php

namespace App\Policies;

use App\Models\ConversionJob;
use App\Models\User;
use App\Services\Conversions\Owner;
use Illuminate\Auth\Access\Response;

class ConversionJobPolicy
{
    public function access(?User $user, ConversionJob $job): Response
    {
        $owner = Owner::fromRequest(request());

        return hash_equals($job->owner_key, $owner->key)
            && (! $job->history_expires_at || $job->history_expires_at->isFuture())
            && $job->created_at->gte(now()->subHours($owner->limits()['history_hours'] ?? 24))
            ? Response::allow() : Response::denyAsNotFound();
    }
}
