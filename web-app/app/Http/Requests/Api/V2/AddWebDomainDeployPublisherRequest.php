<?php

namespace App\Http\Requests\Api\V2;

use App\Services\Deploy\DeployFormat;
use Closure;
use Illuminate\Validation\Rule;

/**
 * POST /users/{user}/web-domains/{domain}/deploy/publishers (E20 §8): a
 * trusted publisher of push mode. The issuer must be one the server allows
 * (`DEPLOY_OIDC_ISSUERS`), which the command checks.
 */
final class AddWebDomainDeployPublisherRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return self::publisherRules('', fn () => true);
    }

    /**
     * The rules of a publisher under $prefix, required when $present says so.
     *
     * @param  Closure(): bool  $present
     * @return array<string, mixed>
     */
    public static function publisherRules(string $prefix, Closure $present): array
    {
        $required = Rule::requiredIf($present);

        return [
            /** The OIDC issuer: `https://token.actions.githubusercontent.com`, `https://gitlab.com` or one the administrator added. */
            $prefix.'issuer' => [$required, 'string', 'max:200', 'regex:/^https:\/\/[A-Za-z0-9.-]+(:[0-9]{1,5})?(\/[A-Za-z0-9._~\/-]*)?$/'],
            /** The repository: GitHub `owner/name`, GitLab `group/project`. */
            $prefix.'repository' => [$required, 'string', 'max:200', ConfigureWebDomainDeployRequest::check(DeployFormat::repository(...), __('Enter the repository as owner/name.'))],
            /** The branch (`main`, `refs/heads/main`) or tag (`refs/tags/v1`); this or `environment` is required. */
            $prefix.'ref' => [
                Rule::requiredIf(fn () => $present() && blank(request()->input($prefix.'environment'))), 'nullable', 'string', 'max:200',
                ConfigureWebDomainDeployRequest::check(DeployFormat::publisherRef(...), __('Enter a branch name, refs/heads/… or refs/tags/….')),
            ],
            /** The deployment environment of the CI job; this or `ref` is required. */
            $prefix.'environment' => ['nullable', 'string', 'max:100', ConfigureWebDomainDeployRequest::check(DeployFormat::environment(...), __('Enter letters, digits, spaces, dots, dashes or underscores.'))],
            /** GitHub: the workflow file that may deploy, such as `.github/workflows/deploy.yml`. */
            $prefix.'workflow' => ['nullable', 'string', 'max:200', ConfigureWebDomainDeployRequest::check(DeployFormat::workflow(...), __('Enter the path of a .yml or .yaml workflow file.'))],
        ];
    }

    /**
     * The publisher as DeployService takes it.
     *
     * @return array{issuer: string, repo: string, ref: string, environment: string, workflow: string}
     */
    public function publisher(): array
    {
        return self::publisherOf((array) $this->validated());
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{issuer: string, repo: string, ref: string, environment: string, workflow: string}
     */
    public static function publisherOf(array $data): array
    {
        return [
            'issuer' => (string) ($data['issuer'] ?? ''),
            'repo' => (string) ($data['repository'] ?? ''),
            'ref' => (string) ($data['ref'] ?? ''),
            'environment' => (string) ($data['environment'] ?? ''),
            'workflow' => (string) ($data['workflow'] ?? ''),
        ];
    }
}
