<?php

namespace App\Http\Controllers;

use App\Http\Requests\AdminPlanRequest;
use App\Models\AuditLog;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\ToolSetting;
use App\Services\Conversions\PipelineLock;
use App\Services\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminPlanController extends Controller
{
    private function present(Plan $plan): array
    {
        return [...$plan->toArray(), 'quotas' => $plan->is_free ? app(Settings::class)->limits('registered') : $plan->quotas,
            'guest_limits' => $plan->is_free ? app(Settings::class)->limits('guest') : null];
    }

    public function index(): JsonResponse
    {
        return response()->json(['data' => ['plans' => Plan::orderBy('display_order')->orderBy('id')->get()->map(fn ($plan) => $this->present($plan)),
            'quota_bounds' => config('conversion.quota_bounds'), 'tools' => collect(config('conversion.tools'))->map(fn ($tool) => ['slug' => $tool['slug'], 'name' => $tool['name']])]]);
    }

    public function subscriptions(Request $request): JsonResponse
    {
        $request->validate(['status' => ['sometimes', 'in:trialing,active,expired,canceled,pending_payment']]);
        $query = Subscription::with('plan:id,name')->latest('id');
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        return response()->json($query->paginate(20, ['id', 'user_id', 'plan_id', 'status', 'starts_at', 'ends_at', 'trial_ends_at', 'billing_period', 'price_amount', 'currency', 'created_at']));
    }

    public function store(AdminPlanRequest $request, PipelineLock $lock): JsonResponse
    {
        return $this->save($request, new Plan, $lock)->setStatusCode(201);
    }

    public function update(AdminPlanRequest $request, Plan $plan, PipelineLock $lock): JsonResponse
    {
        return $this->save($request, $plan, $lock);
    }

    private function save(AdminPlanRequest $request, Plan $plan, PipelineLock $lock): JsonResponse
    {
        $lock->run(function () use ($request, $plan): void {
            if ($plan->exists) {
                $plan->refresh();
            }
            $before = $plan->exists ? $this->present($plan) : null;
            $values = $request->validated();
            unset($values['guest_limits']);
            if ($plan->is_free) {
                ToolSetting::updateOrCreate(['key' => 'limits.registered'], ['value' => $values['quotas']]);
                if ($request->has('guest_limits')) {
                    ToolSetting::updateOrCreate(['key' => 'limits.guest'], ['value' => $request->validated('guest_limits')]);
                }
                $values['quotas'] = null; // Free limits keep their existing central source of truth.
            }
            $plan->fill($values)->save();
            AuditLog::create(['user_id' => $request->user()->id, 'action' => $before ? 'plan.updated' : 'plan.created', 'target' => $plan->slug,
                'metadata' => ['before' => $before, 'after' => $this->present($plan)], 'created_at' => now()]);
        });

        return response()->json(['data' => $this->present($plan)]);
    }
}
