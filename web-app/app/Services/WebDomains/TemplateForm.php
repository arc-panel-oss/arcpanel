<?php

namespace App\Services\WebDomains;

use App\Auth\Principal;
use App\Policies\ServerPolicy;
use App\Services\Ports\PortService;

/**
 * View data of the template pickers and the backend port field of the Add
 * and Edit forms (E6 spec §7.2).
 */
final class TemplateForm
{
    public function __construct(
        private readonly TemplateCatalog $catalog,
        private readonly PortService $ports,
        private readonly RuntimeAvailability $runtimes,
        private readonly Principal $principal,
        private readonly ServerPolicy $server,
    ) {}

    /** @return array<string, mixed> */
    public function data(string $owner, string $domain = '', ?string $currentWeb = null, ?string $currentProxy = null, string $currentPort = ''): array
    {
        $kind = $this->catalog->activeKind();
        $portTemplates = $kind !== null ? $this->catalog->portTemplates($kind) : [];
        $account = $portTemplates !== [] ? $this->ports->account($owner) : null;
        $available = fn (string $runtime): ?bool => $this->runtimes->available($runtime, $owner);

        return [
            'templateOptions' => $this->catalog->pickerOptions('web', $currentWeb, $available),
            'proxyOptions' => $this->catalog->pickerOptions('proxy', $currentProxy, $available),
            // Where a missing runtime is installed (E5 spec D13): administrators only
            'runtimeInstallUrl' => $this->server->managePackages($this->principal) ? route('server.packages') : null,
            'templateCategories' => $this->catalog->categories(),
            // Field name of the picker whose templates may take a port
            'portField' => match ($kind) {
                'web' => 'template',
                'proxy' => 'proxy_template',
                default => null,
            },
            'portTemplates' => $portTemplates,
            'portChoices' => $account !== null ? BackendPorts::choices($account, $domain, $currentPort) : [],
            'portPoolExhausted' => $account !== null && BackendPorts::poolExhausted($account),
            'currentPort' => $currentPort,
        ];
    }
}
