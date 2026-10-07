<?php

namespace App\Http\Controllers\Mail;

use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Http\Requests\Mail\MailGeneralRequest;
use App\Http\Requests\Mail\MailRelayRequest;
use App\Http\Requests\Mail\MailSslRequest;
use App\Policies\MailPolicy;
use App\Services\MailDomains\MailActions;
use App\Services\MailDomains\MailDomainPlanner;
use App\Services\MailDomains\MailService;
use App\Services\WebDomains\ApplyResult;
use App\Services\WebDomains\Step;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

/**
 * The tabs of the mail domain edit page (web/edit/mail): one form per tab,
 * each running only the commands of the values it changed (spec §9.3).
 */
final class MailDomainEditController extends Controller
{
    public function __construct(
        private readonly MailService $mail,
        private readonly MailDomainPlanner $planner,
    ) {}

    public function general(MailGeneralRequest $request, string $user, string $domain, Principal $principal, MailPolicy $policy): RedirectResponse
    {
        abort_unless($policy->update($principal, $user), 403);
        $record = $this->mail->find($user, $domain);
        $systems = $this->mail->systems();
        $values = $request->values($policy->updateRateLimit($principal, $user));

        $client = $values['webmail'];
        if ($systems['webmail'] && $client !== null && $client !== $record->webmail) {
            try {
                app(MailActions::class)->assertWebmail($client);
            } catch (ValidationException $e) {
                throw $e->errorBag('general')->redirectTo($this->tabUrl($user, $domain, 'general'));
            }
        }

        return $this->finish($user, $domain, 'general', $this->mail->apply($this->planner->planGeneral($record, $values, $systems)));
    }

    public function ssl(MailSslRequest $request, string $user, string $domain, Principal $principal, MailPolicy $policy): RedirectResponse
    {
        abort_unless($policy->update($principal, $user), 403);
        $record = $this->mail->find($user, $domain);
        $values = $request->values();

        try {
            app(MailActions::class)->assertCertificate($record, $values);
        } catch (ValidationException $e) {
            throw $e->errorBag('ssl')->redirectTo($this->tabUrl($user, $domain, 'ssl'));
        }

        $certificate = $record->ssl ? $this->mail->ssl($user, $domain) : null;

        return $this->finish($user, $domain, 'ssl', $this->mail->apply($this->planner->planSsl($record, $certificate, $values)));
    }

    public function relay(MailRelayRequest $request, string $user, string $domain, Principal $principal, MailPolicy $policy): RedirectResponse
    {
        abort_unless($policy->update($principal, $user), 403);
        $record = $this->mail->find($user, $domain);

        return $this->finish($user, $domain, 'relay', $this->mail->apply($this->planner->planRelay($record, $request->values())));
    }

    /** Back to the tab with a toast; a failure names the step that failed. */
    private function finish(string $user, string $domain, string $tab, ApplyResult $result): RedirectResponse
    {
        $redirect = redirect()->to($this->tabUrl($user, $domain, $tab));
        if (! $result->ok()) {
            /** @var Step $failed */
            $failed = $result->failed;

            // Without the token, the other tabs show the stored values, not this form's.
            return $redirect->withInput(request()->except(['_token', 'key', 'relay_password']))
                ->with('error', sprintf(__('%s failed'), $failed->label))
                ->with('error_detail', $result->error);
        }

        return $redirect->with('ok', $result->changed() ? __('Changes have been saved.') : __('Nothing has changed.'));
    }

    private function tabUrl(string $user, string $domain, string $tab): string
    {
        return route('mail.edit', ['user' => $user, 'domain' => $domain]).'#'.$tab;
    }
}
