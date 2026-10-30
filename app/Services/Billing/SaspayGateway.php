<?php

namespace App\Services\Billing;

use App\Models\PaymentAttempt;
use App\Models\Subscription;
use App\Services\Conversions\PipelineLock;
use App\Services\Subscriptions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

final class SaspayGateway implements BillingGateway
{
    private const BASE_URL = 'https://api.saspay.me/api/v1';

    public function available(): bool
    {
        return str_starts_with((string) config('services.saspay.api_key'), 'sk_live_');
    }

    private function call(string $method, string $path, array $payload = [], ?string $idempotencyKey = null): array
    {
        abort_unless($this->available(), 503, 'SasPay n’est pas configuré.');
        try {
            $request = Http::withToken(config('services.saspay.api_key'))->acceptJson()->asJson()
                ->connectTimeout(5)->timeout(10)->withoutRedirecting();
            if ($idempotencyKey) {
                $request = $request->withHeaders(['Idempotency-Key' => $idempotencyKey]);
            }
            $response = $request->send($method, self::BASE_URL.$path, $method === 'POST' ? ['json' => $payload] : []);
        } catch (ConnectionException) {
            throw ValidationException::withMessages(['payment' => ['SasPay est momentanément injoignable. Réessayez la même demande ; aucun nouveau paiement ne sera créé.']]);
        }
        // Do not propagate provider bodies, request headers or secrets into logs/errors.
        if (! $response->successful() || $response->json('success') !== true || ! is_array($response->json('data'))) {
            throw ValidationException::withMessages(['payment' => ['SasPay n’a pas pu traiter la demande. Vérifiez votre compte marchand ou réessayez.']]);
        }

        return $response->json('data');
    }

    public function checkout(Subscription $subscription): string
    {
        abort_unless($subscription->status === 'pending_payment', 422);
        $attempt = app(PipelineLock::class)->run(function () use ($subscription) {
            $attempt = PaymentAttempt::firstOrCreate(['subscription_id' => $subscription->id]);
            if (! $attempt->request_payload) {
                $subscription->loadMissing('user', 'plan');
                $attempt->update(['request_payload' => [
                    'amount' => $subscription->price_amount.'.00',
                    'currency' => $subscription->currency,
                    'description' => 'Convert / '.$attempt->id,
                    'customer_email' => $subscription->user->email,
                    'customer_name' => $subscription->user->name,
                    'return_url' => rtrim(config('app.url'), '/').'/souscription?payment='.$subscription->id,
                    'metadata' => ['subscription_id' => (string) $subscription->id],
                ]]);
            }

            return $attempt;
        });
        if ($attempt->checkout_url) {
            return $attempt->checkout_url;
        }
        $data = $this->call('POST', '/checkout-sessions/', $attempt->request_payload, $attempt->id);
        $url = $data['checkout_url'] ?? '';
        abort_unless(is_string($url) && parse_url($url, PHP_URL_SCHEME) === 'https'
            && parse_url($url, PHP_URL_HOST) === 'pay.saspay.me'
            && ! parse_url($url, PHP_URL_USER) && ! parse_url($url, PHP_URL_PASS) && ! parse_url($url, PHP_URL_PORT)
            && is_string($data['id'] ?? null) && preg_match('/^[a-zA-Z0-9-]{1,100}$/D', $data['id']), 502, 'Réponse SasPay invalide.');
        $attempt->update(['session_id' => $data['id'], 'checkout_url' => $url]);

        return $url;
    }

    public function refresh(Subscription $subscription): Subscription
    {
        $attempt = PaymentAttempt::where('subscription_id', $subscription->id)->first();
        if (! $attempt?->session_id || $attempt->verified_at) {
            return $subscription->fresh();
        }
        $session = $this->call('GET', '/checkout-sessions/'.rawurlencode($attempt->session_id).'/status/');
        abort_unless(($session['id'] ?? null) === $attempt->session_id, 502);
        if (($session['status'] ?? null) !== 'PAID' || ($session['transaction_status'] ?? null) !== 'SUCCESS') {
            if (in_array($session['status'] ?? null, ['CANCELLED', 'EXPIRED'], true)) {
                $attempt->update(['status' => $session['status']]);
            }

            return $subscription->fresh();
        }
        $transactionId = $session['transaction_id'] ?? '';
        abort_unless(is_string($transactionId) && preg_match('/^[a-zA-Z0-9-]{1,100}$/D', $transactionId), 502);
        $transaction = $this->call('GET', '/payments/'.rawurlencode($transactionId).'/verify/');
        $this->validatePayment($subscription, $attempt, $transaction, $transactionId);

        return app(Subscriptions::class)->activatePayment($subscription->id, $attempt->id, $transactionId);
    }

    private function validatePayment(Subscription $subscription, PaymentAttempt $attempt, array $data, string $transactionId): void
    {
        $amount = (string) ($data['requested_amount'] ?? '');
        $expected = (string) $subscription->price_amount;
        abort_unless(($data['id'] ?? null) === $transactionId
            && ($data['status'] ?? null) === 'SUCCESS'
            && ($data['transaction_type'] ?? null) === 'PAIEMENT'
            && ($data['flow_direction'] ?? null) === 'INBOUND'
            && ($data['currency'] ?? null) === $subscription->currency
            && preg_match('/^'.preg_quote($expected, '/').'(?:\.0{1,2})?$/D', $amount)
            && ($data['description'] ?? null) === 'Convert / '.$attempt->id, 422, 'Le paiement SasPay ne correspond pas à cette souscription.');
    }

    public function webhook(Request $request): void
    {
        $secret = (string) config('services.saspay.webhook_secret');
        $timestamp = (string) $request->header('X-Webhook-Timestamp', '');
        $signature = (string) $request->header('X-Webhook-Signature', '');
        abort_unless($secret !== '', 503);
        abort_unless(ctype_digit($timestamp) && abs(now()->timestamp - (int) $timestamp) <= 300
            && hash_equals(hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret), $signature), 403);
        abort_unless($request->header('X-Webhook-Event') === $request->input('event'), 403);
        if ($request->input('event') !== 'transaction.success') {
            return;
        }
        $id = $request->input('data.id');
        abort_unless(is_string($id) && preg_match('/^[a-zA-Z0-9-]{1,100}$/D', $id), 422);
        $transaction = $this->call('GET', '/payments/'.rawurlencode($id).'/verify/');
        if (! preg_match('/^Convert \/ ([a-f0-9-]{36})$/D', $transaction['description'] ?? '', $match)) {
            return; // Another application on the same merchant account.
        }
        $attempt = PaymentAttempt::find($match[1]);
        if ($attempt && ! $attempt->verified_at) {
            $subscription = Subscription::findOrFail($attempt->subscription_id);
            $this->validatePayment($subscription, $attempt, $transaction, $id);
            $this->refresh($subscription); // Also checks that this transaction belongs to our checkout.
        }
    }
}
