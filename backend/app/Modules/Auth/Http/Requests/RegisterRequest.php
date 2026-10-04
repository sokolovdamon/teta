<?php

namespace App\Modules\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'email' => ['required', 'email:rfc', 'max:255', Rule::unique('users', 'email')->whereNull('deleted_at')],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
            'name' => ['required', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            // DEC-49: registration and booking only for adults.
            'birth_date' => ['required', 'date', 'before_or_equal:'.now()->subYears(18)->toDateString(), 'after:1900-01-01'],
            'timezone' => ['nullable', 'timezone:all'],
            'role' => ['nullable', Rule::in(['client', 'psychologist'])],
            // Separate consent to personal data processing, not hidden inside the offer.
            'accept_personal_data' => ['accepted'],
            'accept_terms' => ['accepted'],
            'marketing_opt_in' => ['nullable', 'boolean'],
            'referral_code' => ['nullable', 'string', 'max:64'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => 'Пользователь с таким email уже зарегистрирован.',
            'birth_date.before_or_equal' => 'Платформа работает только с совершеннолетними клиентами (18+).',
            'accept_personal_data.accepted' => 'Нужно согласие на обработку персональных данных.',
            'accept_terms.accepted' => 'Нужно принять условия пользовательского соглашения.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('email')) {
            $this->merge(['email' => mb_strtolower(trim((string) $this->input('email')))]);
        }
    }
}
