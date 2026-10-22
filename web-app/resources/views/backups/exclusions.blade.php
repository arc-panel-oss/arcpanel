{{-- Backup exclusions (web/edit/backup/exclusions, E7 batch 1 spec §10.2 and D5) as structured lists (E48 spec §8.1). --}}
@php
    $locked = ! $can['exclusions'];
    $kinds = [
        'WEB' => [__('Web Domains'), 'globe', __('Leave a whole web domain out, or only some of its folders, for example public_html/cache tmp.'), __('Folders to leave out (empty: the whole domain)')],
        'MAIL' => [__('Mail Domains'), 'mail', __('Leave a whole mail domain out, or only some of its accounts.'), __('Accounts to leave out (empty: the whole domain)')],
        'DB' => [__('Databases'), 'db', __('Leave a database out of the backup.'), null],
    ];
    $old = fn (string $field, mixed $default) => \App\View\FormField::submitted() ? old($field) : $default;
@endphp
<x-ap-page :title="__('Backup Exclusions')" :crumbs="[['label' => __('Backups'), 'url' => route('backups.overview', ['user' => $user])], ['label' => __('Exclusions')]]"
    :eyebrow="__('Owner').': '.$user" :description="__('What the backups of this account leave out. Everything else is backed up.')">

    @include('backups.partials.nav', ['current' => 'exclusions'])

    @if ($incremental)
        <x-ap-alert>{{ __('Snapshots ignore exclusions.') }}</x-ap-alert>
    @endif

    <x-ap-form :action="route('backups.exclusions.update', ['user' => $user])" class="ap-stack">
        <fieldset class="ap-fieldset-plain ap-stack" @disabled($locked)>
            @foreach ($kinds as $key => [$label, $icon, $hint, $partsLabel])
                @php($field = strtolower($key))
                @php($list = $lists[$key])
                @php($all = (bool) $old($field.'_all', $list['all']))
                <x-ap-card :title="$label" :icon="$icon" x-data="{ all: {{ $all ? 'true' : 'false' }} }" data-exclusions="{{ $field }}">
                    <p class="muted">{{ $hint }}</p>
                    <x-ap-switch :name="$field.'_all'" :label="sprintf(__('Leave out all %s'), mb_strtolower($label))" :checked="$list['all']" x-model="all" />
                    @if ($list['rows'] === [])
                        <p class="muted" x-show="!all">{{ __('This account has none.') }}</p>
                    @else
                        <ul class="ap-list ap-exclusion-list" x-show="!all">
                            @foreach ($list['rows'] as $name => $row)
                                @php($checked = \App\View\FormField::submitted() ? in_array($name, (array) old($field, []), true) : $row['excluded'])
                                <li x-data="{ on: {{ $checked ? 'true' : 'false' }} }">
                                    <label class="ap-check"><input type="checkbox" name="{{ $field }}[]" value="{{ $name }}" x-model="on" x-bind:disabled="all" @checked($checked)>
                                        <span class="mono">{{ $name }}</span></label>
                                    @if ($partsLabel !== null)
                                        <input class="ap-input ap-input-mono" type="text" name="{{ $field }}_sub[{{ $name }}]" value="{{ \App\View\FormField::submitted() ? ((array) old($field.'_sub', []))[$name] ?? '' : $row['parts'] }}"
                                            aria-label="{{ $partsLabel }}: {{ $name }}" placeholder="{{ $partsLabel }}" x-show="on" x-bind:disabled="all || !on" autocomplete="off" spellcheck="false">
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                    @error($field)<span class="ap-error"><x-ap-icon name="x-circle" size="sm" />{{ $message }}</span>@enderror
                    @foreach ($errors->getMessages() as $errorKey => $messages)
                        @if (str_starts_with($errorKey, $field.'_sub.') || str_starts_with($errorKey, $field.'.'))
                            <span class="ap-error"><x-ap-icon name="x-circle" size="sm" />{{ $messages[0] }}</span>
                        @endif
                    @endforeach
                </x-ap-card>
            @endforeach

            <x-ap-card :title="__('Cron jobs and folders')" icon="folder">
                <x-ap-switch name="cron" :label="__('Leave out the cron jobs')" :checked="in_array('*', $exclusions->get('CRON'), true)"
                    :description="__('Restoring a backup then keeps the cron jobs the account has at that time.')" />
                @foreach ($toggles as $path => $toggle)
                    {{-- x-ap-switch markup; the name is an array, so ids and the checked state are set here --}}
                    <label class="ap-setting" for="toggle-{{ $loop->index }}">
                        <span class="ap-setting-text">
                            <b>{{ $toggle['label'] }}</b>
                            <span id="toggle-{{ $loop->index }}-hint">{{ $toggle['hint'] }}</span>
                        </span>
                        <span class="ap-switch">
                            <input type="checkbox" role="switch" id="toggle-{{ $loop->index }}" name="toggles[]" value="{{ $path }}"
                                aria-describedby="toggle-{{ $loop->index }}-hint" @checked(is_array(old('toggles')) ? in_array($path, old('toggles'), true) : $toggle['on'])>
                        </span>
                    </label>
                @endforeach
                <x-ap-textarea name="userdir" :label="__('Folders of the home directory')" rows="4" mono :value="$userLines"
                    :hint="__('One folder per line, relative to the home directory, for example tmp or .cache. * leaves out every folder that is not part of a web domain or a mail account.')" />
                @if ($exclusions->get('DNS') !== [])
                    <p class="muted">{{ __('DNS exclusions set from the command line are kept.') }}</p>
                @endif
            </x-ap-card>
        </fieldset>
        @if (! $locked)
            <div class="ap-form-actions">
                <x-ap-button type="submit" variant="primary" icon="check">{{ __('Save') }}</x-ap-button>
            </div>
        @endif
    </x-ap-form>
</x-ap-page>
