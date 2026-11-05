<?php

namespace App\Http\Requests\Api\V2;

use App\Services\Alerts\AlertStore;
use Illuminate\Validation\Rule;

/** Body of `createAlertChannel` and `createSystemAlertChannel` (E16 spec §8). */
final class CreateAlertChannelRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:64', 'regex:/^[^\'"$`\\\\\x00-\x1f]+$/'],
            'type' => ['required', Rule::in(AlertStore::CHANNEL_TYPES)],
            'target' => ['sometimes', 'nullable', 'string', 'max:512'],
            /** The bot token or webhook URL; write-only. */
            'secret' => [Rule::requiredIf(in_array($this->input('type'), AlertStore::SECRET_TYPES, true)), 'nullable', 'string', 'max:4096'],
        ];
    }
}
