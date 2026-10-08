<?php

namespace App\Http\Requests\Api\V2;

/**
 * PUT /system/settings/plugins/{plugin} (E34 batch 6 spec §5.7).
 */
final class SetPluginRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** true installs the plugin, false removes it. */
            'enabled' => ['required', 'boolean'],
        ];
    }
}
