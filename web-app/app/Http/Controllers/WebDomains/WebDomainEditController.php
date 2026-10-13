<?php

namespace App\Http\Controllers\WebDomains;

use App\Arc\SysConfig;
use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Http\Requests\WebDomains\AdvancedRequest;
use App\Http\Requests\WebDomains\FtpRequest;
use App\Http\Requests\WebDomains\GeneralRequest;
use App\Http\Requests\WebDomains\RedirectRequest;
use App\Http\Requests\WebDomains\SslRequest;
use App\Http\Requests\WebDomains\StatsRequest;
use App\Policies\FilePolicy;
use App\Policies\WebDomainPolicy;
use App\Services\Mail\CredentialsMailer;
use App\Services\Php\PhpManager;
use App\Services\UserDirectory;
use App\Services\WebDomains\ApplyResult;
use App\Services\WebDomains\Input;
use App\Services\WebDomains\Step;
use App\Services\WebDomains\Tabs\FtpTab;
use App\Services\WebDomains\Tabs\RedirectTab;
use App\Services\WebDomains\Tabs\SslTab;
use App\Services\WebDomains\Tabs\StatsTab;
use App\Services\WebDomains\Tabs\TemplateVarsTab;
use App\Services\WebDomains\TemplateCatalog;
use App\Services\WebDomains\TemplateForm;
use App\Services\WebDomains\WebDomainActions;
use App\Services\WebDomains\WebDomainService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * The edit page of a web domain (web/edit/web): one form per tab, each
 * running only the commands of the values it changed (D7 of the E1-S2 spec).
 */
final class WebDomainEditController extends Controller
{
    public function __construct(
        private readonly WebDomainService $web,
        private readonly TemplateCatalog $catalog,
    ) {}

    public function edit(string $user, string $domain, Principal $principal, WebDomainPolicy $policy, SysConfig $config, UserDirectory $directory, TemplateForm $form, FilePolicy $files, PhpManager $php, WebDomainActions $actions): View
    {
        abort_unless($policy->view($principal, $user), 403);
        $record = $this->web->find($user, $domain);
        $can = $policy->abilities($principal, $user);
        $systems = $this->web->systems();

        $userIps = $this->web->userIps($user);
        $ipOptions = [];
        foreach ($userIps as $ip => $row) {
            $nat = trim((string) ($row['NAT'] ?? ''));
            $ipOptions[$ip] = $nat !== '' ? "{$ip} ({$nat})" : $ip;
        }

        $ownDomains = array_keys($this->web->forUser($user));

        // Visitor-facing template and its backend port (E6)
        $kind = $this->catalog->activeKind();
        $active = TemplateVarsTab::currentTemplate($kind, $record);
        $activeMeta = $active !== null ? $this->catalog->find((string) $kind, $active) : null;
        $portKey = $activeMeta?->portVariable();
        $currentPort = $portKey !== null ? ($this->web->templateVars($user, $domain)['variables'][$portKey] ?? '') : '';

        // PHP tab (E41 §8): the version and profile within the package
        $domainPhp = $can['viewPhp'] && $config->get('WEB_BACKEND') !== '' ? $php->domain($user, $domain) : null;

        return view('web.edit', [
            'record' => $record,
            'ssl' => $record->ssl ? $this->web->ssl($user, $domain) : null,
            'can' => $can,
            'systems' => $systems,
            'publicIp' => $this->web->publicIp($record->ip, $userIps),
            'ipOptions' => $ipOptions,
            'domainOptions' => array_combine($ownDomains, $ownDomains),
            'docroot' => $record->customDocrootParts(),
            'redirectChoice' => RedirectTab::choice($record),
            'backendTemplates' => $can['updateTemplates'] ? $actions->backendTemplatesFor($principal, $user, $record->backendTemplate) : [],
            'activeTemplate' => $active !== null ? $this->catalog->title((string) $kind, $active) : $this->catalog->title('web', $record->template),
            'activePort' => $currentPort,
            'activePortMissing' => $portKey !== null && $currentPort === '',
            ...($can['updateTemplates'] ? $form->data($user, $domain, $record->template, $record->proxyTemplate ?: 'default', $currentPort) : []),
            'statsEngines' => $this->web->statsEngines(),
            'canPurge' => $this->web->canPurgeCache(),
            'ftpPrefix' => $user.'_',
            // File manager (E3 spec §7.7): the document root of the domain
            'fileManagerUrl' => $config->is('FILE_MANAGER', 'true') && $files->view($principal, $user)
                ? route('files.show', ['user' => $user, 'path' => 'web/'.$domain.'/public_html'])
                : null,
            // Quick Install (E7 batch 2 spec §10.3)
            'appInstallerUrl' => $config->get('PLUGIN_APP_INSTALLER') !== 'false' && $policy->installApp($principal, $user)
                ? route('web.domains.install', ['user' => $user, 'domain' => $domain])
                : null,
            'isAdminView' => $user !== $principal->effectiveUser(),
            'php' => $domainPhp,
            'phpProfiles' => $domainPhp !== null ? array_intersect_key($php->profiles(), array_flip([...$domainPhp['profiles'], $domainPhp['profile']])) : [],
        ]);
    }

