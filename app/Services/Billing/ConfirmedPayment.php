<?php

namespace App\Services\Billing;

final readonly class ConfirmedPayment
{
    public function __construct(
        public string $provider,
        public string $eventId,
        public string $subscriptionReference,
        public int $userId,
        public int $planId,
        public string $period,
        public int $amount,
        public string $currency,
        public \DateTimeImmutable $startsAt,
        public \DateTimeImmutable $endsAt,
    ) {}
}
