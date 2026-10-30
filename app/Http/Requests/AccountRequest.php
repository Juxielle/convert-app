<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => strtolower(trim($this->input('email')))]);
        }
    }

    public function rules(): array
    {
        $current = ['bail', 'required', 'string', 'max:128', 'current_password'];
        $password = ['bail', 'required', 'string', 'min:12', 'max:72', 'confirmed', function ($attribute, $value, $fail): void {
            if (strlen($value) > 72 || str_contains($value, "\0")) {
                $fail('Ce mot de passe est trop long ou contient un caractère non accepté.');
            }
        }];

        $rules = match ($this->route()->getName()) {
            'account.register' => ['name' => ['required', 'string', 'max:100'], 'email' => ['required', 'email', 'max:254', 'unique:users,email'], 'password' => $password, 'role' => ['prohibited'], 'plan' => ['prohibited']],
            'account.login' => ['email' => ['required', 'email', 'max:254'], 'password' => ['required', 'string', 'max:128']],
            'account.profile' => ['name' => ['required', 'string', 'max:100'], 'email' => ['required', 'email', 'max:254', Rule::unique('users')->ignore($this->user()->id)], 'current_password' => $current, 'role' => ['prohibited'], 'plan' => ['prohibited']],
            'account.password' => ['current_password' => $current, 'password' => $password],
            'account.forgot' => ['email' => ['required', 'email', 'max:254']],
            'account.reset' => ['email' => ['required', 'email', 'max:254'], 'token' => ['required', 'string', 'max:200'], 'password' => $password],
            default => [],
        };

        return [...$rules, 'current_subscription_id' => ['prohibited'], 'subscription_id' => ['prohibited'], 'plan_id' => ['prohibited'], 'payment_confirmed_at' => ['prohibited']];
    }

    public function messages(): array
    {
        return ['required' => 'Ce champ est obligatoire.', 'email' => 'Saisissez une adresse email valide.', 'unique' => 'Cette adresse email est déjà utilisée.',
            'password.min' => 'Choisissez au moins 12 caractères.', 'password.max' => 'Utilisez au maximum 72 caractères.', 'confirmed' => 'Les mots de passe ne correspondent pas.',
            'current_password' => 'Le mot de passe actuel est incorrect.', 'prohibited' => 'Ce réglage ne peut pas être modifié ici.', 'max' => 'Cette valeur est trop longue.'];
    }
}
