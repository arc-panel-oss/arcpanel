<?php

namespace App\Http\Requests\Api\V2;

use App\Services\Server\PanelUpdates;
use App\Services\Server\UpdateCenter;
use Illuminate\Validation\Rule;

/**
 * POST /system/updates (E34 batch 6 spec §5.4; `version` E33 phase 1 spec §9).
 */
final class RunUpdatesRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** The package to update; every package when left out. */
            'package' => ['nullable', 'string', Rule::in(PanelUpdates::PACKAGES), 'prohibits:version'],
            /** A release of listPanelReleases: the update task with pre-flight, health check and rollback. */
            'version' => ['nullable', 'string', 'regex:/^'.UpdateCenter::VERSION_PATTERN.'$/'],
            /** Required as `true` for a release that needs attention: the administrator has read its `attention_items`. */
            'confirm_attention' => ['sometimes', 'boolean'],
        ];
    }
}
