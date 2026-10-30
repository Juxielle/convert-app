<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Subscriptions;
use Illuminate\Console\Command;

class ReconcileSubscriptions extends Command
{
    protected $signature = 'subscriptions:reconcile';

    protected $description = 'Reconcile subscription validity and restore free access when necessary';

    public function handle(Subscriptions $subscriptions): int
    {
        User::where(function ($query): void {
            $query->whereNull('current_subscription_id')->orWhereHas('currentSubscription', function ($current): void {
                $current->where('status', '!=', 'active')->orWhereNotNull('ends_at')->orWhereHas('plan', fn ($plan) => $plan->where('active', false));
            });
        })->orderBy('id')->chunkById(100, function ($users) use ($subscriptions): void {
            foreach ($users as $user) {
                $subscriptions->current($user);
            }
        });
        $this->info('Abonnements réconciliés.');

        return self::SUCCESS;
    }
}
