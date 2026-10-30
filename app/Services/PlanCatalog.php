<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\User;
use App\Services\Conversions\EngineRegistry;
use App\Services\Conversions\PipelineException;
use App\Services\Billing\BillingGateway;

class PlanCatalog
{
    public function plan(?User $user): Plan
    {
        return $user ? app(Subscriptions::class)->current($user)->plan : Plan::where('slug', 'free')->firstOrFail();
    }

    public function limits(?User $user): array
    {
        if (! $user) {
            return app(Settings::class)->limits('guest');
        }
        $plan = $this->plan($user);

        return $plan->is_free ? app(Settings::class)->limits('registered') : $plan->quotas;
    }

    public function authorizeTool(?User $user, string $tool): void
    {
        $plan = $this->plan($user);
        if (! $plan->active || ($plan->allowed_tools !== null && ! in_array($tool, $plan->allowed_tools, true))) {
            throw new PipelineException('TOOL_NOT_INCLUDED', 403);
        }
        if (in_array($tool, config('billing.advanced_tools'), true) && ! ($plan->entitlements['advanced_conversions'] ?? false)) {
            throw new PipelineException('TOOL_NOT_INCLUDED', 403);
        }
    }

    public function publicPlans(): array
    {
        return Plan::where('active', true)->orderBy('display_order')->orderBy('id')->get()->map(fn (Plan $plan) => $this->present($plan))->all();
    }

    public function present(Plan $plan): array
    {
        $labels = config('billing.feature_labels');
        $features = array_values(array_diff($plan->features, array_values($labels)));
        foreach ($labels as $key => $label) {
            if ($plan->entitlements[$key] ?? false) {
                $features[] = $label;
            }
        }
        $tools = [];
        foreach (config('conversion.tools') as $tool) {
            if (in_array($tool['slug'], config('billing.advanced_tools'), true) && ! ($plan->entitlements['advanced_conversions'] ?? false)) {
                continue;
            }
            if ($plan->allowed_tools !== null && ! in_array($tool['slug'], $plan->allowed_tools, true)) {
                continue;
            }
            try {
                app(EngineRegistry::class)->get($tool['slug']);
                $tools[] = ['slug' => $tool['slug'], 'name' => $tool['name']];
            } catch (PipelineException) {
                // Entitlements never activate an unverified engine.
            }
        }

        $paymentAvailable = app(BillingGateway::class)->available();

        return ['payment_available' => $paymentAvailable, 'trial_payment_setup_available' => false, 'trial_requires_payment' => config('billing.trial_requires_payment'),
            'id' => $plan->slug, 'name' => $plan->name, 'description' => $plan->description,
            'is_free' => $plan->is_free, 'active' => $plan->active, 'currency' => $plan->currency, 'currency_label' => 'FCFA',
            'monthly_price' => $plan->monthly_price, 'yearly_price' => $plan->yearly_price, 'billing_periods' => $plan->billing_periods,
            'features' => $features, 'limits' => $plan->is_free ? app(Settings::class)->limits('registered') : $plan->quotas,
            'guest_limits' => $plan->is_free ? app(Settings::class)->limits('guest') : null,
            'tools' => $tools, 'tool_access_defined' => true,
            'trial_days' => $plan->trial_days, 'trial_enabled' => $plan->trial_enabled, 'entitlements' => $plan->entitlements ?? [],
            'annual_savings' => $plan->monthly_price && $plan->yearly_price ? max(0, $plan->monthly_price * 12 - $plan->yearly_price) : 0,
            'annual_free_months' => $plan->monthly_price && $plan->yearly_price ? max(0, round(12 - $plan->yearly_price / $plan->monthly_price, 2)) : 0,
            'trial_policy' => config('billing.trial_policy'), 'monthly_window_days' => config('billing.monthly_window_days'),
            'display_order' => $plan->display_order, 'available' => $plan->is_free || ($plan->trial_enabled && $plan->trial_days > 0 && $plan->quotas !== null),
            'status' => $plan->is_free ? 'free' : ($plan->trial_enabled ? 'trial_available' : 'coming_soon')];
    }
}
