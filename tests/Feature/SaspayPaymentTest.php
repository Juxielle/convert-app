<?php

namespace Tests\Feature;

use App\Models\PaymentAttempt;
use App\Models\Subscription;
use App\Models\SubscriptionEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SaspayPaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        config(['services.saspay.api_key' => 'sk_live_fake_for_tests', 'services.saspay.webhook_secret' => 'test-signing-secret']);
        Http::preventStrayRequests();
    }

    private function fake(mixed $callback): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::preventStrayRequests();
        Http::fake($callback);
    }

    private function quote(string $mode = 'pay'): string
    {
        return $this->postJson('/api/v1/subscriptions/quote', ['plan' => 'pro', 'period' => 'monthly', 'mode' => $mode, 'payment_method' => 'card'])
            ->assertOk()->json('data.id');
    }

    private function fakeCheckout(): void
    {
        $this->fake(['*/checkout-sessions/' => Http::response(['success' => true, 'data' => ['id' => 'session-1', 'checkout_url' => 'https://pay.saspay.me/checkout/example']], 201)]);
    }

    private function start(): Subscription
    {
        $this->actingAs(User::factory()->create());
        $this->fakeCheckout();
        $response = $this->postJson('/api/v1/subscriptions/checkout', ['quote_id' => $this->quote(), 'confirmed' => true])->assertOk();

        return Subscription::findOrFail($response->json('data.id'));
    }

    private function transaction(Subscription $subscription, array $overrides = []): array
    {
        $attempt = PaymentAttempt::where('subscription_id', $subscription->id)->firstOrFail();

        return ['id' => 'transaction-1', 'status' => 'SUCCESS', 'transaction_type' => 'PAIEMENT', 'flow_direction' => 'INBOUND',
            'requested_amount' => '3900.00', 'currency' => 'XAF', 'description' => 'Convert / '.$attempt->id, ...$overrides];
    }

    private function fakeVerification(Subscription $subscription, array $overrides = []): void
    {
        $this->fake([
            '*/checkout-sessions/session-1/status/' => Http::response(['success' => true, 'data' => ['id' => 'session-1', 'status' => 'PAID', 'transaction_id' => 'transaction-1', 'transaction_status' => 'SUCCESS']]),
            '*/payments/transaction-1/verify/' => Http::response(['success' => true, 'data' => $this->transaction($subscription, $overrides)]),
        ]);
    }

    public function test_checkout_uses_server_price_and_reuses_the_session_without_granting_access(): void
    {
        $this->actingAs($user = User::factory()->create());
        $this->fakeCheckout();
        $quote = $this->quote();
        $payload = ['quote_id' => $quote, 'confirmed' => true, 'amount' => 1, 'status' => 'SUCCESS'];
        $first = $this->postJson('/api/v1/subscriptions/checkout', $payload)->assertOk()->assertJsonPath('data.status', 'pending_payment')
            ->assertJsonPath('data.checkout_url', 'https://pay.saspay.me/checkout/example');
        $this->postJson('/api/v1/subscriptions/checkout', $payload)->assertOk()->assertJsonPath('data.id', $first->json('data.id'));
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request['amount'] === '3900.00' && $request['currency'] === 'XAF'
            && $request->hasHeader('Idempotency-Key', PaymentAttempt::first()->id)
            && $request['return_url'] === config('app.url').'/souscription?payment='.$first->json('data.id'));
        $this->assertSame('free', $user->fresh()->plan);
        $this->assertNull(Subscription::find($first->json('data.id'))->payment_confirmed_at);
    }

    public function test_verified_payment_activates_once_and_does_not_depend_on_the_api_key_afterwards(): void
    {
        $subscription = $this->start();
        $this->fakeVerification($subscription);
        $url = '/api/v1/subscriptions/'.$subscription->id.'/payment-status';
        $this->getJson($url)->assertOk()->assertJsonPath('data.payment_confirmed', true)->assertJsonPath('data.status', 'active');
        $end = $subscription->fresh()->ends_at;
        $this->getJson($url)->assertOk();
        $this->assertTrue($end->equalTo($subscription->fresh()->ends_at));
        $this->assertSame(1, SubscriptionEvent::where('subscription_id', $subscription->id)->where('type', 'payment_confirmed')->count());
        config(['services.saspay.api_key' => '']);
        $this->getJson('/api/v1/me/subscription')->assertOk()->assertJsonPath('data.plan.id', 'pro');
    }

    public function test_mismatched_amount_currency_direction_or_checkout_reference_never_activates_access(): void
    {
        $subscription = $this->start();
        foreach ([['requested_amount' => '1.00'], ['currency' => 'XOF'], ['flow_direction' => 'OUTBOUND'], ['description' => 'another order'], ['status' => 'PENDING']] as $override) {
            $this->fakeVerification($subscription, $override);
            $this->getJson('/api/v1/subscriptions/'.$subscription->id.'/payment-status')->assertUnprocessable();
            $this->assertNull($subscription->fresh()->payment_confirmed_at);
        }
    }

    public function test_payment_status_is_private_and_pending_returns_do_not_claim_payment(): void
    {
        $subscription = $this->start();
        $this->fake(['*/checkout-sessions/session-1/status/' => Http::response(['success' => true, 'data' => ['id' => 'session-1', 'status' => 'PENDING']])]);
        $this->getJson('/api/v1/subscriptions/'.$subscription->id.'/payment-status?status=SUCCESS')->assertOk()->assertJsonPath('data.payment_confirmed', false);
        $this->actingAs(User::factory()->create())->getJson('/api/v1/subscriptions/'.$subscription->id.'/payment-status')->assertNotFound();
    }

    public function test_timed_out_checkout_retries_the_identical_payload_and_idempotency_key(): void
    {
        $this->actingAs($user = User::factory()->create());
        $quote = $this->quote();
        $this->fake(fn () => throw new ConnectionException('timeout'));
        $payload = ['quote_id' => $quote, 'confirmed' => true];
        $this->postJson('/api/v1/subscriptions/checkout', $payload)->assertUnprocessable();
        $attempt = PaymentAttempt::firstOrFail();
        $user->update(['name' => 'Changed name']);
        $this->fakeCheckout();
        $this->postJson('/api/v1/subscriptions/checkout', $payload)->assertOk();
        Http::assertSent(fn (Request $request) => $request->data() === $attempt->request_payload
            && $request->hasHeader('Idempotency-Key', $attempt->id));
        $this->assertDatabaseCount('payment_attempts', 1);
    }

    public function test_webhooks_verify_signature_age_and_payment_and_are_idempotent(): void
    {
        $subscription = $this->start();
        $this->fakeVerification($subscription);
        $body = json_encode(['event' => 'transaction.success', 'data' => ['id' => 'transaction-1']]);
        $url = '/api/v1/billing/saspay/webhook';
        $send = function (int $time, string $signature) use ($body, $url) {
            return $this->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_WEBHOOK_TIMESTAMP' => (string) $time, 'HTTP_X_WEBHOOK_EVENT' => 'transaction.success', 'HTTP_X_WEBHOOK_SIGNATURE' => $signature], $body);
        };
        $time = now()->timestamp;
        $send($time, 'invalid')->assertForbidden();
        $old = $time - 301;
        $send($old, hash_hmac('sha256', $old.'.'.$body, 'test-signing-secret'))->assertForbidden();
        $signature = hash_hmac('sha256', $time.'.'.$body, 'test-signing-secret');
        $send($time, $signature)->assertOk();
        $send($time, $signature)->assertOk();
        $this->assertSame('active', $subscription->fresh()->status);
        $this->assertSame(1, SubscriptionEvent::where('subscription_id', $subscription->id)->where('type', 'payment_confirmed')->count());
    }

    public function test_live_trial_cannot_be_activated_without_a_supported_payment_setup(): void
    {
        $this->actingAs(User::factory()->create());
        $quote = $this->quote('trial');
        $this->postJson('/api/v1/subscriptions/trial', ['quote_id' => $quote, 'confirmed' => true])->assertUnprocessable();
        config(['services.saspay.api_key' => '']);
        $this->postJson('/api/v1/subscriptions/trial', ['quote_id' => $quote, 'confirmed' => true])->assertUnprocessable();
        $this->assertDatabaseCount('trial_grants', 0);
        Http::assertNothingSent();
    }

    public function test_late_payment_on_a_canceled_request_is_recorded_for_review_without_changing_access(): void
    {
        $subscription = $this->start();
        $subscription->update(['status' => 'canceled', 'cancelled_at' => now()]);
        $this->fakeVerification($subscription);
        $this->getJson('/api/v1/subscriptions/'.$subscription->id.'/payment-status')->assertOk()->assertJsonPath('data.review_required', true);
        $this->assertSame('free', $subscription->user->fresh()->plan);
        $this->assertNotNull(PaymentAttempt::first()->verified_at);
        $this->assertSame('canceled', $subscription->fresh()->status);
    }

    public function test_invalid_redirects_and_provider_errors_are_not_exposed(): void
    {
        $this->actingAs(User::factory()->create());
        $quote = $this->quote();
        $this->fake(['*' => Http::response(['success' => true, 'data' => ['id' => 'session-1', 'checkout_url' => 'https://attacker.example/']], 201)]);
        $this->postJson('/api/v1/subscriptions/checkout', ['quote_id' => $quote, 'confirmed' => true])->assertStatus(502);
        $this->fake(['*' => Http::response(['error' => 'provider-secret-details'], 401)]);
        $this->postJson('/api/v1/subscriptions/checkout', ['quote_id' => $quote, 'confirmed' => true])->assertUnprocessable()->assertDontSee('provider-secret-details');
        $this->assertNull(PaymentAttempt::first()->session_id);
    }
}
