{{-- Backups hub, Settings (E48 spec §8.2): how archives are made and how many run at once. --}}
@php
    $mode = ($config['BACKUP_MODE'] ?? 'gzip') === 'zstd' ? 'zstd' : 'gzip';
    $levels = [];
    foreach (range(1, 19) as $level) {
        $levels[(string) $level] = $level > 9 ? sprintf('%d (%s)', $level, __('zstd only')) : (string) $level;
    }
@endphp
<x-ap-page :title="__('Backup settings')" :crumbs="[['label' => __('Server')], ['label' => __('Backups'), 'url' => route('server.backups')], ['label' => __('Settings')]]">
    <x-ap-form :action="route('server.backups.settings.update')" class="ap-stack">
        <fieldset class="ap-fieldset-plain ap-stack" x-data="{ mode: @js($mode) }" @disabled(! $canEdit)>
            <x-ap-card :title="__('Archives')" icon="archive">
                <div class="ap-form-grid">
                    <x-ap-select name="mode" :label="__('Compression')" :options="['gzip' => 'gzip', 'zstd' => 'zstd']" :value="$mode" x-model="mode"
                        :hint="__('zstd is faster and makes smaller archives than gzip.')" />
                    <div x-show="mode === 'zstd'">
                        <x-ap-select name="level" :label="__('Compression level')" :options="$levels" :value="$config['BACKUP_GZIP'] ?? '9'" x-bind:disabled="mode !== 'zstd'" />
                    </div>
                    <input type="hidden" name="level" value="9" x-bind:disabled="mode === 'zstd'">
                    <x-ap-input name="temp" :label="__('Temporary folder')" :value="$config['BACKUP_TEMP'] ?? ($config['BACKUP'] ?? '/backup')" mono required
                        :hint="__('Where archives are built before they go to their destinations. It needs room for the largest account.')" />
                </div>
            </x-ap-card>

            <x-ap-card :title="__('Load')" icon="cpu">
                <div class="ap-form-grid">
                    <x-ap-input name="parallel" type="number" min="1" max="16" :label="__('Accounts at once')" :value="$config['BACKUP_PARALLEL'] ?? '1'" mono required
                        :hint="__('How many accounts are backed up at the same time, across all jobs.')" />
                    <x-ap-input name="la_limit" type="number" min="1" max="512" :label="__('Load limit')" :value="$config['BACKUP_LA_LIMIT'] ?? ''" mono required
                        :hint="__('A backup waits while the load average of the server is above this; the default is the number of CPU cores.')" />
                </div>
                <x-ap-switch name="suspended" :label="__('Back up suspended accounts')" :checked="($config['POLICY_BACKUP_SUSPENDED_USERS'] ?? 'no') === 'yes'"
                    :description="__('Off leaves suspended accounts out of the jobs; their earlier backups stay.')" />
            </x-ap-card>

            <x-ap-card :title="__('Reports')" icon="mail">
                <x-ap-select name="report" :label="__('Mail to the administrator')" :value="$config['BACKUP_REPORT'] ?? 'failures'"
                    :options="['failures' => __('When a job run fails or is partial'), 'daily' => __('A daily summary at 07:00, and failures'), 'off' => __('None (notifications in the panel only)')]" />
            </x-ap-card>
        </fieldset>
        @if ($canEdit)
            <div class="ap-form-actions"><x-ap-button type="submit" variant="primary" icon="check">{{ __('Save') }}</x-ap-button></div>
        @endif
    </x-ap-form>
</x-ap-page>
