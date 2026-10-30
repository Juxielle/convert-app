<?php

namespace App\Services\Billing;

use App\Models\Subscription;
use Illuminate\Http\Request;

final class UnavailableGateway implements BillingGateway
{
    public function available(): bool
    {
        return false;
    }

    public function checkout(Subscription $subscription): string
    {
        throw new \LogicException('No payment provider has been integrated.');
    }

    public function refresh(Subscription $subscription): Subscription
    {
        return $subscription;
    }

    public function webhook(Request $request): void
    {
        throw new \LogicException('No verified payment webhook is available.');
    }
}
