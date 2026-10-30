<?php

namespace App\Http\Requests\Api\V2;

use App\Services\Deploy\DeployFormat;

/**
 * POST /users/{user}/web-domains/{domain}/deploy/runs (E20 §8): a deploy of
 * pull mode, of the branch or of one commit or ref.
 */
final class RunWebDomainDeployRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** A commit (40 hex digits) or a ref name to deploy instead of the head of the branch. */
            'ref' => ['sometimes', 'nullable', 'string', 'max:200', ConfigureWebDomainDeployRequest::check(DeployFormat::revision(...), __('Enter a commit or a branch name.'))],
        ];
    }
}
