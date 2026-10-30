<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\PaymentAttempt;
use App\Models\Subscription;
use App\Models\SubscriptionEvent;
use App\Models\SubscriptionIntent;
use App\Models\SubscriptionNotice;
use App\Models\TrialGrant;
use App\Models\User;
use App\Services\Conversions\PipelineLock;
use Illuminate\Validation\ValidationException;

class Subscriptions
{
    public function current(User $user): Subscription
    {
        return app(PipelineLock::class)->run(function () use ($user): Subscription {
            $account = User::query()->lockForUpdate()->findOrFail($user->id);
            $subscription = Subscription::with('plan')->where('user_id', $account->id)->find($account->current_subscription_id);
            if ($subscription && $this->effective($subscription)) {
                $this->remind($subscription);

                return $subscription;
            }
            if ($subscription && in_array($subscription->status, ['active', 'trialing'], true)) {
                $status = $subscription->ends_at && ! $subscription->ends_at->isFuture() ? 'expired' : 'canceled';
                $subscription->update(['status' => $status, 'cancelled_at' => $status === 'canceled' ? now() : null]);
                SubscriptionEvent::create(['subscription_id' => $subscription->id, 'type' => $status, 'metadata' => ['reason' => 'not_effective', 'plan' => $subscription->plan->name], 'created_at' => now()]);
            }
            $free = Plan::where('slug', 'free')->where('is_free', true)->firstOrFail();
            $next = Subscription::create(['user_id' => $account->id, 'plan_id' => $free->id, 'status' => 'active', 'starts_at' => now(), 'price_amount' => 0, 'currency' => 'XAF']);
            $account->forceFill(['current_subscription_id' => $next->id, 'plan' => 'free'])->save();
            $user->forceFill(['current_subscription_id' => $next->id, 'plan' => 'free']);
            SubscriptionEvent::create(['subscription_id' => $next->id, 'type' => 'free_assigned', 'metadata' => ['reason' => $subscription ? 'fallback' : 'registration', 'previous_subscription_id' => $subscription?->id, 'plan' => $free->name], 'created_at' => now()]);

            return $next->load('plan');
        });
    }

    public function effective(Subscription $subscription): bool
    {
        if (! in_array($subscription->status, ['active', 'trialing'], true) || $subscription->starts_at->isFuture() || ($subscription->ends_at && ! $subscription->ends_at->isFuture()) || ! $subscription->plan->active) {
            return false;
        }
        if ($subscription->status === 'trialing') {
            return ! $subscription->plan->is_free && $subscription->trial_ends_at?->isFuture()
                && $subscription->ends_at?->equalTo($subscription->trial_ends_at)
                && $subscription->plan->quotas !== null
                && TrialGrant::where('user_id', $subscription->user_id)->where('subscription_id', $subscription->id)->where('ends_at', $subscription->trial_ends_at)->exists();
        }

        return $subscription->plan->is_free || ($subscription->payment_provider === 'saspay'
            && PaymentAttempt::where('subscription_id', $subscription->id)->where('transaction_id', $subscription->provider_reference)->whereNotNull('verified_at')->exists()
            && $subscription->payment_confirmed_at !== null && $subscription->payment_provider
            && $subscription->provider_reference && $subscription->ends_at !== null
            && $subscription->price_amount > 0 && $subscription->currency === 'XAF'
            && in_array($subscription->billing_period, ['monthly', 'yearly'], true)
            && is_array($subscription->plan->quotas)
            && array_diff(array_keys(config('conversion.quota_bounds')), array_keys($subscription->plan->quotas)) === []);
    }

    public function eligible(User $user): bool
    {
        return ! TrialGrant::where('user_id', $user->id)->exists();
    }

    public function fingerprint(Plan $plan): string
    {
        return hash('sha256', json_encode($plan->only(['active', 'monthly_price', 'yearly_price', 'currency', 'billing_periods', 'trial_days', 'trial_enabled', 'quotas', 'entitlements', 'allowed_tools']), JSON_THROW_ON_ERROR));
    }

    private function price(Plan $plan, string $period): int
    {
        $amount = $period === 'monthly' ? $plan->monthly_price : $plan->yearly_price;
        if (! $plan->active || $plan->is_free || ! in_array($period, $plan->billing_periods, true) || ! $amount || ! $plan->quotas) {
            throw ValidationException::withMessages(['plan' => ['Cette formule ou cette périodicité est indisponible.']]);
        }

        return $amount;
    }

