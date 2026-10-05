{{-- Living style guide (docs/design/05 §5): every x-ap-* component with sample props. --}}
@php
    $sampleLog = [
        '203.0.113.7 - - [05/Oct/2026:10:42:01 +0000] "GET / HTTP/2.0" 200 512',
        '203.0.113.7 - - [05/Oct/2026:10:42:07 +0000] "GET /missing HTTP/2.0" 404 162',
        '2026/10/05 10:42:19 [error] 123#123: *1 connect() failed (111: Connection refused)',
    ];
@endphp
<x-ap-page :title="__('Style guide')" eyebrow="/app/_styleguide" :description="__('Every building block of the panel app. Names match the Blade components in docs/design/05 §5.')"
    :crumbs="[['label' => __('Developer')], ['label' => __('Style guide')]]">
    <x-slot:actions>
        <x-ap-button :href="request()->fullUrlWithQuery(['theme' => 'nocturne'])" size="sm" variant="ghost">Nocturne</x-ap-button>
        <x-ap-button :href="request()->fullUrlWithQuery(['theme' => 'light'])" size="sm" variant="ghost">Light</x-ap-button>
        <x-ap-button :href="request()->fullUrlWithQuery(['theme' => 'auto'])" size="sm" variant="ghost">Auto</x-ap-button>
        <x-ap-button :href="request()->fullUrlWithQuery(['density' => request('density') === 'compact' ? 'comfortable' : 'compact'])" size="sm" variant="ghost">{{ __('Density') }}</x-ap-button>
        <x-ap-button :href="request()->fullUrlWithQuery(['dir' => request('dir') === 'rtl' ? 'ltr' : 'rtl'])" size="sm" variant="ghost">RTL</x-ap-button>
    </x-slot:actions>

    <section class="sg-section" id="foundations" aria-labelledby="sg-foundations">
        <header><span class="sg-tag">tokens.css · themes/*.css</span><h2 id="sg-foundations">{{ __('Foundations') }}</h2>
            <p>{{ __('Semantic colour tokens. Palette values live only in the themes; components never use a literal colour.') }}</p></header>
        <div class="sg-swatches">
            @foreach (['bg' => 'Background', 'bg-sunken' => 'Sunken', 'surface' => 'Surface', 'surface-raised' => 'Raised', 'border' => 'Border', 'fg' => 'Text', 'fg-muted' => 'Muted text', 'fg-subtle' => 'Subtle text', 'accent' => 'Accent', 'ok' => 'Success', 'warn' => 'Warning', 'danger' => 'Danger', 'info' => 'Info'] as $token => $name)
                <div class="sg-swatch"><i style="background: var(--ap-{{ $token }})"></i><span><b>{{ $name }}</b>--ap-{{ $token }}</span></div>
            @endforeach
        </div>
        <div class="sg-demo is-col sg-type">
            <div class="sg-type-row"><span class="mono subtle">2xl · 26/600</span><span class="sg-type-2xl">Web domains</span></div>
            <div class="sg-type-row"><span class="mono subtle">md · 14/400</span><span>The panel runs every change through the same v-* commands as the CLI.</span></div>
            <div class="sg-type-row"><span class="mono subtle">eyebrow</span><span class="eyebrow">Server · srv01.example.net</span></div>
            <div class="sg-type-row"><span class="mono subtle">mono</span><span class="mono">/home/alice/web/example-shop.com/public_html</span></div>
        </div>
    </section>

    <section class="sg-section" id="buttons" aria-labelledby="sg-buttons">
        <header><span class="sg-tag">x-ap-button · x-ap-icon</span><h2 id="sg-buttons">{{ __('Buttons') }}</h2></header>
        <div class="sg-demo">
            <x-ap-button variant="primary" icon="plus">Add domain</x-ap-button>
            <x-ap-button>Secondary</x-ap-button>
            <x-ap-button variant="ghost">Ghost</x-ap-button>
            <x-ap-button variant="danger" icon="trash" confirm="Delete example-shop.com?">Delete</x-ap-button>
            <x-ap-button :icon-only="true" icon="more" label="More actions" />
            <x-ap-button variant="primary" :loading="true">Saving…</x-ap-button>
            <x-ap-button href="#buttons" icon="external">Link</x-ap-button>
        </div>
        <div class="sg-demo">
            <x-ap-button variant="primary" size="sm" icon="check">Small primary</x-ap-button>
            <x-ap-button size="sm">Small</x-ap-button>
            <x-ap-button size="sm" disabled>Disabled</x-ap-button>
            @foreach (['globe', 'dns', 'mail', 'db', 'clock', 'archive', 'folder', 'server', 'shield', 'chevron-right', 'arrow'] as $icon)
                <x-ap-icon :name="$icon" />
            @endforeach
        </div>
    </section>

    <section class="sg-section" id="forms" aria-labelledby="sg-forms">
        <header><span class="sg-tag">x-ap-form · x-ap-field · inputs</span><h2 id="sg-forms">{{ __('Form fields') }}</h2></header>
        <x-ap-form action="#forms" method="GET" class="sg-demo is-col">
            <div class="ap-grid-2">
                <div class="ap-form">
                    <x-ap-input name="sg_domain" label="Domain" value="example-shop.com" hint="Without www. An alias for www is added for you." mono />
                    <x-ap-input name="sg_user" label="Username" value="Alice Shop" icon="user" required />
                    <x-ap-password name="sg_password" label="Password" :generate="true" />
                    <x-ap-input name="sg_root" label="Document root" prefix="/home/alice/web/" value="example-shop.com/public_html" mono />
                </div>
                <div class="ap-form">
                    <x-ap-select name="sg_package" label="Package" :options="['starter' => 'Starter', 'business' => 'Business']" value="business" />
                    <x-ap-unlimited-input name="sg_quota" label="Disk quota" value="10" suffix="GB" />
                    <x-ap-radio-group name="sg_backend" label="Web backend" :options="['fpm' => 'PHP-FPM', 'static' => 'Static']" value="fpm" />
                    <x-ap-checkbox name="sg_dns" label="Create DNS zone" :checked="true" />
                    <x-ap-textarea name="sg_notes" label="Notes" rows="2" />
                </div>
            </div>
            <div class="ap-settings">
                <x-ap-switch name="sg_force" label="Force HTTPS" description="Redirect every HTTP request to HTTPS." :checked="true" />
                <x-ap-switch name="sg_hsts" label="Enable HSTS" description="Browsers will refuse plain HTTP for a year." />
            </div>
        </x-ap-form>
    </section>

    <section class="sg-section" id="status" aria-labelledby="sg-status">
        <header><span class="sg-tag">x-ap-badge · x-ap-chip · x-ap-alert · x-ap-toast</span><h2 id="sg-status">{{ __('Badges, chips and alerts') }}</h2></header>
        <div class="sg-demo">
            <x-ap-badge tone="ok">Running</x-ap-badge><x-ap-badge tone="warn">Expiring</x-ap-badge><x-ap-badge tone="danger">Suspended</x-ap-badge>
            <x-ap-badge tone="info">Updating</x-ap-badge><x-ap-badge tone="accent">Reseller Pro</x-ap-badge><x-ap-badge>Stopped</x-ap-badge><x-ap-badge :mono="true">PHP 8.3</x-ap-badge>
            <x-ap-chip :active="true" remove-href="#status" remove-label="Remove filter Owner: alice">Owner: alice</x-ap-chip>
        </div>
        <div class="ap-stack">
            <x-ap-alert tone="info" title="Maintenance window tonight">Services restart between 02:00 and 02:15 server time.</x-ap-alert>
            <x-ap-alert tone="ok" title="Backup finished">2.1 GB written.</x-ap-alert>
            <x-ap-alert tone="warn" title="Disk at 82%">The root partition has 90 GB left.<x-slot:actions><x-ap-button size="sm">Open disk usage</x-ap-button></x-slot:actions></x-ap-alert>
            <x-ap-alert tone="danger" title="fail2ban is not running">Brute-force protection is off.</x-ap-alert>
        </div>
        <div class="sg-demo is-col sg-toasts">
            <x-ap-toast type="ok" title="Domain added" message="example-shop.com is live." />
            <x-ap-toast type="error" title="Could not restart nginx" message="Configuration test failed." />
        </div>
    </section>

    <section class="sg-section" id="usage" aria-labelledby="sg-usage">
        <header><span class="sg-tag">x-ap-usage-bar · x-ap-meter</span><h2 id="sg-usage">{{ __('Usage bars and meters') }}</h2></header>
        <div class="ap-usage-list">
            <x-ap-usage-bar label="Disk" :value="6.4" :limit="10" value-label="6.4 GB" limit-label="10 GB" />
            <x-ap-usage-bar label="Web domains" :value="4" :limit="5" />
            <x-ap-usage-bar label="Databases" :value="19" :limit="20" />
            <x-ap-usage-bar label="Mail accounts" :value="410" limit="unlimited" />
        </div>
        <div class="ap-grid">
            <x-ap-meter title="CPU" icon="cpu" :percent="23" value="23" unit="%" :meta="['8 cores']" />
            <x-ap-meter title="Memory" icon="memory" :percent="61" value="9.8" unit=" / 16 GB" :meta="['61% used']" />
            <x-ap-meter title="Disk" icon="disk" :percent="82" value="410" unit=" / 500 GB" :meta="['90 GB free']" />
            <x-ap-meter title="Swap" icon="layers" :percent="97" value="1.9" unit=" / 2 GB" />
        </div>
    </section>

    <section class="sg-section" id="table" aria-labelledby="sg-table">
        <header><span class="sg-tag">x-ap-table · x-ap-bulk-bar · x-ap-empty · x-ap-pager</span><h2 id="sg-table">{{ __('Data table') }}</h2></header>
        <x-ap-card :flush="true">
            <x-ap-bulk-bar table="sg-table" action="#table" :actions="['suspend' => ['label' => 'Suspend', 'icon' => 'pause'], 'delete' => ['label' => 'Delete', 'icon' => 'trash', 'danger' => true, 'confirm' => 'Delete %d domains?']]" />
            <x-ap-table id="sg-table" :selectable="true" search-label="Search domains" :columns="[
                ['key' => 'domain', 'label' => 'Domain', 'sortable' => true],
                ['key' => 'owner', 'label' => 'Owner', 'sortable' => true, 'optional' => true],
                ['key' => 'status', 'label' => 'Status'],
                ['key' => 'disk', 'label' => 'Disk', 'sortable' => true, 'type' => 'number', 'optional' => true],
            ]">
                @foreach ([['example-shop.com', 'alice', 'ok', 1200], ['minhkitchen.example', 'minh', 'ok', 3400], ['tranphoto.example', 'tran', 'danger', 820]] as [$domain, $owner, $tone, $disk])
                    <tr data-row data-search="{{ $domain }} {{ $owner }}">
                        <x-ap-row-check :value="$owner.'/'.$domain" :label="'Select '.$domain" form="sg-table-bulk" />
                        <td data-col="domain" data-label="Domain"><b class="mono">{{ $domain }}</b></td>
                        <td data-col="owner" data-label="Owner" class="muted">{{ $owner }}</td>
                        <td data-col="status" data-label="Status"><x-ap-badge :tone="$tone">{{ $tone === 'ok' ? 'Active' : 'Suspended' }}</x-ap-badge></td>
                        <td data-col="disk" data-label="Disk" class="col-num mono" data-sort-value="{{ $disk }}">{{ $disk }} MB</td>
                        <td class="col-actions"><x-ap-button :icon-only="true" variant="ghost" size="sm" icon="more" :label="'Actions for '.$domain" /></td>
                    </tr>
                @endforeach
                <x-slot:footer><x-ap-pager :page="1" :pages="3" :total="1203" url="#table" /></x-slot:footer>
            </x-ap-table>
        </x-ap-card>
        <x-ap-card :flush="true">
            <x-ap-empty icon="globe" title="No web domains yet">Add your first domain to create its document root, DNS zone and mail domain in one step.
                <x-slot:actions><x-ap-button variant="primary" icon="plus" href="#table">Add domain</x-ap-button></x-slot:actions>
            </x-ap-empty>
        </x-ap-card>
    </section>

    <section class="sg-section" id="navigation" aria-labelledby="sg-navigation">
        <header><span class="sg-tag">x-ap-tabs · x-ap-tab-panel</span><h2 id="sg-navigation">{{ __('Navigation') }}</h2></header>
        <x-ap-tabs label="Domain settings" :tabs="['sg-general' => 'General', 'sg-ssl' => 'SSL', 'sg-redirect' => 'Redirect']">
            <x-ap-tab-panel name="sg-general"><p class="muted">General settings panel.</p></x-ap-tab-panel>
            <x-ap-tab-panel name="sg-ssl"><p class="muted">SSL settings panel.</p></x-ap-tab-panel>
            <x-ap-tab-panel name="sg-redirect"><p class="muted">Redirect settings panel.</p></x-ap-tab-panel>
        </x-ap-tabs>
    </section>

    <section class="sg-section" id="overlays" aria-labelledby="sg-overlays">
        <header><span class="sg-tag">x-ap-modal · x-ap-drawer · x-ap-confirm</span><h2 id="sg-overlays">{{ __('Overlays') }}</h2></header>
        <div class="sg-demo">
            <x-ap-button x-on:click="$dispatch('ap-modal', { id: 'sg-modal' })">Open modal</x-ap-button>
            <x-ap-button x-on:click="$dispatch('ap-modal', { id: 'sg-drawer' })">Open drawer</x-ap-button>
            <form method="get" action="#overlays" data-confirm="This only reloads the style guide."><x-ap-button type="submit" variant="danger">Confirm dialog</x-ap-button></form>
        </div>
        <x-ap-modal id="sg-modal" title="Delete customer tran-photo?" description="Their domains, mailboxes and databases will be removed." icon="trash">
            <x-ap-input name="sg_confirm" label="Type tran-photo to confirm" mono />
            <x-slot:footer><x-ap-button variant="ghost" x-on:click="close()">Cancel</x-ap-button><x-ap-button variant="danger" icon="trash" x-on:click="close()">Delete customer</x-ap-button></x-slot:footer>
        </x-ap-modal>
        <x-ap-drawer id="sg-drawer" title="Job details"><p class="muted">Drawers hold secondary detail.</p></x-ap-drawer>
    </section>

    <section class="sg-section" id="feedback" aria-labelledby="sg-feedback">
        <header><span class="sg-tag">x-ap-skeleton</span><h2 id="sg-feedback">{{ __('Loading states') }}</h2></header>
        <x-ap-card :flush="true"><x-ap-skeleton :rows="3" /></x-ap-card>
    </section>

    <section class="sg-section" id="flows" aria-labelledby="sg-flows">
        <header><span class="sg-tag">x-ap-wizard · x-ap-card-picker · x-ap-cron-builder</span><h2 id="sg-flows">{{ __('Wizard, picker and cron') }}</h2></header>
        <x-ap-card>
            <form action="#flows" method="get">
                <x-ap-wizard :steps="['Account', 'Template', 'Schedule']">
                    <fieldset data-step><legend class="sr-only">Account</legend><x-ap-input name="sg_w_user" label="Username" required /></fieldset>
                    <fieldset data-step><legend class="sr-only">Template</legend>
                        <x-ap-card-picker name="sg_tpl" label="Web template" value="wordpress" :searchable="true" :options="[
                            'wordpress' => ['title' => 'WordPress', 'description' => 'PHP 8.3 · page cache', 'logo' => 'WP'],
                            'laravel' => ['title' => 'Laravel', 'description' => 'public/ document root', 'logo' => 'LV'],
                            'static' => ['title' => 'Static site', 'description' => 'nginx only', 'logo' => '{}'],
                        ]" />
                    </fieldset>
                    <fieldset data-step><legend class="sr-only">Schedule</legend><x-ap-cron-builder prefix="sg_" /></fieldset>
                </x-ap-wizard>
            </form>
        </x-ap-card>
    </section>

    <section class="sg-section" id="code" aria-labelledby="sg-code">
        <header><span class="sg-tag">x-ap-code · x-ap-log · x-ap-island</span><h2 id="sg-code">{{ __('Code and logs') }}</h2></header>
        <x-ap-code label="Commands"># Same change from the command line
$ v-add-web-domain alice example-shop.com</x-ap-code>
        <x-ap-log title="example-shop.com · access" :lines="$sampleLog" />
        <x-ap-island name="example" :props="['note' => 'Islands mount here (E3+).']" class="sg-island"><p class="muted">Island mount point</p></x-ap-island>
    </section>
</x-ap-page>
