<?php

namespace App\Services\Billing;

use App\Models\Subscription;
use Illuminate\Http\Request;

interface BillingGateway
{
    public function available(): bool;

    public function checkout(Subscription $subscription): string;

    public function refresh(Subscription $subscription): Subscription;

    public function webhook(Request $request): void;
}
