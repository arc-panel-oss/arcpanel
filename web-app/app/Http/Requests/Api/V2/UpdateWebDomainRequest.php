<?php

namespace App\Http\Requests\Api\V2;

use App\Services\WebDomains\WebDomainRecord;

/**
 * PATCH /users/{user}/web-domains/{domain}: the fields of the General tab
 * (IP address, document root) and of the Advanced tab; the fields left out
 * keep their value. Aliases have their own operations.
 */
final class UpdateWebDomainRequest extends WebDomainRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** One of the user's addresses (v-list-user-ips). */
            'ip' => ['sometimes', 'ip'],
            /** Serve the folder of another domain of the user; null serves the domain's own public_html. */
            'custom_document_root' => ['sometimes', 'nullable', 'array'],
            'custom_document_root.domain' => ['required_with:custom_document_root', 'string', 'max:253', 'regex:/^[\pL\pN.-]+$/u'],
            /** A folder below that domain's public_html. */
            'custom_document_root.folder' => ['sometimes', 'nullable', 'string', 'max:255', 'regex:/^[\pL\pN\/._ -]*$/u', 'not_regex:/\.\./'],
            ...$this->templateRules(),
        ];
    }

    /** Whether the request sets a field of the General tab. */
    public function setsGeneral(): bool
    {
        return $this->has('ip') || $this->exists('custom_document_root');
    }

    /**
     * The fields of the General tab: what the request sends over the
     * domain's current values, the aliases unchanged.
     *
     * @return array{aliases: string, ip: string, docroot_custom: bool, docroot_domain: ?string, docroot_folder: ?string}
     */
    public function generalValues(WebDomainRecord $record): array
    {
        $parts = $record->customDocrootParts();
        if ($this->exists('custom_document_root')) {
            $custom = $this->input('custom_document_root') !== null;
            $target = $custom ? (string) $this->input('custom_document_root.domain') : null;
            $folder = $custom ? (string) ($this->input('custom_document_root.folder') ?? '') : null;
        } else {
            $custom = $record->customDocroot !== '';
            $target = $parts['domain'] ?? null;
            $folder = $parts['folder'] ?? null;
        }

        return [
            'aliases' => implode(',', $record->aliases),
            'ip' => (string) $this->input('ip', $record->ip),
            'docroot_custom' => $custom,
            'docroot_domain' => $target,
            'docroot_folder' => $folder,
        ];
    }
}
