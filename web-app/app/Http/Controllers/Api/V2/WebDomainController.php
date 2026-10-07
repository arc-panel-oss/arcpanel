<?php

namespace App\Http\Controllers\Api\V2;

use App\Api\ApiException;
use App\Api\ErrorCode;
use App\Api\Paginates;
use App\Api\PlanGate;
use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\LetsEncryptRequest;
use App\Http\Requests\Api\V2\StoreWebDomainRequest;
use App\Http\Requests\Api\V2\UpdateWebDomainRequest;
use App\Http\Requests\Api\V2\WebDomainAliasRequest;
use App\Http\Requests\Api\V2\WebDomainRequest;
use App\Http\Requests\Api\V2\WebDomainSslRequest;
use App\Http\Resources\V2\WebDomainResource;
use App\Policies\WebDomainPolicy;
use App\Services\WebDomains\ApplyResult;
use App\Services\WebDomains\Input;
use App\Services\WebDomains\Step;
use App\Services\WebDomains\TemplateCatalog;
use App\Services\WebDomains\WebDomainActions;
use App\Services\WebDomains\WebDomainService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * Web domains of a user (E34 spec §6.3, E1-S2): everyone manages the domains
 * of its own account, a reseller those of its customers, an administrator
 * every user's. Templates, proxy and cache need the right to change
 * templates (administrators, or POLICY_USER_EDIT_WEB_TEMPLATES).
 *
 * @tags Web domains
 */
final class WebDomainController extends Controller
{
    public function __construct(
        private readonly WebDomainService $web,
        private readonly WebDomainActions $actions,
        private readonly TemplateCatalog $catalog,
        private readonly PlanGate $gate,
        private readonly Paginates $pages,
    ) {}

    /**
     * List the web domains of a user
     *
     * @operationId listWebDomains
     */
    public function index(Request $request, string $user, Principal $principal, WebDomainPolicy $policy): AnonymousResourceCollection
    {
        $this->assertOwner($user, $principal, $policy);
        $page = $this->pages->slice($request, array_values($this->web->forUser($user)));

        return WebDomainResource::collection($page['items'])->additional(['meta' => $page['meta']]);
    }

