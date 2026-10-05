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
use App\Services\Ports\PortService;
use App\Services\UserDirectory;
use App\Services\WebDomains\ApplyResult;
use App\Services\WebDomains\BackendPorts;
use App\Services\WebDomains\Input;
use App\Services\WebDomains\Step;
use App\Services\WebDomains\Tabs\AdvancedTab;
use App\Services\WebDomains\Tabs\FtpTab;
use App\Services\WebDomains\Tabs\GeneralTab;
use App\Services\WebDomains\Tabs\RedirectTab;
use App\Services\WebDomains\Tabs\SslTab;
use App\Services\WebDomains\Tabs\StatsTab;
use App\Services\WebDomains\Tabs\TemplateVarsTab;
use App\Services\WebDomains\TemplateCatalog;
use App\Services\WebDomains\TemplateForm;
use App\Services\WebDomains\WebDomainService;
use App\View\Navigation;
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

    public function edit(string $user, string $domain, Principal $principal, WebDomainPolicy $policy, SysConfig $config, UserDirectory $directory, TemplateForm $form, FilePolicy $files): View
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
            'backendTemplates' => $can['updateTemplates'] ? $this->web->backendTemplates() : [],
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
            'appInstallerUrl' => $config->get('PLUGIN_APP_INSTALLER') !== 'false' && $can['update'] && $user === $principal->effectiveUser()
                ? Navigation::url('web.apps.install').'?'.http_build_query(['domain' => $domain, 'token' => $principal->legacyToken])
                : null,
            'isAdminView' => $user !== $principal->effectiveUser(),
        ]);
    }

    public function general(GeneralRequest $request, string $user, string $domain, Principal $principal, WebDomainPolicy $policy, GeneralTab $tab): RedirectResponse
    {
        abort_unless($policy->update($principal, $user), 403);
        $record = $this->web->find($user, $domain);

        $values = $request->values();
        $userIps = $this->web->userIps($user);
        if (! array_key_exists($values['ip'], $userIps)) {
            throw ValidationException::withMessages(['ip' => __('Please select a valid IP address.')])
                ->errorBag('general')->redirectTo($this->tabUrl($user, $domain, 'general'));
        }
        if ($values['docroot_custom'] && ! in_array($values['docroot_domain'], array_keys($this->web->forUser($user)), true)) {
            throw ValidationException::withMessages(['docroot_domain' => __('Please select one of your domains.')])
                ->errorBag('general')->redirectTo($this->tabUrl($user, $domain, 'general'));
        }

        return $this->finish($user, $domain, 'general', $this->web->apply($tab->plan($record, $values, $userIps, $this->web)));
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

    public function advanced(AdvancedRequest $request, string $user, string $domain, Principal $principal, WebDomainPolicy $policy, AdvancedTab $tab, TemplateVarsTab $varsTab, PortService $ports): RedirectResponse
    {
        abort_unless($policy->updateTemplates($principal, $user), 403);
        $record = $this->web->find($user, $domain);
        $values = $request->values();

        $known = [
            'template' => $this->catalog->names('web'),
            'backend_template' => $this->web->backendTemplates(),
            'proxy_template' => $this->catalog->names('proxy'),
        ];
        foreach ($known as $field => $names) {
            if (($values[$field] ?? '') !== '' && $values[$field] !== null && ! in_array($values[$field], $names, true)) {
                throw ValidationException::withMessages([$field => __('Please select a valid template.')])
                    ->errorBag('advanced')->redirectTo($this->tabUrl($user, $domain, 'advanced'));
            }
        }

        $steps = $tab->plan($record, $values, $this->web->systems());

        // Backend port of the visitor-facing template (E6 spec §7.2)
        $kind = $this->catalog->activeKind();
        $active = TemplateVarsTab::activeTemplate($kind, $record, $values);
        $meta = $active !== null ? $this->catalog->find((string) $kind, $active) : null;
        $vars = $kind !== null ? $this->web->templateVars($user, $domain)['variables'] : [];
        $port = (string) $request->input('backend_port', '');
        $key = $meta?->portVariable();
        if ($key !== null && $port !== '' && ! BackendPorts::accepts($ports->account($user), $domain, $vars[$key] ?? '', $port)) {
            throw ValidationException::withMessages(['backend_port' => __('Please select one of the ports of the list.')])
                ->errorBag('advanced')->redirectTo($this->tabUrl($user, $domain, 'advanced'));
        }
        $changed = $active !== TemplateVarsTab::currentTemplate($kind, $record);
        $steps = [...$steps, ...$varsTab->plan($record, $meta, $port, $vars, $changed)];

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

    private function tabUrl(string $user, string $domain, string $tab): string
    {
        return route('web.domains.edit', ['user' => $user, 'domain' => $domain]).'#'.$tab;
    }
}
