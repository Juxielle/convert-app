<?php

namespace App\Console\Commands;

use App\Models\PaymentAttempt;
use App\Models\Subscription;
use App\Services\Billing\BillingGateway;
use Illuminate\Console\Command;

class SaspayReconcile extends Command
{
    protected $signature = 'billing:saspay:reconcile';

    protected $description = 'Revérifier les paiements SasPay en attente sans initier de paiement';

    public function handle(BillingGateway $gateway): int
    {
        if (! $gateway->available()) {
            $this->error('SasPay non configuré.');

            return self::FAILURE;
        }
        $failures = 0;
        foreach (PaymentAttempt::whereNotNull('session_id')->whereNull('verified_at')->where('status', 'PENDING')->cursor() as $attempt) {
            try {
                $gateway->refresh(Subscription::findOrFail($attempt->subscription_id));
            } catch (\Throwable) {
                $failures++;
                $this->warn('Vérification indisponible pour la demande '.$attempt->id.'.');
            }
        }
        $this->info('Vérification terminée. Échecs : '.$failures.'.');

        return $failures ? self::FAILURE : self::SUCCESS;
    }
}
