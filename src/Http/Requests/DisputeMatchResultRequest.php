<?php

declare(strict_types=1);

namespace Modules\Tournaments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The opponent rejects the report they were shown, saying why.
 */
final class DisputeMatchResultRequest extends FormRequest
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
        return [
            'report' => ['required', 'string', 'max:64'],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'reason' => (string) __('tournaments::messages.fields.dispute_reason'),
        ];
    }
}
