<?php

namespace App\Http\Requests\Api\V2;

/**
 * POST /system/restart (E34 batch 6 spec D61): the host name of the server
 * confirms the restart. The controller compares it with the server's own.
 */
final class RestartServerRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** The host name of the server, as `meta.hostname` of listServices names it. */
            'confirm_hostname' => ['required', 'string', 'max:255'],
        ];
    }
}
