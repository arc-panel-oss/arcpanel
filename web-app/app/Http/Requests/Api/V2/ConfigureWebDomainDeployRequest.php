<?php

namespace App\Http\Requests\Api\V2;

use App\Services\Deploy\DeployFormat;
use App\Services\Deploy\DeployService;
use Closure;

/**
 * PUT /users/{user}/web-domains/{domain}/deploy (E20 §8): turn Git Deploy on
 * (`mode` and its settings) or change the settings of a deploy that is on.
 * Fields left out stay; the format checks are those of the commands (§4.1),
 * the checks that depend on the mode are made by the controller.
 */
final class ConfigureWebDomainDeployRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** `pull` (the server fetches and builds) or `push` (a CI uploads a built archive); needed to turn deploy on, and cannot change while it is on. */
            'mode' => ['sometimes', 'string', 'in:pull,push'],
            /** Pull mode: the repository, `git@host:path`, `ssh://…` or `https://…`. */
            'url' => ['sometimes', 'string', 'max:500', self::check(DeployFormat::url(...), __('Enter a repository URL such as git@github.com:owner/repo.git or https://….'))],
            /** Pull mode: the branch to deploy (`main` when turning on). */
            'branch' => ['sometimes', 'string', 'max:200', self::check(DeployFormat::ref(...), __('This is not a valid branch name.'))],
            /** Pull mode: the build preset (`none` when turning on). */
            'preset' => ['sometimes', 'string', 'in:'.implode(',', DeployService::PRESETS)],
            /** Pull mode: `key` (a deploy key is created and its public part answered once) or `token` (an HTTPS token, with an `https://` URL). */
            'auth' => ['sometimes', 'string', 'in:key,token'],
            /** Pull mode: the HTTPS token; stored in the secret store, never answered. */
            'token' => ['sometimes', 'string', 'max:4096'],
            /** `.` or the folder of the release served as the document root (`public`, `dist`). */
            'webroot' => ['sometimes', 'string', 'max:200', self::check(DeployFormat::webroot(...), __('Enter . or a folder inside the release.'))],
            /** Paths linked from `shared/` into every release (a trailing `/` for a folder), at most 20. */
            'shared' => ['sometimes', 'array', 'max:20'],
            'shared.*' => ['string', 'max:200', self::check(DeployFormat::sharedEntry(...), __('Enter a path inside the release.'))],
            /** Releases kept, 1–20. */
            'keep' => ['sometimes', 'integer', 'min:1', 'max:20'],
            /** Pull mode with the laravel preset: run the migrations before the switch. */
            'migrate' => ['sometimes', 'boolean'],
            /** A path checked after the switch (a failure rolls back), or null for none. */
            'health_path' => ['sometimes', 'nullable', 'string', 'max:200', self::check(DeployFormat::healthPath(...), __('Enter a path that starts with /.'))],
            /** Push mode, when turning on: the first trusted publisher. */
            'publisher' => ['sometimes', 'nullable', 'array'],
            ...AddWebDomainDeployPublisherRequest::publisherRules('publisher.', fn () => is_array($this->input('publisher'))),
        ];
    }

    /**
     * The settings of the request as v-change-web-domain-deploy keys and
     * values, in the order of DeployService::SETTINGS.
     *
     * @return array<string, string>
     */
    public function settings(): array
    {
        $values = [
            'URL' => $this->has('url') ? (string) $this->input('url') : null,
            'BRANCH' => $this->has('branch') ? (string) $this->input('branch') : null,
            'PRESET' => $this->has('preset') ? (string) $this->input('preset') : null,
            'WEBROOT' => $this->has('webroot') ? (rtrim((string) $this->input('webroot'), '/') ?: '.') : null,
            'SHARED' => $this->has('shared') ? implode(' ', array_map('strval', (array) $this->input('shared'))) : null,
            'KEEP' => $this->has('keep') ? (string) (int) $this->input('keep') : null,
            'MIGRATE' => $this->has('migrate') ? ($this->boolean('migrate') ? 'yes' : 'no') : null,
            'HEALTH_PATH' => $this->has('health_path') ? (string) $this->input('health_path') : null,
        ];

        return array_filter($values, fn (?string $value) => $value !== null);
    }

    /** A rule that refuses a value $valid does not accept. */
    public static function check(Closure $valid, string $message): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($valid, $message): void {
            if (! is_string($value) || ! $valid($value)) {
                $fail($message);
            }
        };
    }
}
