<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AdminSettingsRequest extends FormRequest
{
    public function messages(): array
    {
        return ['array' => 'Ces paramètres ne sont pas autorisés.', 'integer' => 'Un nombre entier est attendu.',
            'between' => 'La valeur est hors des limites autorisées (:min à :max).', 'boolean' => 'Une activation ou désactivation est attendue.'];
    }

    public function authorize(): bool
    {
        return $this->user()?->can('admin') ?? false;
    }

    public function rules(): array
    {
        $tools = array_column(config('conversion.tools'), 'slug');
        $bounds = config('conversion.quota_bounds');
        $rules = ['limits' => ['sometimes', 'array:guest,registered'], 'tools' => ['sometimes', 'array:'.implode(',', $tools)]];
        foreach (['guest', 'registered'] as $role) {
            $rules['limits.'.$role] = ['sometimes', 'array:'.implode(',', array_keys($bounds))];
            foreach ($bounds as $key => [$min, $max]) {
                $rules['limits.'.$role.'.'.$key] = ['sometimes', ...($key === 'monthly_jobs' ? ['nullable'] : []), 'integer', 'between:'.$min.','.$max];
            }
        }
        foreach ($tools as $tool) {
            $rules['tools.'.$tool] = ['sometimes', 'boolean'];
        }

        return $rules;
    }
}
