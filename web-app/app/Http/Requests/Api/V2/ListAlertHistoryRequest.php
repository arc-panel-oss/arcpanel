<?php

namespace App\Http\Requests\Api\V2;

use App\Services\Alerts\AlertStore;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Query parameters of `listAlertHistory` and `listSystemAlertHistory` (E16 spec §8). */
final class ListAlertHistoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'rule' => ['sometimes', 'string', 'regex:/^[a-z0-9.-]{1,64}$/'],
            'severity' => ['sometimes', Rule::in(AlertStore::SEVERITIES)],
            /** YYYY-MM-DD or a date and time, server time. */
            'since' => ['sometimes', 'date'],
        ];
    }
}
