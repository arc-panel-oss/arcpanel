<?php

namespace App\Http\Requests\Api\V2;

/**
 * PUT /system/updates/settings (E34 batch 6 spec §5.4): fields left out keep
 * their value.
 */
final class SetUpdateSettingsRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** Update the panel packages every day (a cron job of the administrator). */
            'auto_update' => ['sometimes', 'boolean'],
            /** Send an e-mail to the administrator after an update. */
            'send_email' => ['sometimes', 'boolean'],
            /** Attach the log of the update to that e-mail. */
            'send_log' => ['sometimes', 'boolean'],
        ];
    }
}
