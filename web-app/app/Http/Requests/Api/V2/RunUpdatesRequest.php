<?php

namespace App\Http\Requests\Api\V2;

use App\Services\Server\PanelUpdates;
use Illuminate\Validation\Rule;

/**
 * POST /system/updates (E34 batch 6 spec §5.4).
 */
final class RunUpdatesRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** The package to update; every package when left out. */
            'package' => ['nullable', 'string', Rule::in(PanelUpdates::PACKAGES)],
        ];
    }
}
