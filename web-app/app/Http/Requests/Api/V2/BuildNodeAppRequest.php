<?php

namespace App\Http\Requests\Api\V2;

use App\Services\Node\NodeAppService;

final class BuildNodeAppRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'script' => ['nullable', 'string', 'regex:'.NodeAppService::SCRIPT],
        ];
    }
}
