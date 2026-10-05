<?php

namespace App\Http\Requests\Packages;

use App\Services\Packages\PackageRecord;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Add or edit a package (web/add/package, web/edit/package). Limits come
 * from x-ap-unlimited-input: NAME and, when ticked, NAME_unlimited.
 * Templates and shells are checked by the controller against the server's
 * lists.
 */
final class PackageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $object = ['nullable', 'string', 'max:100', 'regex:/^[[:alnum:]][-.\w]*$/'];
        $rules = [
            'name' => ['required', 'string', 'max:60', 'regex:/^[[:alnum:]][-.\w]*$/'],
            'web_template' => $object,
            'backend_template' => $object,
            'proxy_template' => $object,
            'dns_template' => $object,
            'shell' => $object,
            'rate_limit' => ['required', 'integer', 'min:0', 'max:1000000'],
            'backups' => ['required', 'integer', 'min:0', 'max:1000'],
            'backups_incremental' => ['nullable', 'boolean'],
            'nameservers' => ['required', 'string', 'max:2000'],
            'users' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'reseller_packages' => ['nullable', 'string', 'max:2000', 'regex:/^(\*|[[:alnum:]][-.\w]*(\s*,\s*[[:alnum:]][-.\w]*)*)?$/'],
            'reseller_visible' => ['nullable', 'boolean'],
            'ports' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'node' => ['nullable', 'boolean'],
            'node_versions' => ['nullable', 'integer', 'min:0', 'max:999'],
            'node_allowed' => ['nullable', 'string', 'max:100', 'regex:/^(lts|\*|\d{1,3}(,\d{1,3})*)$/'],
        ];
        foreach (PackageRecord::COUNTED as $key) {
            $rules[strtolower($key)] = ['nullable', 'required_without:'.strtolower($key).'_unlimited', 'integer', 'min:0', 'max:100000000'];
        }
        foreach (PackageRecord::RESOURCES as $key) {
            $rules[strtolower($key)] = ['nullable', 'string', 'max:20', 'regex:/^(unlimited|\d+(%|ms|s|[KMGT])?)$/'];
        }

        return $rules;
    }

    /**
     * The package file values, KEY => value; cgroup limits keep $current
     * when the server does not use them.
     *
     * @param  array<string, string>  $current
     * @return array<string, string>
     */
    public function packageValues(array $current, bool $resources): array
    {
        $values = [
            'WEB_TEMPLATE' => (string) ($this->input('web_template') ?: 'default'),
            'BACKEND_TEMPLATE' => (string) ($this->input('backend_template') ?: ($current['BACKEND_TEMPLATE'] ?? 'default')),
            'PROXY_TEMPLATE' => (string) ($this->input('proxy_template') ?: ($current['PROXY_TEMPLATE'] ?? 'default')),
            'DNS_TEMPLATE' => (string) ($this->input('dns_template') ?: 'default'),
            'RATE_LIMIT' => (string) $this->integer('rate_limit'),
            'BACKUPS' => (string) $this->integer('backups'),
            'BACKUPS_INCREMENTAL' => $this->boolean('backups_incremental') ? 'yes' : 'no',
            'SHELL' => (string) ($this->input('shell') ?: 'nologin'),
            'NS' => implode(',', $this->nameservers()),
        ];
        foreach (PackageRecord::COUNTED as $key) {
            $field = strtolower($key);
            $values[$key] = $this->boolean($field.'_unlimited') ? 'unlimited' : (string) $this->integer($field);
        }
        foreach (PackageRecord::RESOURCES as $key) {
            $values[$key] = $resources ? (string) ($this->input(strtolower($key)) ?: 'unlimited') : ($current[$key] ?? 'unlimited');
        }

        return $values;
    }

    /** @return array{USERS: string, RESELLER_PACKAGES: string, RESELLER_VISIBLE: string} */
    public function tenancyValues(): array
    {
        $packages = preg_split('/\s*,\s*/', trim((string) $this->input('reseller_packages'))) ?: [];

        return [
            'USERS' => $this->boolean('users_unlimited') ? 'unlimited' : (string) $this->integer('users'),
            'RESELLER_PACKAGES' => implode(',', array_filter($packages)),
            'RESELLER_VISIBLE' => $this->boolean('reseller_visible') ? 'yes' : 'no',
        ];
    }

    /** PORTS of the package sidecar (E12): a number, or unlimited when ticked. */
    public function portsValue(): string
    {
        return $this->boolean('ports_unlimited') ? 'unlimited' : (string) $this->integer('ports');
    }

    /**
     * Node.js keys of the package sidecar (E13): NODE, NODE_VERSIONS (a
     * number, or unlimited when ticked) and NODE_ALLOWED.
     *
     * @return array{NODE: string, NODE_VERSIONS: string, NODE_ALLOWED: string}
     */
    public function nodeValues(): array
    {
        return [
            'NODE' => $this->boolean('node') ? 'yes' : 'no',
            'NODE_VERSIONS' => $this->boolean('node_versions_unlimited') ? 'unlimited' : (string) $this->integer('node_versions', 3),
            'NODE_ALLOWED' => (string) ($this->input('node_allowed') ?: 'lts'),
        ];
    }

    /** @return list<string> */
    public function nameservers(): array
    {
        $list = preg_split('/[\s,]+/', strtolower((string) $this->input('nameservers'))) ?: [];

        return array_values(array_filter($list, fn (string $ns) => preg_match('/^([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z0-9-]+$/', $ns) === 1));
    }
}
