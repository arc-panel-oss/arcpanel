{{--
    The fields of one driver (E48 spec §4.2): plain fields as fields[KEY],
    secrets as secrets[KEY]. Secrets are write-only: a stored one shows
    "Stored" and an empty field that replaces it when filled. Inputs of the
    drivers not picked are disabled, so only one driver's fields are sent.
--}}
@use('App\Services\Backups\BackupDestination')
@php
    $labels = BackupDestination::fieldLabels();
    $spec = BackupDestination::DRIVERS[$driver];
    $stored ??= [];
    $values ??= [];
    $value = fn (string $key, string $default = '') => $values[$key] ?? $default;
    $id = fn (string $key) => 'dest-'.$driver.'-'.strtolower($key);
    $off = $picker ? "driver !== '".$driver."'" : 'false';
    $hints = [
        'PATH' => match ($driver) {
            'local' => __('An absolute folder on this server, for example /backup or a mounted disk; not under /home, /etc or other system folders.'),
            'sftp', 'ftp' => __('The folder on the server, relative to the login folder or absolute.'),
            's3', 'b2' => __('An optional folder inside the bucket.'),
            default => __('An optional folder; empty uses the root.'),
        },
        'HOST' => __('Host name or IP address.'),
        'PORT' => $driver === 'sftp' ? __('Empty uses 22.') : __('Empty uses 21 (990 with implicit TLS).'),
        'ENDPOINT' => __('Empty for Amazon S3; for others the https:// address of the service, or http://127.0.0.1:9310 for the object storage of this server.'),
        'REGION' => __('For example us-east-1; empty uses the provider\'s default.'),
        'BUCKET' => __('The bucket must exist; the key needs to list, read, write and delete in it.'),
        'BWLIMIT' => __('Upload speed limit per second, for example 10M; empty for none.'),
        'ROOT_FOLDER_ID' => __('Optional: the ID of the Drive folder to use as the root, from its URL.'),
        'SHARED_DRIVE_ID' => __('Optional: the ID of a shared drive.'),
        'REMOTE' => __('A name for the remote; the section of the configuration below with this name is used.'),
        'URL' => __('For Nextcloud: https://cloud.example.com/remote.php/dav/files/USER'),
        'PRIVATE_KEY' => __('An OpenSSH or PEM private key. Use a key made for backups, allowed only to write to this folder.'),
        'CONFIG' => __('The remote\'s section of an rclone.conf, with its [name] line.'),
        'SERVICE_ACCOUNT' => __('The JSON key of a Google Cloud service account that has access to the folder.'),
    ];
    $backend = BackupDestination::authorizeBackend($driver);
@endphp
<div class="ap-stack" @if ($picker) x-show="driver === @js($driver)" x-cloak @endif data-driver-fields="{{ $driver }}">
    @if ($backend !== null)
        <x-ap-alert tone="info" :title="__('Get a token on your computer')">
            <p>{{ __('Install rclone on a computer with a browser and run the command below. Sign in, allow access, then paste the JSON it prints between the arrows into the Token field.') }}</p>
            <p><code class="mono" data-authorize>rclone authorize "{{ $backend }}"</code></p>
        </x-ap-alert>
    @endif
    <div class="ap-form-grid">
        @foreach ($spec['fields'] as $field)
            @php($options = BackupDestination::fieldOptions($field))
            @if ($options !== null)
                <x-ap-select :name="'fields['.$field.']'" :id="$id($field)" :label="$labels[$field]" :options="$options"
                    :value="$value($field, array_key_first($options))" :hint="$hints[$field] ?? null" x-bind:disabled="{{ $off }}" />
            @elseif (in_array($field, ['TLS_VERIFY', 'PATH_STYLE'], true))
                <x-ap-switch :name="'fields['.$field.']'" :id="$id($field)" value="yes" :label="$labels[$field]"
                    :description="$field === 'TLS_VERIFY' ? __('Turn off only for a server with a self-signed certificate.') : __('Needed by MinIO, Silo and most self-hosted stores.')"
                    :checked="$value($field, $field === 'TLS_VERIFY' ? 'yes' : 'no') === 'yes'" x-bind:disabled="{{ $off }}" />
            @else
                <x-ap-input :name="'fields['.$field.']'" :id="$id($field)" :label="$labels[$field]" :value="$value($field)" mono autocomplete="off"
                    :type="$field === 'PORT' ? 'number' : 'text'" :hint="$hints[$field] ?? null" x-bind:disabled="{{ $off }}"
                    :required="in_array($field, ['HOST', 'USERNAME', 'BUCKET', 'ACCESS_KEY_ID', 'KEY_ID', 'URL', 'REMOTE'], true) || ($driver === 'local' && $field === 'PATH')" />
            @endif
        @endforeach
    </div>
    @php($long = ['PRIVATE_KEY', 'TOKEN', 'SERVICE_ACCOUNT', 'CONFIG'])
    @php($short = array_values(array_diff($spec['secrets'], $long)))
    @if ($short !== [])
        <div class="ap-form-grid">
            @foreach ($short as $secret)
                @php($hint = trim(($hints[$secret] ?? '').' '.(in_array($secret, $stored, true) ? __('Stored. Leave empty to keep it.') : '')))
                <x-ap-password :name="'secrets['.$secret.']'" :id="$id($secret)" :label="$labels[$secret]" :meter="false" autocomplete="off"
                    :hint="$hint !== '' ? $hint : null" x-bind:disabled="{{ $off }}" />
            @endforeach
        </div>
    @endif
    @foreach (array_intersect($spec['secrets'], $long) as $secret)
        @php($hint = trim(($hints[$secret] ?? '').' '.(in_array($secret, $stored, true) ? __('Stored. Leave empty to keep it.') : '')))
        <x-ap-textarea :name="'secrets['.$secret.']'" :id="$id($secret)" :label="$labels[$secret]" :value="''" mono rows="5"
            :hint="$hint !== '' ? $hint : null" autocomplete="off" spellcheck="false" x-bind:disabled="{{ $off }}" />
        @if ($secret === 'SERVICE_ACCOUNT')
            <x-ap-input type="file" name="service_account_file" :id="$id('SERVICE_ACCOUNT_FILE')" :label="__('Or upload the key file')" accept=".json,application/json"
                x-bind:disabled="{{ $off }}" />
        @endif
    @endforeach
</div>
