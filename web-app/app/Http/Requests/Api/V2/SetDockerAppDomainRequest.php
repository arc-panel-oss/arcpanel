<?php

namespace App\Http\Requests\Api\V2;

use App\Services\Docker\DockerAppActions;

final class SetDockerAppDomainRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return app(DockerAppActions::class)->domainRules();
    }
}