    public function quote(User $user, Plan $plan, string $period, string $mode, ?string $paymentMethod = null): SubscriptionIntent
    {
        return app(PipelineLock::class)->run(function () use ($user, $plan, $period, $mode, $paymentMethod) {
            if (! array_key_exists($paymentMethod ?? '', config('billing.payment_methods'))) {
                throw ValidationException::withMessages(['payment_method' => ['Choisissez un moyen de paiement proposé.']]);
            }
            $plan->refresh();
            $amount = $this->price($plan, $period);
            $current = $this->current($user);
            if ($mode === 'trial' && (! $this->eligible($user) || ! $current->plan->is_free || ! $plan->trial_enabled || $plan->trial_days < 1)) {
                throw ValidationException::withMessages(['trial' => ['Essai indisponible : un seul essai est autorisé par compte, toutes formules confondues.']]);
            }

            return SubscriptionIntent::create(['user_id' => $user->id, 'plan_id' => $plan->id, 'mode' => $mode, 'period' => $period, 'payment_method' => $paymentMethod, 'amount' => $amount, 'trial_days' => $plan->trial_days, 'fingerprint' => $this->fingerprint($plan), 'expires_at' => now()->addMinutes(config('billing.quote_minutes'))]);
        });
    }

    public function confirm(User $user, string $intentId, string $mode): Subscription
    {
        if ($mode === 'trial' && config('billing.trial_requires_payment')) {
            throw ValidationException::withMessages(['payment' => ['La validation d’un moyen de paiement sans débit pour l’essai gratuit n’est pas encore disponible avec SasPay. Choisissez un paiement immédiat pour souscrire.']]);
        }
        return app(PipelineLock::class)->run(function () use ($user, $intentId, $mode): Subscription {
            $intent = SubscriptionIntent::where('user_id', $user->id)->findOrFail($intentId);
            abort_unless($intent->mode === $mode, 422);
            if ($intent->consumed_at) {
                return Subscription::with('plan')->where('user_id', $user->id)->findOrFail($intent->subscription_id);
            }
            $plan = Plan::findOrFail($intent->plan_id);
            if (! $intent->expires_at->isFuture() || ! hash_equals($intent->fingerprint, $this->fingerprint($plan))) {
                throw ValidationException::withMessages(['quote' => ['Ce récapitulatif a expiré ou l’offre a changé. Vérifiez à nouveau les conditions.']]);
            }
            $this->price($plan, $intent->period);
            $current = $this->current($user);
            if ($intent->mode === 'trial' && (! $this->eligible($user) || ! $current->plan->is_free || ! $plan->trial_enabled)) {
                throw ValidationException::withMessages(['trial' => ['Votre essai a déjà été utilisé. Changer de formule ne le réinitialise pas.']]);
            }
            $trial = $intent->mode === 'trial';
            $end = $trial ? now()->addDays($intent->trial_days) : null;
            $next = Subscription::create(['user_id' => $user->id, 'plan_id' => $plan->id, 'status' => $trial ? 'trialing' : 'pending_payment', 'starts_at' => now(), 'ends_at' => $end,
                'trial_started_at' => $trial ? now() : null, 'trial_ends_at' => $end, 'price_amount' => $intent->amount, 'currency' => $plan->currency, 'billing_period' => $intent->period, 'payment_method' => $intent->payment_method]);
            if ($trial) {
                // Database uniqueness is a second defense against concurrent confirmations.
                TrialGrant::create(['user_id' => $user->id, 'plan_id' => $plan->id, 'subscription_id' => $next->id, 'granted_at' => now(), 'ends_at' => $end]);
                $current->update(['status' => 'canceled', 'cancelled_at' => now()]);
                $this->event($current, 'canceled', ['reason' => 'trial_started']);
                User::whereKey($user->id)->update(['current_subscription_id' => $next->id, 'plan' => $plan->slug]);
            } else {
                // A pending payment never replaces or extends the current trial/access.
                foreach (Subscription::where('user_id', $user->id)->where('status', 'pending_payment')->whereKeyNot($next->id)->get() as $old) {
                    $old->update(['status' => 'canceled', 'cancelled_at' => now()]);
                    $this->event($old, 'canceled', ['reason' => 'replaced_pending_request']);
                }
            }
            $this->event($next, $trial ? 'trial_started' : 'payment_pending', ['period' => $intent->period, 'amount' => $intent->amount, 'charged_amount' => 0, 'payment_method' => $next->payment_method]);
            $intent->update(['consumed_at' => now(), 'subscription_id' => $next->id]);

            return $next->load('plan');
        });
    }

