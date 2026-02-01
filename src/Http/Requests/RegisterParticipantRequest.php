<?php

declare(strict_types=1);

namespace Modules\Tournaments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class RegisterParticipantRequest extends FormRequest
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
        // If unauthenticated, require guest data and GDPR consent
        if ($this->user() === null) {
            return [
                'guest_name' => ['required', 'string', 'max:255'],
                'guest_email' => ['required', 'email', 'max:255'],
                'gdpr_consent' => ['required', 'accepted'],
            ];
        }

        return [
            'guest_name' => ['nullable', 'string', 'max:255'],
            'guest_email' => ['nullable', 'email', 'max:255', 'required_with:guest_name'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'guest_email.required_with' => __('tournaments::messages.errors.guest_email_required'),
            'gdpr_consent.required' => __('tournaments::messages.validation.gdpr_consent_required'),
            'gdpr_consent.accepted' => __('tournaments::messages.validation.gdpr_consent_required'),
        ];
    }
}
