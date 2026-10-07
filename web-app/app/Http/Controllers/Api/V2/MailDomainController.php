<?php

namespace App\Http\Controllers\Api\V2;

use App\Api\ApiException;
use App\Api\TaskLink;
use App\Api\ErrorCode;
use App\Api\Paginates;
use App\Api\PlanGate;
use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\RequestMailLetsEncryptRequest;
use App\Http\Requests\Api\V2\SetMailDomainRelayRequest;
use App\Http\Requests\Api\V2\SetMailDomainSslRequest;
use App\Http\Requests\Api\V2\StoreMailDomainRequest;
use App\Http\Requests\Api\V2\UpdateMailDomainRequest;
use App\Http\Resources\V2\MailDnsRecordResource;
use App\Http\Resources\V2\MailDomainResource;
use App\Policies\MailPolicy;
use App\Services\MailDomains\MailActions;
use App\Services\MailDomains\MailDomainPlanner;
use App\Services\MailDomains\MailService;
use App\Services\WebDomains\Step;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * Mail domains of a user (E34 batch 2 spec §6.2).
 *
 * @tags Mail
 */
final class MailDomainController extends Controller
{
    public function __construct(
        private readonly MailService $mail,
        private readonly MailDomainPlanner $planner,
        private readonly MailActions $actions,
        private readonly PlanGate $gate,
        private readonly Paginates $pages,
    ) {}

    /**
     * List the mail domains of a user
     *
     * @operationId listMailDomains
     */
    public function index(Request $request, string $user, Principal $principal, MailPolicy $policy): AnonymousResourceCollection
    {
        $this->assertOwner($user, $principal, $policy);
        $page = $this->pages->slice($request, array_values($this->mail->forUser($user)));

        return MailDomainResource::collection($page['items'])->additional(['meta' => $page['meta']]);
    }

