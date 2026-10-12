<?php

namespace App\Http\Resources\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The PHP of a web domain (`v-list-web-domain-php USER DOMAIN json`, E41
 * §7.2): version and PHP-FPM profile, what the package allows, and the
 * effective PHP limits with their source.
 *
 * @property array{version: string, backend: string, profile: string, profileApplied: bool, versionAllowed: bool, profileAllowed: bool, allowed: bool, package: string, defaultProfile: string, versions: array<string, array{status: string, eol: string}>, profiles: list<string>, effective: array<string, array{value: string, source: string}>} $resource
 */
final class WebDomainPhpResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $versions = [];
        foreach ($this->resource['versions'] as $version => $state) {
            $versions[] = ['version' => (string) $version, 'status' => $state['status'], 'eol' => $state['eol'] !== '' ? $state['eol'] : null];
        }
        $effective = [];
        foreach ($this->resource['effective'] as $directive => $value) {
            $effective[$directive] = ['value' => $value['value'] !== '' ? $value['value'] : null, 'source' => $value['source']];
        }

        return [
            /** @var string|null the PHP version; null for a domain without PHP (`no-php`) */
            'version' => $this->resource['version'] !== '' ? $this->resource['version'] : null,
            /** @var string the backend template */
            'backend_template' => $this->resource['backend'],
            /** @var string the PHP-FPM profile of the pool */
            'profile' => $this->resource['profile'],
            /** @var bool false when the backend template has no profile line, so the pool keeps its own settings */
            'profile_applied' => $this->resource['profileApplied'],
            /**
             * Whether the package allows the domain's version and profile; a
             * value the package no longer allows stays but cannot be chosen again.
             *
             * @var array{version: bool, profile: bool}
             */
            'allowed' => ['version' => $this->resource['versionAllowed'], 'profile' => $this->resource['profileAllowed']],
            /** @var string the package of the owner */
            'package' => $this->resource['package'],
            /** @var string the profile of new web domains of the package */
            'default_profile' => $this->resource['defaultProfile'],
            /** @var list<array{version: string, status: string, eol: string|null}> installed versions the package allows */
            'versions' => $versions,
            /** @var list<string> profiles the package allows */
            'profiles' => $this->resource['profiles'],
            /**
             * memory_limit, upload_max_filesize, post_max_size, max_execution_time and max_input_vars
             * with their source: `profile` or `php.ini`.
             *
             * @var array<string, array{value: string|null, source: string}>
             */
            'effective' => $effective,
        ];
    }
}
