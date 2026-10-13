<?php

namespace App\Http\Requests\Api\V2;

/**
 * PUT /system/php/versions/{php}/ini/{sapi} (E41 §6, §9): the whole file.
 */
final class ReplacePhpIniRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** The new php.ini, at most 1 MiB. */
            'content' => ['present', 'nullable', 'string', 'max:1048576'],
        ];
    }

    protected function multiLineFields(): array
    {
        return ['content'];
    }
}
