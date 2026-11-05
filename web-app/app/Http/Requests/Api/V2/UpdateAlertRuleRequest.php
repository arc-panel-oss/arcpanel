<?php

namespace App\Http\Requests\Api\V2;

use App\Services\Alerts\AlertStore;
use Illuminate\Validation\Rule;

/** Body of `updateAlertRule` and `updateSystemAlertRule` (E16 spec §8); fields left out keep their value. */
final class UpdateAlertRuleRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'enabled' => ['sometimes', 'boolean'],
            /** The warning threshold. */
            'warning' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999999'],
            /** The critical threshold; null for none on a rule with one level. */
            'critical' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999999'],
            'for' => ['sometimes', 'integer', 'min:0', 'max:1440'],
            'severity' => ['sometimes', Rule::in(AlertStore::SEVERITIES)],
            'cooldown' => ['sometimes', 'integer', 'min:1', 'max:43200'],
            'channels' => ['sometimes', 'array', 'max:20'],
            'channels.*' => ['string', 'regex:/^\d{14}$/'],
            'options' => ['sometimes', 'nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9_.\/@,-]*$/'],
        ];
    }
}
