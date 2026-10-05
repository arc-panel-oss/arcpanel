<?php

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;

/** Render a Blade snippet inside an element with the given direction. */
function component(string $blade, array $data = [], string $dir = 'ltr'): string
{
    return Blade::render('<div dir="'.$dir.'">'.$blade.'</div>', $data);
}

dataset('directions', ['ltr', 'rtl']);

it('renders buttons, links and one-button forms', function (string $dir) {
    $html = component('<x-ap-button variant="primary" icon="plus">Add</x-ap-button>', dir: $dir);
    expect($html)->toContain('class="ap-btn ap-btn-primary"')->toContain('#i-plus')->toContain('Add');

    $html = component('<x-ap-button :icon-only="true" icon="more" label="More actions" />', dir: $dir);
    expect($html)->toContain('aria-label="More actions"')->toContain('ap-btn-icon');

    $html = component('<x-ap-button href="/x" icon="chevron-right">Go</x-ap-button>', dir: $dir);
    expect($html)->toContain('<a href="/x"')->toContain('ap-icon--dir');

    $html = component('<x-ap-button form-action="/delete" form-method="DELETE" variant="danger" confirm="Sure?">Delete</x-ap-button>', dir: $dir);
    expect($html)->toContain('method="post" action="/delete"')->toContain('name="_method" value="DELETE"')
        ->toContain('data-confirm="Sure?"')->toContain('data-confirm-danger')->toContain('name="_token"');
})->with('directions');

it('renders form fields with labels, hints, errors and old input', function (string $dir) {
    $errors = new ViewErrorBag;
    $errors->put('default', new MessageBag(['domain' => ['Domain is required.']]));
    $errors->put('ssl', new MessageBag(['ftp.0.path' => ['Bad path.']]));
    view()->share('errors', $errors);

    $html = component('<x-ap-input name="domain" label="Domain" hint="Without www." required mono />', dir: $dir);
    expect($html)->toContain('for="f-domain"')
        ->toContain('aria-invalid="true"')->toContain('aria-describedby="f-domain-hint f-domain-error"')
        ->toContain('Domain is required.')->toContain('ap-input-mono')->toContain('required');

    $html = component('<x-ap-input name="ftp[0][path]" label="Path" bag="ssl" prefix="/home/" />', dir: $dir);
    expect($html)->toContain('id="f-ftp-0-path"')->toContain('Bad path.')->toContain('<span class="ap-addon">/home/</span>');

    $html = component('<x-ap-select name="ip" label="IP" :options="[\'1.1.1.1\' => \'one\']" value="2.2.2.2" />', dir: $dir);
    expect($html)->toContain('<option value="2.2.2.2" selected>')->toContain('<option value="1.1.1.1"');

    $html = component('<x-ap-textarea name="aliases" label="Aliases" value="a&lt;b" />', dir: $dir);
    expect($html)->toContain('<textarea')->toContain('a&amp;lt;b');

    $html = component('<x-ap-checkbox name="dns" label="DNS" :checked="true" />', dir: $dir);
    expect($html)->toContain('type="checkbox"')->toContain('checked');

    $html = component('<x-ap-radio-group name="code" label="Code" :options="[\'301\' => \'301\', \'302\' => \'302\']" value="302" />', dir: $dir);
    expect($html)->toContain('<legend class="ap-label">Code</legend>')->toContain('value="302" checked');

    $html = component('<x-ap-switch name="hsts" label="HSTS" description="One year." />', dir: $dir);
    expect($html)->toContain('role="switch"')->toContain('aria-describedby="f-hsts-hint"');

    $html = component('<x-ap-password name="pw" label="Password" :generate="true" />', dir: $dir);
    expect($html)->toContain('x-data="apPassword()"')->toContain('type="password"')->toContain('Generate')->toContain('ap-strength')
        ->not->toContain('value=');

    $html = component('<x-ap-unlimited-input name="quota" label="Quota" value="unlimited" suffix="GB" />', dir: $dir);
    expect($html)->toContain('name="quota_unlimited"')->toContain('disabled')->toContain('apUnlimited({ unlimited: true })');

    $html = component('<x-ap-form action="/save" method="PATCH" confirm="Save?" :focus-first="true">x</x-ap-form>', dir: $dir);
    expect($html)->toContain('name="_method" value="PATCH"')->toContain('data-confirm="Save?"')->toContain('data-focus-first');
})->with('directions');