    public function general(GeneralRequest $request, string $user, string $domain, Principal $principal, WebDomainPolicy $policy, WebDomainActions $actions): RedirectResponse
    {
        abort_unless($policy->update($principal, $user), 403);
        $record = $this->web->find($user, $domain);

        $values = $request->values();
        $steps = $this->onTab('general', $user, $domain, function () use ($actions, $record, $values, $user) {
            $userIps = $actions->checkIp($user, $values['ip']);
            $actions->checkDocroot($user, $values);

            return $actions->planGeneral($record, $values, $userIps);
        });

        return $this->finish($user, $domain, 'general', $this->web->apply($steps));
    }

    public function ssl(SslRequest $request, string $user, string $domain, Principal $principal, WebDomainPolicy $policy, SslTab $tab): RedirectResponse
    {
        abort_unless($policy->update($principal, $user), 403);
        $record = $this->web->find($user, $domain);
        $values = $request->values();

        // An uploaded certificate needs the certificate and its key.
        if ($values['ssl'] && ! $values['letsencrypt'] && ! $record->ssl) {
            $missing = [];
            if (Input::pem($values['certificate']) === '') {
                $missing['certificate'] = sprintf(__('Field "%s" can not be blank.'), __('SSL Certificate'));
            }
            if (Input::pem($values['key']) === '') {
                $missing['key'] = sprintf(__('Field "%s" can not be blank.'), __('SSL Private Key'));
            }
            if ($missing !== []) {
                throw ValidationException::withMessages($missing)->errorBag('ssl')->redirectTo($this->tabUrl($user, $domain, 'ssl'));
            }
        }

        $certificate = $record->ssl ? $this->web->ssl($user, $domain) : null;

        return $this->finish($user, $domain, 'ssl', $this->web->apply($tab->plan($record, $certificate, $values)));
    }

    public function redirect(RedirectRequest $request, string $user, string $domain, Principal $principal, WebDomainPolicy $policy, RedirectTab $tab): RedirectResponse
    {
        abort_unless($policy->update($principal, $user), 403);
        $record = $this->web->find($user, $domain);

        return $this->finish($user, $domain, 'redirect', $this->web->apply($tab->plan($record, $request->values())));
    }

