<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Models\PaymentAttempt;
use App\Models\Subscription;
use App\Models\SubscriptionEvent;
use App\Models\SubscriptionNotice;
use App\Services\Billing\BillingGateway;
use App\Services\Conversions\Owner;
use App\Services\Conversions\Pipeline;
use App\Services\PlanCatalog;
use App\Services\Subscriptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SubscriptionController extends Controller
{
    public function show(Request $request, PlanCatalog $catalog, Subscriptions $subscriptions, Pipeline $pipeline): JsonResponse
    {
        $owner = Owner::fromRequest($request);
        $subscription = $request->user() ? $subscriptions->current($request->user()) : null;
        $history = $subscription ? SubscriptionEvent::whereHas('subscription', fn ($query) => $query->where('user_id', $request->user()->id))->latest('id')->limit(20)->get(['id', 'type', 'metadata', 'created_at']) : [];

        return response()->json(['data' => [
            'plan' => $catalog->present($subscription?->plan ?? $catalog->plan(null)),
            'is_guest' => ! $request->user(), 'status' => $subscription?->status ?? 'active',
            'starts_at' => $subscription?->starts_at, 'ends_at' => $subscription?->ends_at, 'renews_at' => $subscription?->renews_at,
            'trial_started_at' => $subscription?->trial_started_at, 'trial_ends_at' => $subscription?->trial_ends_at,
            'trial_remaining_seconds' => $subscription?->status === 'trialing' ? max(0, (int) now()->diffInSeconds($subscription->trial_ends_at, false)) : null,
            'trial_eligible' => $request->user() ? $subscriptions->eligible($request->user()) : false,
            'trial_policy' => config('billing.trial_policy'),
            'price_amount' => $subscription?->price_amount ?? 0, 'currency' => 'XAF', 'billing_period' => $subscription?->billing_period,
            'notices' => $subscription ? SubscriptionNotice::where('subscription_id', $subscription->id)->latest('id')->get(['id', 'key', 'message', 'created_at']) : [],
            'pending_payments' => $request->user() ? Subscription::with('plan:id,name,slug')->where('user_id', $request->user()->id)->where('status', 'pending_payment')->latest('id')->get(['id', 'plan_id', 'status', 'billing_period', 'price_amount', 'currency', 'created_at']) : [],
            'limits' => $owner->limits(), 'used' => $pipeline->usage($owner), 'external_used' => $pipeline->usage($owner, true),
            'history' => $history, 'can_change_plan' => true, 'payment_available' => app(BillingGateway::class)->available(),
        ]]);
    }

    public function checkout(Request $request): JsonResponse
    {
        $values = $request->validate(['quote_id' => ['required', 'uuid'], 'confirmed' => ['required', 'accepted'], ...$this->excludedPaymentFields()]);
        $subscription = app(Subscriptions::class)->confirm($request->user(), $values['quote_id'], 'pay');

        $gateway = app(BillingGateway::class);
        if ($gateway->available()) {
            if ($subscription->payment_confirmed_at) {
                return response()->json(['data' => ['id' => $subscription->id, 'status' => $subscription->status, 'payment_available' => true]]);
            }

            return response()->json(['data' => ['id' => $subscription->id, 'status' => $subscription->status,
                'payment_available' => true, 'checkout_url' => $gateway->checkout($subscription)]]);
        }

        return response()->json(['data' => ['id' => $subscription->id, 'status' => $subscription->status, 'payment_method' => $subscription->payment_method, 'payment_available' => false, 'charged_amount' => 0,
            'message' => 'Le paiement sera bientôt disponible. Aucun paiement ni prélèvement n’a été effectué. Votre accès actuel reste inchangé.']], 202);
    }

    public function quote(Request $request, Subscriptions $subscriptions, PlanCatalog $catalog): JsonResponse
    {
        $values = $request->validate(['plan' => ['required', 'string', 'exists:plans,slug'], 'period' => ['required', 'in:monthly,yearly'], 'mode' => ['required', 'in:trial,pay'],
            'payment_method' => ['required', Rule::in(array_keys(config('billing.payment_methods')))], ...$this->excludedPaymentFields()]);
        $intent = $subscriptions->quote($request->user(), Plan::where('slug', $values['plan'])->firstOrFail(), $values['period'], $values['mode'], $values['payment_method'] ?? null);

        return response()->json(['data' => ['id' => $intent->id, 'plan' => $catalog->present(Plan::findOrFail($intent->plan_id)), 'period' => $intent->period, 'mode' => $intent->mode,
            'amount' => $intent->amount, 'currency' => 'XAF', 'trial_days' => $intent->trial_days, 'expires_at' => $intent->expires_at,
            'payment_method' => $intent->payment_method, 'payment_method_label' => config('billing.payment_methods.'.$intent->payment_method.'.label'),
            'trial_policy' => config('billing.trial_policy'), 'charged_now' => 0, 'payment_available' => app(BillingGateway::class)->available()]]);
    }

    public function paymentMethods(): JsonResponse
    {
        return response()->json(['data' => ['available' => app(BillingGateway::class)->available(), 'hosted_checkout' => true,
            'trial_payment_setup_available' => false,
            'trial_requires_payment' => config('billing.trial_requires_payment'),
            'methods' => collect(config('billing.payment_methods'))->map(fn ($method, $id) => ['id' => $id, ...$method, 'fields_enabled' => false])->values()]]);
    }

    public function paymentStatus(Request $request, int $subscription): JsonResponse
    {
        $record = Subscription::where('user_id', $request->user()->id)->findOrFail($subscription);
        $record = app(BillingGateway::class)->refresh($record);
        $attempt = PaymentAttempt::where('subscription_id', $record->id)->first();

        return response()->json(['data' => ['id' => $record->id, 'status' => $record->status,
            'payment_confirmed' => $record->payment_confirmed_at !== null,
            'payment_status' => $attempt?->status,
            'checkout_url' => $record->status === 'pending_payment' && $attempt?->status === 'PENDING' ? $attempt?->checkout_url : null,
            'review_required' => $record->events()->where('type', 'payment_review_required')->exists()]]);
    }

    public function paymentWebhook(Request $request): JsonResponse
    {
        app(BillingGateway::class)->webhook($request);

        return response()->json(['received' => true]);
    }

    private function excludedPaymentFields(): array
    {
        return array_fill_keys(['card_number', 'cardholder', 'expiry', 'cvv', 'cvc', 'pin', 'otp', 'phone', 'mobile_number'], ['prohibited']);
    }

    public function trial(Request $request): JsonResponse
    {
        $values = $request->validate(['quote_id' => ['required', 'uuid'], 'confirmed' => ['required', 'accepted']]);
        $subscription = app(Subscriptions::class)->confirm($request->user(), $values['quote_id'], 'trial');

        return response()->json(['data' => ['id' => $subscription->id, 'status' => $subscription->status, 'trial_ends_at' => $subscription->trial_ends_at, 'charged_amount' => 0]]);
    }

    public function period(Request $request, Subscriptions $subscriptions): JsonResponse
    {
        $values = $request->validate(['period' => ['required', 'in:monthly,yearly'], 'expected_amount' => ['required', 'integer', 'min:1'], 'confirmed' => ['required', 'accepted']]);
        $subscription = $subscriptions->changePeriod($request->user(), $values['period'], $values['expected_amount']);

        return response()->json(['data' => ['status' => $subscription->status, 'period' => $subscription->billing_period, 'trial_ends_at' => $subscription->trial_ends_at]]);
    }

    public function cancelTrial(Request $request, Subscriptions $subscriptions): JsonResponse
    {
        $request->validate(['confirmed' => ['required', 'accepted']]);
        $subscriptions->cancelTrial($request->user());

        return $this->show($request, app(PlanCatalog::class), $subscriptions, app(Pipeline::class));
    }
}