it('renders status components with text, never colour alone', function (string $dir) {
    expect(component('<x-ap-badge tone="ok">Running</x-ap-badge>', dir: $dir))->toContain('ap-badge is-ok')->toContain('Running');
    expect(component('<x-ap-chip :active="true" remove-href="/x" remove-label="Clear">Owner: bob</x-ap-chip>', dir: $dir))
        ->toContain('ap-chip is-on')->toContain('aria-label="Clear"');
    expect(component('<x-ap-alert tone="danger" title="Down">nginx stopped</x-ap-alert>', dir: $dir))
        ->toContain('role="alert"')->toContain('<b>Down</b>');
    expect(component('<x-ap-alert tone="warn" title="Disk">82%</x-ap-alert>', dir: $dir))->toContain('role="status"');
    expect(component('<x-ap-toast type="error" title="Failed" message="Details" />', dir: $dir))
        ->toContain('role="alert"')->toContain('sticky: true')->toContain('aria-label="Dismiss"');
    expect(component('<x-ap-toast type="ok" title="Saved" />', dir: $dir))->toContain('role="status"')->toContain('sticky: false');
    expect(component('<x-ap-empty icon="globe" title="Nothing">Add one.</x-ap-empty>', dir: $dir))->toContain('<h3>Nothing</h3>');
    expect(component('<x-ap-skeleton :rows="2" />', dir: $dir))->toContain('aria-busy="true"')->toContain('ap-skel-line');
})->with('directions');

it('renders usage bars and meters with warn and danger levels', function (string $dir) {
    $html = component('<x-ap-usage-bar label="Disk" :value="8" :limit="10" value-label="8 GB" limit-label="10 GB" />', dir: $dir);
    expect($html)->toContain('ap-usage is-warn')->toContain('aria-valuenow="80"')->toContain('8 GB / 10 GB');

    expect(component('<x-ap-usage-bar label="DB" :value="19" :limit="20" />', dir: $dir))->toContain('is-danger')->toContain('aria-valuenow="95"');
    expect(component('<x-ap-usage-bar label="Mail" :value="4" limit="unlimited" />', dir: $dir))->toContain('is-unlimited')->toContain('4 / Unlimited');
    expect(component('<x-ap-meter title="CPU" icon="cpu" :percent="97" value="97" unit="%" />', dir: $dir))
        ->toContain('ap-gauge is-danger')->toContain('role="meter"')->toContain('stroke-dasharray: 97 100');
})->with('directions');

it('renders the data table, row selection and bulk bar', function (string $dir) {
    $html = component(<<<'BLADE'
        <x-ap-bulk-bar table="t" action="/bulk" :actions="['delete' => ['label' => 'Delete', 'danger' => true, 'confirm' => 'Delete %d?']]" />
        <x-ap-table id="t" :selectable="true" :columns="[['key' => 'name', 'label' => 'Name', 'sortable' => true], ['key' => 'size', 'label' => 'Size', 'type' => 'number', 'optional' => true]]">
            <tr data-row><x-ap-row-check value="bob/a" label="Select a" form="t-bulk" /><td data-col="name">a</td><td data-col="size">1</td><td></td></tr>
        </x-ap-table>
        BLADE, dir: $dir);

    expect($html)->toContain('x-data="apTable({ id: ')->toContain('data-select-all')->toContain('aria-label="Select all rows"')
        ->toContain('x-bind:aria-sort="ariaSort(')->toContain('data-sort-type="number"')->toContain('data-optional')
        ->toContain('name="items[]" value="bob/a" form="t-bulk"')
        ->toContain('id="t-bulk"')->toContain('data-confirm="Delete %d?"')->toContain('Columns');

    expect(component('<x-ap-pager :page="2" :pages="3" :total="1200" :per-page="500" url="/list?owner=bob" />', dir: $dir))
        ->toContain('501–1000')->toContain('href="/list?owner=bob&amp;page=3"')->toContain('aria-current="page">2');
})->with('directions');

