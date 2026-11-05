<?php

namespace App\Http\Requests\Api\V2;

/** Body of `updateAlertChannel` and `updateSystemAlertChannel` (E16 spec §8). */
final class UpdateAlertChannelRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:64', 'regex:/^[^\'"$`\\\\\x00-\x1f]+$/'],
            'enabled' => ['required', 'boolean'],
            'target' => ['sometimes', 'nullable', 'string', 'max:512'],
            /** A new bot token or webhook URL; write-only. */
            'secret' => ['sometimes', 'nullable', 'string', 'max:4096'],
        ];
    }
}
