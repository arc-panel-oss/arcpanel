<?php

namespace App\Http\Resources\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The per-domain PHP settings of a web domain
 * (`v-list-web-domain-php-settings USER DOMAIN json`, E19 §5): per setting
 * the domain's value, the package maximum, the values of php.ini and the
 * profile, and the effective value with its source.
 *
 * @property array{pool: string, supported: bool, nginxMaxBody: string, settings: array<string, array{value: string, max: string, ini: string, profile: string, effective: string, source: string}>, warnings: list<string>} $resource
 */
final class WebDomainPhpSettingsResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $empty = fn (string $value) => $value !== '' ? $value : null;
        $settings = [];
        foreach ($this->resource['settings'] as $directive => $setting) {
            $settings[str_replace('.', '_', $directive)] = [
                /** @var string the php.ini directive */
                'directive' => $directive,
                /** @var string|null the domain's value; null when it inherits */
                'value' => $empty($setting['value']),
                /** @var string|null the package maximum; null for none or unlimited */
                'max' => $setting['max'] !== 'unlimited' ? $empty($setting['max']) : null,
                /** @var string|null the value of the version's php.ini */
                'ini' => $empty($setting['ini']),
                /** @var string|null the value of the PHP-FPM profile */
                'profile' => $empty($setting['profile']),
                /** @var string|null the value the pool runs with */
                'effective' => $empty($setting['effective']),
                /** @var string `php.ini`, `profile`, `domain`, or `capped` when the package maximum lowers the domain's value */
                'source' => $setting['source'],
            ];
        }

        return [
            /** @var string `domain`, or `user` when the user's web domains share one pool and so one set of settings */
            'pool' => $this->resource['pool'],
            /** @var bool false for a domain without PHP or a backend template without the profile line */
            'supported' => $this->resource['supported'],
            /** @var string|null the nginx `client_max_body_size` of the domain, which also caps uploads */
            'nginx_max_body' => $empty($this->resource['nginxMaxBody']),
            /** The seven settings keyed by directive, with `date.timezone` as `date_timezone`. */
            'settings' => $settings,
            /** @var list<string> */
            'warnings' => $this->resource['warnings'],
        ];
    }
}