    /**
     * Create a web domain
     *
     * As the add page of the panel: the domain with its aliases (`www.DOMAIN`
     * by default), the DNS zone and mail domain when asked, then the asked
     * templates and the app port of a template that takes one. A domain the
     * user has answers 409 `E_EXISTS`; one of another user fails with it too.
     *
     * @operationId createWebDomain
     *
     * @status 201
     */
    public function store(StoreWebDomainRequest $request, string $user, Principal $principal, WebDomainPolicy $policy): WebDomainResource|JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);
        abort_unless($policy->create($principal, $user), 403);
        $domain = (string) $request->input('domain');
        if (array_key_exists($domain, $this->web->forUser($user))) {
            throw new ApiException(ErrorCode::Exists, sprintf(__('Web domain %s exists.'), $domain), 409);
        }
        $templates = $this->assertTemplatesAllowed($request, $user, $principal, $policy);

        $values = $request->advancedValues($this->actions->predictRecord($user, $domain, '', []));
        $ip = (string) $request->input('ip', array_key_first($this->web->userIps($user)) ?? '');
        $aliases = $request->aliases();
        $userIps = WebDomainRequest::withApiFields(function () use ($user, $domain, $ip, $values, $templates, $request) {
            $userIps = $this->actions->checkIp($user, $ip);
            if ($templates) {
                $this->checkSystems($request);
                $this->actions->checkTemplates($values);
                $this->actions->checkPort($user, $domain, null, $values, $request->backendPort());
            }

            return $userIps;
        });

        $steps = $this->actions->planCreate($user, $domain, $ip, $request->boolean('dns'), $request->boolean('mail'), $aliases, $userIps);
        $templateValues = ['template' => $values['template'], 'backend_template' => $values['backend_template'],
            'proxy_template' => $values['proxy_template'], 'proxy_extensions' => $request->has('proxy_extensions') ? $values['proxy_extensions'] : ''];
        $predicted = $templates ? $this->actions->planCreatedTemplates($this->actions->predictRecord($user, $domain, $ip, $aliases), $templateValues, $request->backendPort()) : [];

        $this->gate->authorize([...$steps, ...$predicted]);
        if ($this->gate->dryRun()) {
            return $this->gate->preview([...$steps, ...$predicted]);
        }
        $created = $this->web->apply($steps);
        $this->gate->assertApplied($created);
        if ($templates) {
            $templateSteps = $this->actions->planCreatedTemplates($this->web->find($user, $domain), $templateValues, $request->backendPort());
            $this->gate->authorize($templateSteps);
            $result = $this->web->apply($templateSteps);
            $this->gate->assertApplied(new ApplyResult([...$created->applied, ...$result->applied], $result->failed, $result->error, $result->exitCode));
        }

        return $this->resource($user, $domain)->response()->setStatusCode(201)
            ->header('Location', route('api.v2.web-domains.show', ['user' => $user, 'domain' => $domain]));
    }

    /**
     * Get a web domain
     *
     * @operationId getWebDomain
     */
    public function show(string $user, string $domain, Principal $principal, WebDomainPolicy $policy): WebDomainResource
    {
        $this->assertOwner($user, $principal, $policy);

        return $this->resource($user, $domain);
    }

    /**
     * Update a web domain
     *
     * The IP address, the document root and, with the right to change
     * templates, the templates, proxy, FastCGI cache and app port; the fields
     * left out keep their value. A new IP also moves the DNS zones of the
     * domain and its aliases.
     *
     * @operationId updateWebDomain
     */
    public function update(UpdateWebDomainRequest $request, string $user, string $domain, Principal $principal, WebDomainPolicy $policy): WebDomainResource|JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);
        abort_unless($policy->update($principal, $user), 403);
        $record = $this->web->find($user, $domain);
        $templates = $this->assertTemplatesAllowed($request, $user, $principal, $policy);

        $steps = WebDomainRequest::withApiFields(function () use ($request, $record, $user, $domain, $templates) {
            $steps = [];
            if ($request->setsGeneral()) {
                $values = $request->generalValues($record);
                $userIps = $this->actions->checkIp($user, $values['ip']);
                if ($request->exists('custom_document_root')) {
                    $this->actions->checkDocroot($user, $values);
                }
                $steps = $this->actions->planGeneral($record, $values, $userIps);
            }
            if ($templates) {
                $values = $request->advancedValues($record);
                $this->checkSystems($request);
                $this->actions->checkTemplates($values);
                $this->actions->checkPort($user, $domain, $record, $values, $request->backendPort());
                $steps = [...$steps, ...$this->actions->planTemplates($record, $values, $request->backendPort())];
            }

            return $steps;
        });

        return $this->gate->execute($steps, $this->web->apply(...)) ?? $this->resource($user, $domain);
    }

    /**
     * Delete a web domain
     *
     * Deletes the domain with its files, and the app port, Node.js app and
     * Docker app bound to it (the Docker app's data folder is kept).
     *
     * @operationId deleteWebDomain
     *
     * @status 204
     */
    public function destroy(string $user, string $domain, Principal $principal, WebDomainPolicy $policy): Response|JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);
        abort_unless($policy->delete($principal, $user), 403);
        $this->web->find($user, $domain);
        $steps = [new Step(sprintf(__('Delete web domain %s'), $domain), 'v-delete-web-domain', [$user, $domain])];

        if ($preview = $this->gate->execute($steps, $this->web->apply(...), WebDomainService::DELETE_CLEANUP)) {
            return $preview;
        }
        $this->web->cleanUpDeleted($user, $domain);

        return response()->noContent();
    }

    /**
     * Suspend a web domain
     *
     * A suspended domain answers as it is.
     *
     * @operationId suspendWebDomain
     */
    public function suspend(string $user, string $domain, Principal $principal, WebDomainPolicy $policy): WebDomainResource|JsonResponse
    {
        return $this->setSuspended($user, $domain, true, $principal, $policy);
    }

    /**
     * Unsuspend a web domain
     *
     * @operationId unsuspendWebDomain
     */
    public function unsuspend(string $user, string $domain, Principal $principal, WebDomainPolicy $policy): WebDomainResource|JsonResponse
    {
        return $this->setSuspended($user, $domain, false, $principal, $policy);
    }

    /**
     * Rebuild a web domain
     *
     * Writes the configuration of the domain again. Administrators only.
     *
     * @operationId rebuildWebDomain
     */
    public function rebuild(string $user, string $domain, Principal $principal, WebDomainPolicy $policy): WebDomainResource|JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);
        abort_unless($policy->rebuild($principal, $user), 403);
        $this->web->find($user, $domain);
        $steps = [new Step(sprintf(__('Rebuild web domain %s'), $domain), 'v-rebuild-web-domain', [$user, $domain])];

        return $this->gate->execute($steps, $this->web->apply(...)) ?? $this->resource($user, $domain);
    }

    /**
     * Purge the NGINX cache of a web domain
     *
     * On servers where NGINX serves the domains; 403 elsewhere.
     *
     * @operationId purgeWebDomainCache
     */
    public function purgeCache(string $user, string $domain, Principal $principal, WebDomainPolicy $policy): WebDomainResource|JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);
        abort_unless($policy->update($principal, $user), 403);
        if (! $this->web->canPurgeCache()) {
            throw new ApiException(ErrorCode::Forbidden, __('NGINX does not serve the domains of this server.'), 403);
        }
        $this->web->find($user, $domain);
        $steps = [new Step(__('Purge the NGINX cache'), 'v-purge-nginx-cache', [$user, $domain])];

        return $this->gate->execute($steps, $this->web->apply(...)) ?? $this->resource($user, $domain);
    }

    /**
     * Add an alias to a web domain
     *
     * With a DNS record when the domain has a DNS zone; a Let's Encrypt
     * certificate is queued again to cover it. An alias the domain has
     * answers 409 `E_EXISTS`.
     *
     * @operationId addWebDomainAlias
     */
    public function addAlias(WebDomainAliasRequest $request, string $user, string $domain, Principal $principal, WebDomainPolicy $policy): WebDomainResource|JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);
        abort_unless($policy->update($principal, $user), 403);
        $record = $this->web->find($user, $domain);
        $alias = (string) $request->input('alias');
        if (in_array($alias, [$domain, ...$record->aliases], true)) {
            throw new ApiException(ErrorCode::Exists, sprintf(__('Alias %s exists.'), $alias), 409);
        }

        return $this->gate->execute($this->actions->planAliases($record, [...$record->aliases, $alias]), $this->web->apply(...))
            ?? $this->resource($user, $domain);
    }

    /**
     * Delete an alias of a web domain
     *
     * With its DNS record; a Let's Encrypt certificate is queued again
     * without it.
     *
     * @operationId deleteWebDomainAlias
     *
     * @status 204
     */
    public function deleteAlias(string $user, string $domain, string $alias, Principal $principal, WebDomainPolicy $policy): Response|JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);
        abort_unless($policy->update($principal, $user), 403);
        $record = $this->web->find($user, $domain);
        $alias = strtolower($alias);
        abort_unless(in_array($alias, $record->aliases, true), 404);
        $aliases = array_values(array_filter($record->aliases, fn (string $name) => $name !== $alias));

        return $this->gate->execute($this->actions->planAliases($record, $aliases), $this->web->apply(...))
            ?? response()->noContent();
    }

    /**
     * Install a certificate or change HTTPS settings
     *
     * With `certificate` (and `key` for a first certificate) the uploaded
     * certificate is installed or replaced; a Let's Encrypt certificate is
     * removed first. Without it, `force` and `hsts` change for the
     * certificate the domain has.
     *
     * @operationId setWebDomainSsl
     */
    public function setSsl(WebDomainSslRequest $request, string $user, string $domain, Principal $principal, WebDomainPolicy $policy): WebDomainResource|JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);
        abort_unless($policy->update($principal, $user), 403);
        $record = $this->web->find($user, $domain);
        $values = $request->values();
        $uploaded = Input::pem($values['certificate'] ?? '') !== '';
        $missing = array_filter([
            'certificate' => ! $uploaded && ! $record->ssl ? __('The domain has no certificate: send one.') : null,
            'key' => $uploaded && (! $record->ssl || $record->letsEncrypt) && Input::pem($values['key'] ?? '') === '' ? __('The private key of a new certificate is required.') : null,
        ]);
        if ($missing !== []) {
            throw ValidationException::withMessages($missing);
        }

        return $this->gate->execute($this->actions->planSsl($record, $values), $this->web->apply(...))
            ?? $this->resource($user, $domain);
    }

    /**
     * Turn SSL off
     *
     * Removes the certificate (uploaded or Let's Encrypt), forced HTTPS and
     * HSTS. A domain without SSL answers as it is.
     *
     * @operationId deleteWebDomainSsl
     *
     * @status 204
     */
    public function deleteSsl(string $user, string $domain, Principal $principal, WebDomainPolicy $policy): Response|JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);
        abort_unless($policy->update($principal, $user), 403);
        $record = $this->web->find($user, $domain);

        return $this->gate->execute($this->actions->planSslOff($record), $this->web->apply(...))
            ?? response()->noContent();
    }

    /**
     * Request a Let's Encrypt certificate
     *
     * Queues the certificate for the domain and its aliases, which must
     * resolve to this server; the queue runs every five minutes. Answers
     * 202 with the domain as it is: `ssl.letsencrypt` turns true once the
     * certificate is installed.
     *
     * @operationId requestWebDomainLetsEncrypt
     *
     * @status 202
     */
    public function letsEncrypt(LetsEncryptRequest $request, string $user, string $domain, Principal $principal, WebDomainPolicy $policy): WebDomainResource|JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);
        abort_unless($policy->update($principal, $user), 403);
        $record = $this->web->find($user, $domain);
        $steps = [$this->actions->planLetsEncrypt($record, (string) $request->input('key_algorithm', $record->sslKeyAlgo))];

        if ($preview = $this->gate->execute($steps, $this->web->apply(...))) {
            return $preview;
        }

        return $this->resource($user, $domain)->response()->setStatusCode(202)
            ->header('Location', route('api.v2.web-domains.show', ['user' => $user, 'domain' => $domain]));
    }

    private function setSuspended(string $user, string $domain, bool $suspend, Principal $principal, WebDomainPolicy $policy): WebDomainResource|JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);
        abort_unless($policy->suspend($principal, $user), 403);
        $record = $this->web->find($user, $domain);
        $steps = $record->suspended === $suspend ? [] : [$suspend
            ? new Step(sprintf(__('Suspend web domain %s'), $domain), 'v-suspend-web-domain', [$user, $domain])
            : new Step(sprintf(__('Unsuspend web domain %s'), $domain), 'v-unsuspend-web-domain', [$user, $domain])];

        return $this->gate->execute($steps, $this->web->apply(...)) ?? $this->resource($user, $domain);
    }

    /** A user the principal may see (the route's middleware) that exists; 404 otherwise. */
    private function assertOwner(string $user, Principal $principal, WebDomainPolicy $policy): void
    {
        abort_unless($policy->view($principal, $user) && $this->actions->ownerExists($user), 404);
    }

    /** Whether the request sets templates; 403 when it does without the right to. */
    private function assertTemplatesAllowed(WebDomainRequest $request, string $user, Principal $principal, WebDomainPolicy $policy): bool
    {
        if (! $request->setsTemplates()) {
            return false;
        }
        if (! $policy->updateTemplates($principal, $user)) {
            throw new ApiException(ErrorCode::Forbidden, __('This account may not change templates, proxy or cache.'), 403);
        }

        return true;
    }

    /** Proxy and cache fields need a server that has them. */
    private function checkSystems(WebDomainRequest $request): void
    {
        $systems = $this->web->systems();
        $errors = array_filter([
            'proxy' => $request->has('proxy') && ! $systems['proxy'] ? __('This server has no proxy.') : null,
            'fastcgi_cache' => $request->has('fastcgi_cache') && ! $systems['nginx'] ? __('The FastCGI cache needs NGINX with PHP-FPM.') : null,
        ]);
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** One domain with the app port of its template and its certificate. */
    private function resource(string $user, string $domain): WebDomainResource
    {
        return WebDomainResource::single($this->web, $this->catalog, $user, $domain);
    }
}