    /**
     * Create a mail domain
     *
     * Spam filter, anti-virus and DKIM follow the add page: on when left out
     * and the server offers them. A domain the user has answers 409 `E_EXISTS`.
     *
     * @operationId createMailDomain
     *
     * @status 201
     */
    public function store(StoreMailDomainRequest $request, string $user, Principal $principal, MailPolicy $policy): MailDomainResource|JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);
        abort_unless($policy->create($principal, $user), 403);
        $domain = (string) $request->input('domain');
        if (array_key_exists($domain, $this->mail->forUser($user))) {
            throw new ApiException(ErrorCode::Exists, sprintf(__('Mail domain %s exists.'), $domain), 409);
        }
        $systems = $this->mail->systems();
        $this->assertOffered('antispam', $request->exists('antispam'), $systems['antispam']);
        $this->assertOffered('antivirus', $request->exists('antivirus'), $systems['antivirus']);

        $input = [
            'domain' => $domain,
            'antispam' => $request->exists('antispam') ? $request->boolean('antispam') : true,
            'antivirus' => $request->exists('antivirus') ? $request->boolean('antivirus') : true,
            'dkim' => $request->exists('dkim') ? $request->boolean('dkim') : true,
            'reject' => false,
            'webmail' => '',
            'relay' => false,
            'relay_host' => '',
            'relay_port' => '',
            'relay_user' => '',
            'relay_password' => '',
        ];
        $steps = $this->planner->planCreate($user, $input, [...$systems, 'webmail' => false]);

        if ($preview = $this->gate->execute($steps, $this->mail->apply(...))) {
            return $preview;
        }

        return $this->resource($user, $domain)->response()->setStatusCode(201)
            ->header('Location', route('api.v2.mail-domains.show', ['user' => $user, 'domain' => $domain]));
    }

    /**
     * Get a mail domain
     *
     * @operationId getMailDomain
     */
    public function show(string $user, string $domain, Principal $principal, MailPolicy $policy): MailDomainResource
    {
        $this->assertOwner($user, $principal, $policy);

        return $this->resource($user, $domain);
    }

    /**
     * Update a mail domain
     *
     * The General tab. Fields left out keep their value. `rate_limit` is for
     * administrators.
     *
     * @operationId updateMailDomain
     */
    public function update(UpdateMailDomainRequest $request, string $user, string $domain, Principal $principal, MailPolicy $policy): MailDomainResource|JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);
        abort_unless($policy->update($principal, $user), 403);
        $record = $this->mail->find($user, $domain);
        $systems = $this->mail->systems();
        $this->assertOffered('antispam', $request->exists('antispam'), $systems['antispam']);
        $this->assertOffered('antivirus', $request->exists('antivirus'), $systems['antivirus']);
        $this->assertOffered('reject', $request->exists('reject'), $systems['antispam']);
        $this->assertOffered('webmail', $request->exists('webmail'), $systems['webmail']);
        if ($request->exists('rate_limit') && ! $policy->updateRateLimit($principal, $user)) {
            throw new ApiException(ErrorCode::Forbidden, __('This account may not change the rate limit.'), 403);
        }
        if ($request->exists('webmail') && $request->input('webmail') !== null) {
            $this->actions->assertWebmail((string) $request->input('webmail'));
        }

        $webmail = null;
        if ($request->exists('webmail')) {
            $webmail = $request->input('webmail') === null ? MailDomainPlanner::WEBMAIL_DISABLED : (string) $request->input('webmail');
        }
        $input = [
            'webmail' => $webmail,
            'catchall' => $request->exists('catchall') ? (string) ($request->input('catchall') ?? '') : $record->catchall,
            'rate' => $request->exists('rate_limit') ? (string) ($request->input('rate_limit') ?? '') : null,
            'antispam' => $request->exists('antispam') ? $request->boolean('antispam') : $record->antispam,
            'reject' => $request->exists('reject') ? $request->boolean('reject') : $record->reject,
            'antivirus' => $request->exists('antivirus') ? $request->boolean('antivirus') : $record->antivirus,
            'dkim' => $request->exists('dkim') ? $request->boolean('dkim') : $record->dkim,
        ];

        return $this->gate->execute($this->planner->planGeneral($record, $input, $systems), $this->mail->apply(...))
            ?? $this->resource($user, $domain);
    }

    /**
     * Delete a mail domain
     *
     * @operationId deleteMailDomain
     *
     * @status 204
     */
    public function destroy(string $user, string $domain, Principal $principal, MailPolicy $policy): Response|JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);
        abort_unless($policy->delete($principal, $user), 403);
        $this->mail->find($user, $domain);

        return $this->gate->execute([
            new Step(sprintf(__('Delete mail domain %s'), $domain), 'v-delete-mail-domain', [$user, $domain]),
        ], $this->mail->apply(...)) ?? response()->noContent();
    }

    /**
     * Suspend a mail domain
     *
     * @operationId suspendMailDomain
     */
    public function suspend(string $user, string $domain, Principal $principal, MailPolicy $policy): MailDomainResource|JsonResponse
    {
        return $this->setSuspended($user, $domain, true, $principal, $policy);
    }

    /**
     * Unsuspend a mail domain
     *
     * @operationId unsuspendMailDomain
     */
    public function unsuspend(string $user, string $domain, Principal $principal, MailPolicy $policy): MailDomainResource|JsonResponse
    {
        return $this->setSuspended($user, $domain, false, $principal, $policy);
    }

    /**
     * Rebuild a mail domain
     *
     * Administrators only.
     *
     * @operationId rebuildMailDomain
     */
    public function rebuild(string $user, string $domain, Principal $principal, MailPolicy $policy): MailDomainResource|JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);
        abort_unless($policy->rebuild($principal, $user), 403);
        $this->mail->find($user, $domain);

        return $this->gate->execute([
            new Step(sprintf(__('Rebuild mail domain %s'), $domain), 'v-rebuild-mail-domain', [$user, $domain]),
        ], $this->mail->apply(...)) ?? $this->resource($user, $domain);
    }

    /**
     * Install an uploaded certificate
     *
     * Replacing a Let's Encrypt certificate removes it first, in the same plan.
     *
     * @operationId setMailDomainSsl
     */
    public function setSsl(SetMailDomainSslRequest $request, string $user, string $domain, Principal $principal, MailPolicy $policy): MailDomainResource|JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);
        abort_unless($policy->update($principal, $user), 403);
        $record = $this->mail->find($user, $domain);
        $values = [
            'ssl' => true,
            'letsencrypt' => false,
            'key_algo' => $record->keyAlgo(),
            'certificate' => (string) $request->input('certificate'),
            'key' => $request->input('key'),
            'ca' => $request->input('ca'),
        ];
        $this->actions->assertCertificate($record, $values);
        $certificate = $record->ssl ? $this->mail->ssl($user, $domain) : null;

        return $this->gate->execute(
            $this->planner->planSsl($record, $certificate, $values, $record->letsEncrypt),
            $this->mail->apply(...),
        ) ?? $this->resource($user, $domain);
    }

    /**
     * Remove the certificate of a mail domain
     *
     * Idempotent: 204 when the domain has no SSL.
     *
     * @operationId deleteMailDomainSsl
     *
     * @status 204
     */
    public function deleteSsl(string $user, string $domain, Principal $principal, MailPolicy $policy): Response|JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);
        abort_unless($policy->update($principal, $user), 403);
        $record = $this->mail->find($user, $domain);
        $steps = $record->ssl || $record->letsEncrypt
            ? $this->planner->planSsl($record, null, [
                'ssl' => false,
                'letsencrypt' => false,
                'key_algo' => $record->keyAlgo(),
                'certificate' => null,
                'key' => null,
                'ca' => null,
            ])
            : [];

        return $this->gate->execute($steps, $this->mail->apply(...)) ?? response()->noContent();
    }

    /**
     * Queue a Let's Encrypt certificate
     *
     * Answers 202 with the domain as it is: `ssl.letsencrypt` turns true once
     * the queued certificate is installed.
     *
     * @operationId requestMailDomainLetsEncrypt
     *
     * @status 202
     */
    public function letsEncrypt(RequestMailLetsEncryptRequest $request, string $user, string $domain, Principal $principal, MailPolicy $policy): MailDomainResource|JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);
        abort_unless($policy->update($principal, $user), 403);
        $record = $this->mail->find($user, $domain);
        $algo = (string) $request->input('key_algorithm', $record->keyAlgo());
        $steps = [new Step(__('Issue a Let’s Encrypt certificate'), 'v-schedule-letsencrypt-domain', [$user, $domain, '', $algo, 'yes'])];

        $apply = null;
        if ($preview = $this->gate->execute($steps, function (array $plan) use (&$apply) {
            $apply = $this->mail->apply($plan);

            return $apply;
        })) {
            return $preview;
        }
        $recordId = $apply?->stdoutOf('v-schedule-letsencrypt-domain') ?? '';
        $headers = ['Location' => route('api.v2.mail-domains.show', ['user' => $user, 'domain' => $domain])];
        if ($recordId !== '') {
            $headers['Link'] = TaskLink::queue($user, $recordId);
        }

        return $this->resource($user, $domain)->response()->setStatusCode(202)->withHeaders($headers);
    }

    /**
     * Set the SMTP relay
     *
     * Re-added only when the host, username or port changed or a password was sent.
     *
     * @operationId setMailDomainSmtpRelay
     */
    public function setRelay(SetMailDomainRelayRequest $request, string $user, string $domain, Principal $principal, MailPolicy $policy): MailDomainResource|JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);
        abort_unless($policy->update($principal, $user), 403);
        $record = $this->mail->find($user, $domain);
        $input = [
            'relay' => true,
            'relay_host' => (string) $request->input('host'),
            'relay_port' => $request->exists('port') ? (string) $request->integer('port') : '',
            'relay_user' => (string) $request->input('username', ''),
            'relay_password' => (string) $request->input('password', ''),
        ];

        return $this->gate->execute($this->planner->planRelay($record, $input), $this->mail->apply(...))
            ?? $this->resource($user, $domain);
    }

    /**
     * Remove the SMTP relay
     *
     * Idempotent: 204 when the domain has no relay.
     *
     * @operationId deleteMailDomainSmtpRelay
     *
     * @status 204
     */
    public function deleteRelay(string $user, string $domain, Principal $principal, MailPolicy $policy): Response|JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);
        abort_unless($policy->update($principal, $user), 403);
        $record = $this->mail->find($user, $domain);
        $steps = $this->planner->planRelay($record, [
            'relay' => false,
            'relay_host' => '',
            'relay_port' => '',
            'relay_user' => '',
            'relay_password' => '',
        ]);

        return $this->gate->execute($steps, $this->mail->apply(...)) ?? response()->noContent();
    }

    /**
     * DNS records a mail domain needs
     *
     * @operationId getMailDomainDnsRecords
     */
    public function dnsRecords(string $user, string $domain, Principal $principal, MailPolicy $policy): AnonymousResourceCollection
    {
        $this->assertOwner($user, $principal, $policy);

        return MailDnsRecordResource::collection($this->mail->dnsHints($this->mail->find($user, $domain)));
    }

    private function setSuspended(string $user, string $domain, bool $suspend, Principal $principal, MailPolicy $policy): MailDomainResource|JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);
        abort_unless($policy->suspend($principal, $user), 403);
        $record = $this->mail->find($user, $domain);
        $steps = $record->suspended === $suspend ? [] : [$suspend
            ? new Step(sprintf(__('Suspend mail domain %s'), $domain), 'v-suspend-mail-domain', [$user, $domain])
            : new Step(sprintf(__('Unsuspend mail domain %s'), $domain), 'v-unsuspend-mail-domain', [$user, $domain])];

        return $this->gate->execute($steps, $this->mail->apply(...)) ?? $this->resource($user, $domain);
    }

    private function resource(string $user, string $domain): MailDomainResource
    {
        return MailDomainResource::single($this->mail, $user, $domain);
    }

    private function assertOwner(string $user, Principal $principal, MailPolicy $policy): void
    {
        abort_unless($policy->view($principal, $user) && $this->actions->ownerExists($user), 404);
    }

    /** A field the server does not offer is a validation error on that field. */
    private function assertOffered(string $field, bool $sent, bool $available): void
    {
        if ($sent && ! $available) {
            throw ValidationException::withMessages([$field => __('This server does not offer that option.')]);
        }
    }
}
