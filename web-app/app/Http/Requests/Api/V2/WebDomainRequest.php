<?php

namespace App\Http\Requests\Api\V2;

use App\Services\WebDomains\WebDomainRecord;
use Closure;
use Illuminate\Validation\ValidationException;

/**
 * Base of POST and PATCH /users/{user}/web-domains: the template fields of
 * the Advanced tab (E6), which need the right to change templates
 * (administrators, or users with POLICY_USER_EDIT_WEB_TEMPLATES).
 */
abstract class WebDomainRequest extends ApiRequest
{
    /** The fields that need the right to change templates. */
    public const TEMPLATE_FIELDS = ['templates', 'proxy', 'proxy_extensions', 'fastcgi_cache', 'backend_port'];

    /** @return array<string, mixed> */
    protected function templateRules(): array
    {
        $name = ['sometimes', 'string', 'max:100', 'regex:/^[\w.-]+$/'];

        return [
            'templates' => ['sometimes', 'array'],
            /** A web template of the server (v-list-web-templates). */
            'templates.web' => $name,
            /** A PHP-FPM backend template, on servers with a backend. */
            'templates.backend' => $name,
            /** A proxy template, on servers with a proxy; it also turns the proxy on. */
            'templates.proxy' => $name,
            /** Turns the proxy in front of the web server on or off. */
            'proxy' => ['sometimes', 'boolean'],
            /** Extensions the proxy serves itself. */
            'proxy_extensions' => ['sometimes', 'array', 'max:200'],
            'proxy_extensions.*' => ['string', 'max:20', 'regex:/^[\w.-]+$/'],
            /** The NGINX FastCGI cache, on servers where NGINX runs PHP-FPM. */
            'fastcgi_cache' => ['sometimes', 'array'],
            'fastcgi_cache.enabled' => ['required_with:fastcgi_cache', 'boolean'],
            /** How long a page is kept, such as 2m (the default), 1h or 1d. */
            'fastcgi_cache.duration' => ['sometimes', 'nullable', 'string', 'regex:/^\d+[smhd]$/'],
            /** `auto` or a port of the owner's pool, for a template that takes an app port (E6). */
            'backend_port' => ['sometimes', 'nullable', 'regex:/^(auto|\d{1,5})$/'],
        ];
    }

    /** Whether the request sets a template field. */
    public function setsTemplates(): bool
    {
        foreach (self::TEMPLATE_FIELDS as $field) {
            if ($this->has($field)) {
                return true;
            }
        }

        return false;
    }

    /** "auto", a port, or '' when not sent. */
    public function backendPort(): string
    {
        return (string) ($this->input('backend_port') ?? '');
    }

    /**
     * The fields of the Advanced tab: what the request sends over the
     * domain's current values.
     *
     * @return array{template: ?string, backend_template: ?string, proxy: bool, proxy_template: ?string, proxy_extensions: string, fastcgi_cache: bool, fastcgi_duration: ?string}
     */
    public function advancedValues(WebDomainRecord $record): array
    {
        $proxyTemplate = $this->input('templates.proxy');

        return [
            'template' => $this->input('templates.web'),
            'backend_template' => $this->input('templates.backend'),
            'proxy' => $this->has('proxy') ? $this->boolean('proxy') : ($record->hasProxy() || $proxyTemplate !== null),
            'proxy_template' => $proxyTemplate,
            'proxy_extensions' => implode(',', $this->has('proxy_extensions') ? (array) $this->input('proxy_extensions') : $record->proxyExtensions),
            'fastcgi_cache' => $this->has('fastcgi_cache.enabled') ? $this->boolean('fastcgi_cache.enabled') : $record->fastcgiCache,
            'fastcgi_duration' => $this->has('fastcgi_cache.duration') ? $this->input('fastcgi_cache.duration') : $record->fastcgiDuration,
        ];
    }

    /**
     * Run a check of WebDomainActions; its errors, named after the fields of
     * the panel forms, are renamed to the fields of this request.
     *
     * @template T
     *
     * @param  Closure(): T  $check
     * @return T
     *
     * @throws ValidationException
     */
    public static function withApiFields(Closure $check): mixed
    {
        try {
            return $check();
        } catch (ValidationException $e) {
            $names = [
                'template' => 'templates.web', 'backend_template' => 'templates.backend', 'proxy_template' => 'templates.proxy',
                'docroot_domain' => 'custom_document_root.domain',
            ];
            $errors = [];
            foreach ($e->errors() as $field => $messages) {
                $errors[$names[$field] ?? $field] = $messages;
            }

            throw ValidationException::withMessages($errors);
        }
    }
}