    public function advanced(AdvancedRequest $request, string $user, string $domain, Principal $principal, WebDomainPolicy $policy, WebDomainActions $actions): RedirectResponse
    {
        abort_unless($policy->updateTemplates($principal, $user), 403);
        $record = $this->web->find($user, $domain);
        $values = $request->values();
        $port = (string) $request->input('backend_port', '');

        $steps = $this->onTab('advanced', $user, $domain, function () use ($actions, $record, $values, $port, $user, $domain, $principal) {
            $actions->checkTemplates($values);
            // A PHP version of the package only (E41 §7.5)
            $actions->checkBackendTemplate($principal, $user, $values['backend_template'] ?? null, $record->backendTemplate);
            // Backend port of the visitor-facing template (E6 spec §7.2)
            $actions->checkPort($user, $domain, $record, $values, $port);

            return $actions->planTemplates($record, $values, $port);
        });

        $result = $this->web->apply($steps);
        if (! $result->ok() && $result->failed?->command === TemplateVarsTab::COMMAND) {
            return redirect()->to($this->tabUrl($user, $domain, 'advanced'))->withInput()
                ->withErrors(['backend_port' => TemplateVarsTab::error($result->exitCode, $result->error, $user)], 'advanced')
                ->with('error', sprintf(__('%s failed'), $result->failed->label));
        }

        return $this->finish($user, $domain, 'advanced', $result);
    }

    public function ftp(FtpRequest $request, string $user, string $domain, Principal $principal, WebDomainPolicy $policy, FtpTab $tab, CredentialsMailer $mailer, UserDirectory $directory): RedirectResponse
    {
        abort_unless($policy->update($principal, $user) && $this->web->systems()['ftp'], 403);
        $record = $this->web->find($user, $domain);
        $language = (string) (($directory->all()[$user] ?? [])['LANGUAGE'] ?? '');

        $mail = fn (string $email, string $username, string $password) => $mailer->sendFtpCredentials($email, $domain, $username, $password, $language);

        return $this->finish($user, $domain, 'ftp', $this->web->apply($tab->plan($record, $request->values(), $mail)));
    }

    public function stats(StatsRequest $request, string $user, string $domain, Principal $principal, WebDomainPolicy $policy, StatsTab $tab): RedirectResponse
    {
        abort_unless($policy->update($principal, $user), 403);
        $record = $this->web->find($user, $domain);
        $values = $request->values();

        if (! in_array($values['type'], $this->web->statsEngines(), true)) {
            throw ValidationException::withMessages(['type' => __('Please select a valid statistics engine.')])
                ->errorBag('stats')->redirectTo($this->tabUrl($user, $domain, 'stats'));
        }
        if ($values['auth'] && $record->statsUser === '' && (string) $values['password'] === '') {
            throw ValidationException::withMessages(['password' => sprintf(__('Field "%s" can not be blank.'), __('Password'))])
                ->errorBag('stats')->redirectTo($this->tabUrl($user, $domain, 'stats'));
        }

        return $this->finish($user, $domain, 'stats', $this->web->apply($tab->plan($record, $values)));
    }

    /** Back to the tab with a toast; a failure names the step that failed. */
    private function finish(string $user, string $domain, string $tab, ApplyResult $result): RedirectResponse
    {
        $redirect = redirect()->to($this->tabUrl($user, $domain, $tab));
        if (! $result->ok()) {
            /** @var Step $failed */
            $failed = $result->failed;

            return $redirect->withInput(request()->except(['password', 'key', 'new.password', 'accounts']))
                ->with('error', sprintf(__('%s failed'), $failed->label))
                ->with('error_detail', $result->error);
        }

        return $redirect->with('ok', $result->changed() ? __('Changes have been saved.') : __('Nothing has changed.'));
    }

    /**
     * Run the checks and plan of a tab; their validation errors go to the
     * tab's error bag and back to the tab.
     *
     * @template T
     *
     * @param  \Closure(): T  $plan
     * @return T
     */
    private function onTab(string $tab, string $user, string $domain, \Closure $plan): mixed
    {
        try {
            return $plan();
        } catch (ValidationException $e) {
            throw $e->errorBag($tab)->redirectTo($this->tabUrl($user, $domain, $tab));
        }
    }

    private function tabUrl(string $user, string $domain, string $tab): string
    {
        return route('web.domains.edit', ['user' => $user, 'domain' => $domain]).'#'.$tab;
    }
}
