{{--
    Template pickers and the backend port of the Add form and the Advanced
    tab (E6 spec §7.2). The port field shows when the visitor-facing template
    (proxy template behind NGINX + Apache, web template on NGINX + PHP-FPM)
    takes a port. values: template, backend_template, proxy_template.
--}}
@php
    // After the note of a template whose runtime is missing (E5 spec D13)
    $runtimeLink = $runtimeInstallUrl !== null
        ? ['url' => $runtimeInstallUrl, 'label' => __('Install it on the System packages page.')]
        : ['url' => null, 'label' => __('Ask your provider to install it.')];
    $portSelected = $portField !== null ? (string) \App\View\FormField::old($portField, $values[$portField] ?? '') : '';
@endphp
<div class="ap-stack" x-data="{ selected: @js($portSelected), ports: @js($portTemplates) }"
    @if ($portField !== null) @change="if ($event.target.name === @js($portField)) selected = $event.target.value" @endif>
    <x-ap-card-picker name="template" :label="__('Web Template')" :options="$templateOptions" :value="$values['template']"
        :categories="$templateCategories" :searchable="count($templateOptions) > 8" :bag="$bag" :note-link="$runtimeLink" class="ap-picker-scroll" />
    @if ($systems['backend'])
        <div class="ap-form-grid">
            <x-ap-select name="backend_template" :label="__('Backend Template')" :options="array_combine($backendTemplates, $backendTemplates)" :value="$values['backend_template']" :bag="$bag" />
        </div>
    @endif
    @if ($systems['proxy'])
        <x-ap-card-picker name="proxy_template" :label="__('Proxy Template')" :options="$proxyOptions" :value="$values['proxy_template']"
            :categories="$templateCategories" :searchable="count($proxyOptions) > 8" :bag="$bag" :note-link="$runtimeLink" class="ap-picker-scroll" />
    @endif
    @if ($portField !== null && $portTemplates !== [])
        <div class="ap-form-grid" x-show="ports.includes(selected)" x-cloak>
            <x-ap-select name="backend_port" :label="__('App port')" :options="$portChoices" :value="$currentPort !== '' ? $currentPort : 'auto'" :bag="$bag"
                :hint="$portPoolExhausted
                    ? __('No free port is left in the pool. Ask your provider for more ports.')
                    : __('Your app must listen on 127.0.0.1 at this port. It is taken from your port pool.')" />
        </div>
    @endif
</div>
