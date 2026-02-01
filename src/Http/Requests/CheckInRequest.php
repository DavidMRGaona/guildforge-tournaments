<?php

declare(strict_types=1);

namespace Modules\Tournaments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CheckInRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        // If user is authenticated, no email needed
        if ($this->user() !== null) {
            return [];
        }

        // If not authenticated, email and GDPR consent are required
        return [
            'email' => ['required', 'email', 'max:255'],
            'gdpr_consent' => ['required', 'accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.required' => __('validation.required', ['attribute' => 'email']),
            'email.email' => __('validation.email', ['attribute' => 'email']),
            'gdpr_consent.required' => __('tournaments::messages.validation.gdpr_consent_required'),
            'gdpr_consent.accepted' => __('tournaments::messages.validation.gdpr_consent_required'),
        ];
    }
}
