<?php

namespace App\Http\Controllers\Api\V2;

use App\Api\ApiException;
use App\Api\ErrorCode;
use App\Api\Paginates;
use App\Api\PlanGate;
use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\ChangeMailAccountPasswordRequest;
use App\Http\Requests\Api\V2\StoreMailAccountRequest;
use App\Http\Requests\Api\V2\UpdateMailAccountRequest;
use App\Http\Resources\V2\MailAccountResource;
use App\Policies\MailPolicy;
use App\Services\Mail\HostingCredentialsMailer;
use App\Services\MailDomains\MailAccountPlanner;
use App\Services\MailDomains\MailActions;
use App\Services\MailDomains\MailService;
use App\Services\WebDomains\Step;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Mail accounts of a domain (E34 batch 2 spec §6.3).
 *
 * @tags Mail
 */
final class MailAccountController extends Controller
{
    public function __construct(
        private readonly MailService $mail,
        private readonly MailAccountPlanner $planner,
        private readonly MailActions $actions,
        private readonly PlanGate $gate,
        private readonly Paginates $pages,
    ) {}

    /**
     * List the mail accounts of a domain
     *
     * @operationId listMailAccounts
     */
    public function index(Request $request, string $user, string $domain, Principal $principal, MailPolicy $policy): AnonymousResourceCollection
    {
        $this->assertOwner($user, $principal, $policy);
        $this->mail->find($user, $domain);
        $page = $this->pages->slice($request, $this->mail->accounts($user, $domain));

        return MailAccountResource::collection($page['items'])->additional(['meta' => $page['meta']]);
    }

