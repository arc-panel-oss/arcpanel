<?php

namespace App\Http\Requests\Api\V2;

/**
 * PUT /system/settings/web/php (E34 batch 6 spec §5.7): the full list of
 * PHP versions the server should have.
 */
final class SetPhpVersionsRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** Every PHP version to keep or install; an installed version left out is removed. */
            'versions' => ['present', 'array', 'max:20'],
            'versions.*' => ['string', 'distinct', 'regex:/^\d\.\d$/'],
        ];
    }
}
