{{-- Server settings: Backups and incremental backups (web/edit/server, E7 batch 3 spec §8.2). --}}
@php
    $systems = array_filter(explode(',', $config['BACKUP_SYSTEM'] ?? ''));
    $local = in_array('local', $systems, true);
    $remoteType = $remote['type'];
    $incremental = ($config['BACKUP_INCREMENTAL'] ?? '') === 'yes';
    $mode = ($config['BACKUP_MODE'] ?? 'gzip') === 'zstd' ? 'zstd' : 'gzip';
    $levels = [];
    foreach (range(1, 19) as $level) {
        $levels[(string) $level] = $level > 9 ? sprintf('%d (%s)', $level, __('zstd only')) : (string) $level;
    }
    $keep = fn (string $value) => $value === '-1' ? '' : $value;
    // An S3 backup host (E44 spec §9): its connection without the secret
    $s3 = $incremental ? $restic['s3'] : null;
@endphp
<x-ap-page :title="__('Server settings')" :crumbs="[['label' => __('Server')], ['label' => __('Server settings')], ['label' => __('Backups')]]">
    @include('server.settings.partials.nav')

    <x-ap-form :action="route('server.settings.update.backups')" class="ap-stack"
        x-data="{ remote: {{ $remoteType !== '' ? 'true' : 'false' }}, type: '{{ $remoteType !== '' ? $remoteType : 'sftp' }}', incremental: {{ $incremental ? 'true' : 'false' }}, repoType: '{{ $s3 !== null ? 's3' : 'path' }}', mode: '{{ $mode }}' }">
        <fieldset class="ap-fieldset-plain ap-stack" @disabled(! $canEdit)>
            <x-ap-card :title="__('Backups')" icon="archive">
                <x-ap-switch name="local" :label="__('Local Backup')" :description="__('Keep backup archives in the backup folder of this server.')" :checked="$local" />
                <div class="ap-form-grid">
                    <x-ap-select name="mode" :label="__('Compression')" :options="['gzip' => 'gzip', 'zstd' => 'zstd']" :value="$mode" x-model="mode" />
                    <div x-show="mode === 'zstd'">
                        <x-ap-select name="level" :label="__('Compression Level')" :options="$levels" :value="$config['BACKUP_GZIP'] ?? '9'" />
                    </div>
                    <input type="hidden" name="level" value="9" x-bind:disabled="mode === 'zstd'">
                    <x-ap-input name="backup_dir" :label="__('Directory')" :value="$config['BACKUP'] ?? '/backup'" mono readonly />
                </div>
            </x-ap-card>

            <x-ap-card :title="__('Remote Backup')" icon="upload">
                <x-ap-switch name="remote" :label="__('Remote Backup')" :description="__('Copy every backup to another server or to object storage.')" :checked="$remoteType !== ''" x-model="remote" />
                <div class="ap-stack" x-show="remote">
                    <x-ap-select name="remote_type" :label="__('Protocol')" :options="['sftp' => 'SFTP', 'ftp' => 'FTP', 'b2' => 'Backblaze B2', 'rclone' => 'Rclone']" :value="$remoteType !== '' ? $remoteType : 'sftp'" x-model="type" />
                    <div class="ap-form-grid" x-show="type === 'ftp' || type === 'sftp'">
                        <x-ap-input name="host" :label="__('Host')" :value="$remote['host']" mono x-bind:disabled="type === 'b2' || type === 'rclone'" />
                        <x-ap-input name="port" type="number" :label="__('Port')" :value="$remote['port']" mono x-bind:disabled="type === 'b2' || type === 'rclone'" />
                        <x-ap-input name="username" :label="__('Username')" :value="$remote['username']" mono autocomplete="off" />
                        <x-ap-password name="password" :label="__('Password')" :meter="false" x-bind:disabled="type !== 'ftp' && type !== 'sftp'"
                            :hint="$remoteType !== '' ? __('Leave empty to keep the current password. Required when the host or user changes.') : null" />
                        <x-ap-input name="path" :label="__('Directory')" :value="$remote['path']" mono x-bind:disabled="type === 'b2' || type === 'rclone'" />
                    </div>
                    <div class="ap-form-grid" x-show="type === 'b2'">
                        <x-ap-input name="bucket" :label="__('Bucket')" :value="$remote['bucket']" mono />
                        <x-ap-input name="key_id" :label="__('Application Key ID')" :value="$remote['keyId']" mono autocomplete="off" />
                        <x-ap-password name="password" id="b2-key" :label="__('Application Key')" :meter="false" x-bind:disabled="type !== 'b2'"
                            :hint="$remoteType === 'b2' ? __('Leave empty to keep the current key.') : null" />
                    </div>
                    <div class="ap-form-grid" x-show="type === 'rclone'">
                        <x-ap-input name="host" id="rclone-host" :label="__('Remote')" :value="$remoteType === 'rclone' ? $remote['host'] : ''" mono x-bind:disabled="type !== 'rclone'"
                            :hint="__('Name of the remote in the rclone configuration of root.')" />
                        <x-ap-input name="path" id="rclone-path" :label="__('Directory')" :value="$remoteType === 'rclone' ? $remote['path'] : ''" mono x-bind:disabled="type !== 'rclone'" />
                    </div>
                </div>
            </x-ap-card>

            <x-ap-card :title="__('Incremental Backups')" icon="layers">
                <x-ap-switch name="incremental" :label="__('Incremental Backups')" :description="__('Snapshots with restic, deduplicated and encrypted.')" :checked="$incremental" x-model="incremental" />
                <div class="ap-stack" x-show="incremental">
                    <x-ap-select name="repo_type" :label="__('Repository type')" :options="['path' => __('Path or restic URL'), 's3' => __('S3-compatible bucket')]" :value="$s3 !== null ? 's3' : 'path'" x-model="repoType" />
                    <div x-show="repoType === 'path'">
                        <x-ap-input name="repo" :label="__('Repository')" :value="$incremental && $s3 === null ? $restic['repo'] : ''" mono autocomplete="off" x-bind:disabled="repoType !== 'path'"
                            :hint="__('A local path or a restic repository URL, for example sftp:user@host:/srv/restic or rclone:remote:path.')" />
                    </div>
                    <div class="ap-form-grid" x-show="repoType === 's3'">
                        <x-ap-input name="s3_endpoint" :label="__('Endpoint')" :value="$s3['endpoint'] ?? ''" mono autocomplete="off" placeholder="https://s3.example.com"
                            :hint="__('AWS, Cloudflare R2, Wasabi, or the object storage of another ArcPanel server.')" />
                        <x-ap-input name="s3_bucket" :label="__('Bucket and path')" :value="$s3['bucket'] ?? ''" mono autocomplete="off" placeholder="backups/server1"
                            :hint="__('The bucket must exist; the key needs to list, read and write it.')" />
                        <x-ap-input name="s3_region" :label="__('Region')" :value="$s3['region'] ?? 'us-east-1'" mono autocomplete="off" />
                        <x-ap-input name="s3_key_id" :label="__('Access key ID')" :value="$s3['keyId'] ?? ''" mono autocomplete="off" />
                        <x-ap-password name="s3_secret" :label="__('Secret access key')" :meter="false" x-bind:disabled="repoType !== 's3'"
                            :hint="$s3 !== null ? __('Leave empty to keep the current key. Required when the connection changes.') : null" />
                    </div>
                    <div class="ap-form-grid">
                        <x-ap-input name="snapshots" type="number" :label="__('Snapshots')" :value="$restic['snapshots']" />
                        <x-ap-input name="keep_daily" type="number" :label="__('Daily')" :value="$restic['daily']" :hint="__('-1 keeps every snapshot')" />
                        <x-ap-input name="keep_weekly" type="number" :label="__('Weekly')" :value="$restic['weekly']" />
                        <x-ap-input name="keep_monthly" type="number" :label="__('Monthly')" :value="$restic['monthly']" />
                        <x-ap-input name="keep_yearly" type="number" :label="__('Yearly')" :value="$restic['yearly']" />
                    </div>
                </div>
            </x-ap-card>
        </fieldset>
        @if ($canEdit)
            <div class="ap-form-actions"><x-ap-button type="submit" variant="primary" icon="check">{{ __('Save') }}</x-ap-button></div>
        @endif
    </x-ap-form>
</x-ap-page>
