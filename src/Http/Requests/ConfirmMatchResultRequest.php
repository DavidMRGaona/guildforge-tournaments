<?php

declare(strict_types=1);

namespace Modules\Tournaments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The opponent accepts the report they were shown (its fingerprint).
 */
final class ConfirmMatchResultRequest extends FormRequest
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
        ];
    }
}