    public function activatePayment(int $subscriptionId, string $attemptId, string $transactionId): Subscription
    {
        return app(PipelineLock::class)->run(function () use ($subscriptionId, $attemptId, $transactionId): Subscription {
            $subscription = Subscription::with('plan')->findOrFail($subscriptionId);
            $attempt = PaymentAttempt::where('subscription_id', $subscriptionId)->findOrFail($attemptId);
            if ($attempt->verified_at) {
                return $subscription;
            }
            abort_if(PaymentAttempt::where('transaction_id', $transactionId)->whereKeyNot($attemptId)->exists(), 422);
            $attempt->update(['transaction_id' => $transactionId, 'status' => 'PAID', 'verified_at' => now()]);
            // Late payments on replaced requests are recorded for review, never silently change access.
            if ($subscription->status !== 'pending_payment' || ! $subscription->plan->active) {
                $this->event($subscription, 'payment_review_required', ['transaction_id' => $transactionId]);

                return $subscription;
            }
            $user = User::findOrFail($subscription->user_id);
            $current = $this->current($user);
            $current->update(['status' => 'canceled', 'cancelled_at' => now()]);
            $this->event($current, 'canceled', ['reason' => 'payment_confirmed']);
            $subscription->update(['status' => 'active', 'starts_at' => now(),
                'ends_at' => $subscription->billing_period === 'yearly' ? now()->addYearNoOverflow() : now()->addMonthNoOverflow(),
                'payment_provider' => 'saspay', 'provider_reference' => $transactionId, 'payment_confirmed_at' => now()]);
            $user->forceFill(['current_subscription_id' => $subscription->id, 'plan' => $subscription->plan->slug])->save();
            $this->event($subscription, 'payment_confirmed', ['transaction_id' => $transactionId, 'amount' => $subscription->price_amount, 'currency' => $subscription->currency]);

            return $subscription->fresh('plan');
        });
    }

    public function changePeriod(User $user, string $period, int $expectedAmount): Subscription
    {
        return app(PipelineLock::class)->run(function () use ($user, $period, $expectedAmount): Subscription {
            $current = $this->current($user);
            if ($current->status !== 'trialing') {
                throw ValidationException::withMessages(['period' => ['La périodicité d’une souscription payante changera seulement après confirmation réelle du paiement.']]);
            }
            $amount = $this->price($current->plan, $period);
            if ($amount !== $expectedAmount) {
                throw ValidationException::withMessages(['price' => ['Le prix a changé. Rechargez le récapitulatif.']]);
            }
            if ($current->billing_period !== $period) {
                $previous = $current->billing_period;
                $current->update(['billing_period' => $period, 'price_amount' => $amount]);
                $this->event($current, 'period_changed', ['previous' => $previous, 'period' => $period, 'trial_ends_at' => $current->trial_ends_at->toIso8601String()]);
            }

            return $current;
        });
    }

    public function cancelTrial(User $user): void
    {
        app(PipelineLock::class)->run(function () use ($user): void {
            $current = $this->current($user);
            if ($current->status === 'trialing') {
                $current->update(['status' => 'canceled', 'cancelled_at' => now()]);
                $this->event($current, 'canceled', ['reason' => 'user_request']);
                $this->current($user);
            }
        });
    }

    public function remind(Subscription $subscription): void
    {
        if ($subscription->status !== 'trialing' || ! $subscription->trial_ends_at?->isFuture()) {
            return;
        }
        $seconds = now()->diffInSeconds($subscription->trial_ends_at);
        $days = $seconds <= 86400 ? 1 : ($seconds <= 3 * 86400 ? 3 : null);
        if ($days) {
            SubscriptionNotice::firstOrCreate(['subscription_id' => $subscription->id, 'key' => 'trial_j'.$days], ['message' => 'Votre essai '.$subscription->plan->name.' se termine le '.$subscription->trial_ends_at->format('d/m/Y à H:i').' UTC. Sans paiement confirmé, votre compte reviendra au Gratuit. Aucun prélèvement automatique.', 'created_at' => now()]);
        }
    }

    private function event(Subscription $subscription, string $type, array $metadata): void
    {
        SubscriptionEvent::create(['subscription_id' => $subscription->id, 'type' => $type, 'metadata' => ['plan' => $subscription->plan->name, ...$metadata], 'created_at' => now()]);
    }
}