    /**
     * Create a mail account
     *
     * An account the domain has answers 409 `E_EXISTS`. When `send_credentials_to`
     * is sent, `meta.credentials_sent` says whether the settings mail went out.
     *
     * @operationId createMailAccount
     *
     * @status 201
     */
    public function store(StoreMailAccountRequest $request, string $user, string $domain, Principal $principal, MailPolicy $policy, HostingCredentialsMailer $mailer): MailAccountResource|JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);
        abort_unless($policy->create($principal, $user), 403);
        $this->mail->find($user, $domain);
        $account = (string) $request->input('account');
        if ($this->known($user, $domain, $account)) {
            throw new ApiException(ErrorCode::Exists, sprintf(__('Mail account %s exists.'), $account), 409);
        }
        if ($request->exists('rate_limit') && ! $policy->updateRateLimit($principal, $user)) {
            throw new ApiException(ErrorCode::Forbidden, __('This account may not change the rate limit.'), 403);
        }
        $this->assertDiscard($request->boolean('discard_all'), $request->exists('forwards') ? $request->input('forwards') : []);

        $input = [
            'account' => $account,
            'password' => (string) $request->input('password'),
            'quota' => $request->exists('quota_mb') && $request->input('quota_mb') !== null ? (string) $request->integer('quota_mb') : '',
            'aliases' => array_values($request->input('aliases', [])),
            'forwards' => array_values($request->input('forwards', [])),
            'blackhole' => false,
            'fwd_only' => $request->boolean('forward_only'),
            'rate' => $request->exists('rate_limit') ? (string) ($request->input('rate_limit') ?? '') : null,
        ];
        if ($preview = $this->gate->execute($this->planner->planCreate($user, $domain, $input), $this->mail->apply(...))) {
            return $preview;
        }

        $resource = new MailAccountResource($this->mail->findAccount($user, $domain, $account));
        if ($request->exists('send_credentials_to') && $request->input('send_credentials_to') !== null) {
            $resource->additional(['meta' => ['credentials_sent' => $this->sendCredentials(
                $mailer, (string) $request->input('send_credentials_to'), $user, $domain, $account, $input['password'], $principal,
            )]]);
        }

        return $resource->response()->setStatusCode(201)->header('Location', route('api.v2.mail-domains.accounts.show', [
            'user' => $user, 'domain' => $domain, 'account' => $account,
        ]));
    }

    /**
     * Get a mail account
     *
     * @operationId getMailAccount
     */
    public function show(string $user, string $domain, string $account, Principal $principal, MailPolicy $policy): MailAccountResource
    {
        $this->assertOwner($user, $principal, $policy);
        $this->mail->find($user, $domain);

        return new MailAccountResource($this->mail->findAccount($user, $domain, $account));
    }

    /**
     * Update a mail account
     *
     * A field left out keeps its value. `aliases` and `forwards` replace the lists.
     * `discard_all` together with `forwards` is a validation error.
     *
     * @operationId updateMailAccount
     */
    public function update(UpdateMailAccountRequest $request, string $user, string $domain, string $account, Principal $principal, MailPolicy $policy): MailAccountResource|JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);
        abort_unless($policy->update($principal, $user), 403);
        $this->mail->find($user, $domain);
        $current = $this->mail->findAccount($user, $domain, $account);
        if ($request->exists('rate_limit') && ! $policy->updateRateLimit($principal, $user)) {
            throw new ApiException(ErrorCode::Forbidden, __('This account may not change the rate limit.'), 403);
        }
        if ($request->exists('discard_all') && $request->boolean('discard_all')) {
            $this->assertDiscard(true, $request->exists('forwards') ? $request->input('forwards') : []);
        }
        // An account that keeps discarding mail would drop the forwards silently.
        if (! $request->exists('discard_all') && $current->discards() && $request->input('forwards', []) !== []) {
            throw ValidationException::withMessages(['forwards' => __('Turn off discard_all to set forwards.')]);
        }

        $steps = [];
        if ($request->exists('quota_mb') || $request->exists('rate_limit')) {
            $steps = [...$steps, ...$this->planner->planGeneral($current, [
                'password' => '',
                'quota' => $request->exists('quota_mb')
                    ? ($request->input('quota_mb') === null ? '' : (string) $request->integer('quota_mb'))
                    : ($current->unlimitedQuota() ? '' : $current->quota),
                'rate' => $request->exists('rate_limit') ? (string) ($request->input('rate_limit') ?? '') : null,
            ])];
        }
        if ($request->exists('aliases') || $request->exists('forwards') || $request->exists('forward_only') || $request->exists('discard_all')) {
            $steps = [...$steps, ...$this->planner->planForwarding($current, [
                'aliases' => $request->exists('aliases') ? array_values($request->input('aliases')) : $current->aliases,
                'forwards' => $request->exists('forwards') ? array_values($request->input('forwards')) : $current->forwardAddresses(),
                'blackhole' => $request->exists('discard_all') ? $request->boolean('discard_all') : $current->discards(),
                'fwd_only' => $request->exists('forward_only') ? $request->boolean('forward_only') : $current->fwdOnly,
            ])];
        }
        if ($request->exists('autoreply')) {
            $text = $request->input('autoreply');
            $steps = [...$steps, ...$this->planner->planAutoreply($current, $this->mail->autoreplyMessage($current), [
                'autoreply' => $text !== null,
                'message' => (string) ($text ?? ''),
            ])];
        }

        return $this->gate->execute($steps, $this->mail->apply(...))
            ?? new MailAccountResource($this->mail->findAccount($user, $domain, $account));
    }

    /**
     * Change the password of a mail account
     *
     * @operationId changeMailAccountPassword
     *
     * @status 204
     */
    public function password(ChangeMailAccountPasswordRequest $request, string $user, string $domain, string $account, Principal $principal, MailPolicy $policy): Response|JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);
        abort_unless($policy->update($principal, $user), 403);
        $current = $this->mail->findAccount($user, $domain, $account);

        return $this->gate->execute($this->planner->planGeneral($current, [
            'password' => (string) $request->input('password'),
            'quota' => $current->unlimitedQuota() ? '' : $current->quota,
            'rate' => null,
        ]), $this->mail->apply(...)) ?? response()->noContent();
    }

    /**
     * Delete a mail account
     *
     * @operationId deleteMailAccount
     *
     * @status 204
     */
    public function destroy(string $user, string $domain, string $account, Principal $principal, MailPolicy $policy): Response|JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);
        abort_unless($policy->delete($principal, $user), 403);
        $this->mail->findAccount($user, $domain, $account);

        return $this->gate->execute([
            new Step(sprintf(__('Delete mail account %s'), $account), 'v-delete-mail-account', [$user, $domain, $account]),
        ], $this->mail->apply(...)) ?? response()->noContent();
    }

    /**
     * Suspend a mail account
     *
     * @operationId suspendMailAccount
     */
    public function suspend(string $user, string $domain, string $account, Principal $principal, MailPolicy $policy): MailAccountResource|JsonResponse
    {
        return $this->setSuspended($user, $domain, $account, true, $principal, $policy);
    }

    /**
     * Unsuspend a mail account
     *
     * @operationId unsuspendMailAccount
     */
    public function unsuspend(string $user, string $domain, string $account, Principal $principal, MailPolicy $policy): MailAccountResource|JsonResponse
    {
        return $this->setSuspended($user, $domain, $account, false, $principal, $policy);
    }

    private function setSuspended(string $user, string $domain, string $account, bool $suspend, Principal $principal, MailPolicy $policy): MailAccountResource|JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);
        abort_unless($policy->suspend($principal, $user), 403);
        $current = $this->mail->findAccount($user, $domain, $account);
        $steps = $current->suspended === $suspend ? [] : [$suspend
            ? new Step(sprintf(__('Suspend mail account %s'), $account), 'v-suspend-mail-account', [$user, $domain, $account])
            : new Step(sprintf(__('Unsuspend mail account %s'), $account), 'v-unsuspend-mail-account', [$user, $domain, $account])];

        return $this->gate->execute($steps, $this->mail->apply(...))
            ?? new MailAccountResource($this->mail->findAccount($user, $domain, $account));
    }

    private function assertOwner(string $user, Principal $principal, MailPolicy $policy): void
    {
        abort_unless($policy->view($principal, $user) && $this->actions->ownerExists($user), 404);
    }

    private function known(string $user, string $domain, string $account): bool
    {
        foreach ($this->mail->accounts($user, $domain) as $row) {
            if ($row->account === $account) {
                return true;
            }
        }

        return false;
    }

    private function assertDiscard(bool $discard, mixed $forwards): void
    {
        if ($discard && is_array($forwards) && $forwards !== []) {
            throw ValidationException::withMessages(['discard_all' => __('Discard all mail cannot be combined with forwards.')]);
        }
    }

    private function sendCredentials(HostingCredentialsMailer $mailer, string $to, string $user, string $domain, string $account, string $password, Principal $principal): bool
    {
        try {
            $record = $this->mail->find($user, $domain);
            $mailer->sendMailCredentials($to, [
                'account' => $account,
                'domain' => $domain,
                'password' => $password,
                'webmail' => $this->mail->webmailUrl($record),
                'hostname' => 'mail.'.$domain,
            ], $principal->language);

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
