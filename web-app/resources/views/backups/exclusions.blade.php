{{-- Backup exclusions (web/edit/backup/exclusions, E7 batch 1 spec §10.2 and D5). --}}
<x-ap-page :title="__('Backup Exclusions')" :crumbs="[['label' => __('Backups'), 'url' => route('backups.archives.index', ['user' => $user])], ['label' => __('Backup Exclusions')]]"
    :eyebrow="__('Owner').': '.$user" :description="__('What the nightly backup leaves out. One entry per line; * stands for everything.')">

    @include('backups.partials.nav', ['current' => 'exclusions'])

    @if ($incremental)
        <x-ap-alert>{{ __('Snapshots ignore exclusions.') }}</x-ap-alert>
    @endif

    <x-ap-card :title="__('Backup Exclusions')" icon="archive">
        <x-ap-form :action="route('backups.exclusions.update', ['user' => $user])">
            @php($locked = ! $can['exclusions'])
            @if ($toggles !== [])
                <fieldset class="ap-fieldset-plain">
                    <legend class="ap-label">{{ __('Leave out of the backup') }}</legend>
                    @foreach ($toggles as $path => $toggle)
                        {{-- x-ap-switch markup; the name is an array, so ids and the checked state are set here --}}
                        <label class="ap-setting" for="toggle-{{ $loop->index }}">
                            <span class="ap-setting-text">
                                <b>{{ $toggle['label'] }}</b>
                                <span id="toggle-{{ $loop->index }}-hint">{{ $toggle['hint'] }}</span>
                            </span>
                            <span class="ap-switch">
                                <input type="checkbox" role="switch" id="toggle-{{ $loop->index }}" name="toggles[]" value="{{ $path }}"
                                    aria-describedby="toggle-{{ $loop->index }}-hint" @checked(is_array(old('toggles')) ? in_array($path, old('toggles'), true) : $toggle['on']) @disabled($locked)>
                            </span>
                        </label>
                    @endforeach
                </fieldset>
            @endif
            <div class="ap-form-grid">
                <x-ap-textarea name="web" :label="__('Web Domains')" rows="4" mono :value="$exclusions->lines('WEB')" :disabled="$locked"
                    :hint="__('Type domain name, one per line. To exclude all domains use *. To exclude specific dirs use following format: domain.tld:public_html/cache:public_html/tmp')" />
                <x-ap-textarea name="mail" :label="__('Mail Domains')" rows="4" mono :value="$exclusions->lines('MAIL')" :disabled="$locked"
                    :hint="__('Type domain name, one per line. To exclude all domains use *. To exclude specific accounts use following format: domain.tld:info:support:postmaster')" />
                <x-ap-textarea name="db" :label="__('Databases')" rows="4" mono :value="$exclusions->lines('DB')" :disabled="$locked"
                    :hint="__('Type full database name, one per line. To exclude all databases use *')" />
                <x-ap-textarea name="userdir" :label="__('User Directories')" rows="4" mono :value="$userLines" :disabled="$locked"
                    :hint="__('Type directory name, one per line. To exlude all dirs use *')" />
            </div>
            @if ($exclusions->get('DNS') !== [] || $exclusions->get('CRON') !== [])
                <p class="muted">{{ __('DNS and cron exclusions set from the command line are kept.') }}</p>
            @endif
            @if (! $locked)
                <div class="ap-form-actions">
                    <x-ap-button type="submit" variant="primary" icon="check">{{ __('Save') }}</x-ap-button>
                </div>
            @endif
        </x-ap-form>
    </x-ap-card>
</x-ap-page>