it('renders tabs, dialogs, code, logs and the remaining components', function (string $dir) {
    $html = component('<x-ap-tabs label="Settings" :tabs="[\'general\' => \'General\', \'ssl\' => \'SSL\']"><x-ap-tab-panel name="general">G</x-ap-tab-panel><x-ap-tab-panel name="ssl">S</x-ap-tab-panel></x-ap-tabs>', dir: $dir);
    expect($html)->toContain('role="tablist" aria-label="Settings"')->toContain('href="#ssl"')->toContain('role="tabpanel" id="ssl" aria-labelledby="tab-ssl"')
        ->toContain('aria-selected="true">General');

    expect(component('<x-ap-modal id="m" title="Delete?" description="Sure.">Body<x-slot:footer>F</x-slot:footer></x-ap-modal>', dir: $dir))
        ->toContain('role="dialog" aria-modal="true"')->toContain('aria-labelledby="m-title"')->toContain('aria-describedby="m-desc"');
    expect(component('<x-ap-drawer id="d" title="Details">x</x-ap-drawer>', dir: $dir))->toContain('class="ap-drawer"');
    expect(component('<x-ap-confirm />', dir: $dir))->toContain('role="alertdialog"')->toContain('apConfirm()');
    expect(component('<x-ap-code label="cmd">v-list-users</x-ap-code>', dir: $dir))->toContain('<pre class="ap-code"')->toContain('aria-label="cmd"');
    expect(component('<x-ap-log :lines="[\'[error] x\', \'ok\']" title="access" url="/log" download="/dl" />', dir: $dir))
        ->toContain('ap-log-line is-error')->toContain('role="log"')->toContain('href="/dl"');
    expect(component('<x-ap-cron-builder prefix="c_" />', dir: $dir))->toContain('name="c_min"')->toContain('name="c_wday"')->toContain('apCronBuilder');
    expect(component('<x-ap-card-picker name="tpl" label="Template" value="b" :options="[\'a\' => [\'title\' => \'A\'], \'b\' => [\'title\' => \'B\', \'logo\' => \'BB\']]" />', dir: $dir))
        ->toContain('<legend class="ap-label">Template</legend>')->toContain('value="b" checked')->toContain('BB');
    expect(component('<x-ap-wizard :steps="[\'One\', \'Two\']"><fieldset data-step>1</fieldset></x-ap-wizard>', dir: $dir))
        ->toContain('class="ap-steps"')->toContain('apWizard()');
    expect(component('<x-ap-rrd-graph url="/rrd" service="la" title="Load" />', dir: $dir))->toContain('apRrdGraph({ url: ')->toContain('<canvas');
    expect(component('<x-ap-island name="files" :props="[\'path\' => \'/home\']" />', dir: $dir))
        ->toContain('data-island="files"')->toContain('data-props="{&quot;path&quot;:&quot;/home&quot;}"');
    expect(component('<x-ap-card title="Card" icon="globe">Body<x-slot:footer>Foot</x-slot:footer></x-ap-card>', dir: $dir))
        ->toContain('>Card</h2>')->toContain('aria-labelledby="card-')->toContain('ap-card-foot');
    expect(component('<x-ap-icon name="arrow" />', dir: $dir))->toContain('ap-icon--dir')->toContain('aria-hidden="true"');
    expect(component('<x-ap-i18n />', dir: $dir))->toContain('window.__ap')->toContain('Dismiss');
})->with('directions');
