<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class AdminPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('admin') ?? false;
    }

    public function rules(): array
    {
        $free = $this->route('plan')?->is_free ?? false;
        $bounds = config('conversion.quota_bounds');
        $rules = [
            'slug' => $this->isMethod('post') ? ['required', 'string', 'regex:/^[a-z][a-z0-9-]{1,59}$/', 'not_in:free,guest,premium', 'unique:plans,slug'] : ['prohibited'],
            'name' => ['required', 'string', 'max:100'], 'description' => ['required', 'string', 'max:2000'],
            'active' => ['required', 'boolean', ...($free ? ['accepted'] : [])], 'currency' => ['required', Rule::in(['XAF'])],
            'monthly_price' => ['present', ...($free ? ['integer', 'in:0'] : ['nullable', 'integer', 'min:1', 'max:1000000000'])],
            'yearly_price' => ['present', ...($free ? ['integer', 'in:0'] : ['nullable', 'integer', 'min:1', 'max:1000000000'])],
            'billing_periods' => ['present', 'array', 'max:2', ...($free ? ['size:0'] : ['min:1'])], 'billing_periods.*' => ['required', 'distinct', Rule::in(['monthly', 'yearly'])],
            'features' => ['present', 'array', 'max:20'], 'features.*' => ['required', 'string', 'distinct', 'max:200'],
            'allowed_tools' => ['present', 'nullable', 'array', 'max:50'], 'allowed_tools.*' => ['required', 'distinct', Rule::in(array_column(config('conversion.tools'), 'slug'))],
            'quotas' => ['present', 'nullable', 'array:'.implode(',', array_keys($bounds)), 'size:'.count($bounds)],
            'guest_limits' => $free ? ['sometimes', 'array:'.implode(',', array_keys($bounds)), 'size:'.count($bounds)] : ['prohibited'],
            'display_order' => ['required', 'integer', 'between:0,1000'], 'is_free' => ['prohibited'],
            'trial_days' => ['sometimes', 'integer', 'between:'.($free ? '0,0' : '1,60')],
            'trial_enabled' => ['sometimes', 'boolean', ...($free ? ['declined'] : [])],
            'entitlements' => ['sometimes', 'array:'.implode(',', config('billing.entitlements')), 'size:'.count(config('billing.entitlements'))],
        ];
        foreach (config('billing.entitlements') as $feature) {
            $rules['entitlements.'.$feature] = ['required_with:entitlements', 'boolean'];
        }
        foreach ($bounds as $key => [$min, $max]) {
            $rules['quotas.'.$key] = [$free && $key === 'monthly_jobs' ? 'nullable' : 'required_with:quotas', 'integer', 'between:'.$min.','.$max];
            if ($free) {
                $rules['guest_limits.'.$key] = [$key === 'monthly_jobs' ? 'nullable' : 'required_with:guest_limits', 'integer', 'between:'.$min.','.$max];
            }
        }

        return $rules;
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            foreach (['quotas', 'guest_limits'] as $key) {
                $values = $this->input($key);
                if ($values && ($values['hourly_jobs'] > $values['daily_jobs'] || $values['storage_bytes'] < $values['max_file_bytes'])) {
                    $validator->errors()->add($key, 'Le quota journalier doit couvrir le quota horaire et le stockage doit couvrir un fichier.');
                }
            }
            if ($this->route('plan')?->is_free && ! $this->input('quotas')) {
                $validator->errors()->add('quotas', 'Les quotas gratuits doivent rester définis.');
            }
            $plan = $this->route('plan');
            if (! $plan?->is_free && $this->input('trial_enabled', $plan?->trial_enabled ?? false) && ! $this->input('quotas')) {
                $validator->errors()->add('quotas', 'Définissez des quotas complets avant d’activer les essais.');
            }
        }];
    }
}
