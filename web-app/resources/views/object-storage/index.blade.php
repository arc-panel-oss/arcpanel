{{-- Object storage of an account (E44 spec §11): buckets, S3 keys, how to connect. --}}
@php
    $size = fn (int $bytes) => \App\Services\Dashboard\DashboardService::size($bytes / 1048576);
    $mb = fn (?int $value) => $value === null ? __('Unlimited') : \App\Services\Dashboard\DashboardService::size((float) $value);
    $bucketColumns = [
        ['key' => 'name', 'label' => __('Bucket'), 'sortable' => true],
        ['key' => 'usage', 'label' => __('Usage'), 'sortable' => true, 'type' => 'number'],
        ['key' => 'objects', 'label' => __('Objects'), 'sortable' => true, 'type' => 'number'],
        ['key' => 'versioning', 'label' => __('Versioning'), 'sortable' => true],
        ['key' => 'date', 'label' => __('Created'), 'sortable' => true, 'optional' => true],
    ];
    $keyColumns = [
        ['key' => 'name', 'label' => __('Name'), 'sortable' => true],
        ['key' => 'id', 'label' => __('Access key ID')],
        ['key' => 'scope', 'label' => __('Buckets'), 'sortable' => true],
        ['key' => 'access', 'label' => __('Access'), 'sortable' => true],
        ['key' => 'expires', 'label' => __('Expires'), 'sortable' => true],
    ];
    $endpoint = $account->endpoint !== '' ? $account->endpoint : __('(no endpoint yet)');
    $left = $account->quotaLeftMb();
    $defaultQuota = $left === null ? 1024 : max(1, min(1024, $left));
@endphp
<x-ap-page :title="__('Object storage')" :crumbs="[['label' => __('Object storage')], ['label' => $user]]"
    :description="__('S3-compatible buckets for media, uploads, static files and app backups, reached with S3 keys.')">
    <x-slot:actions>
        @if ($canManage && $account->allowed)
            <x-ap-button variant="primary" icon="plus" x-on:click="$dispatch('ap-modal', { id: 'add-bucket' })" :disabled="! $account->canAddBucket()">{{ __('Add bucket') }}</x-ap-button>
        @endif
    </x-slot:actions>

    @unless ($account->allowed)
        <x-ap-alert tone="warn">{{ __('Object storage is not included in the package of this account. Existing buckets can still be deleted.') }}</x-ap-alert>
    @endunless

    <x-ap-card :title="__('Usage')" icon="disk">
        <div class="ap-usage-list">
            <x-ap-usage-bar :label="__('Buckets')" :value="$account->buckets" :limit="$account->bucketsLimit" />
            <x-ap-usage-bar :label="__('Bucket quotas')" :value="$account->quotaUsedMb" :limit="$account->quotaLimitMb"
                :value-label="$mb($account->quotaUsedMb)" :limit-label="$mb($account->quotaLimitMb)"
                :note="__('The quotas of all buckets together, within the package.')" />
            <x-ap-usage-bar :label="__('Stored')" :value="$account->usedBytes" :limit="$account->quotaLimitMb === null ? null : $account->quotaLimitMb * 1048576"
                :value-label="$size($account->usedBytes)" :limit-label="$mb($account->quotaLimitMb)"
                :note="trans_choice(':count object|:count objects', $account->objects, ['count' => $account->objects])" />
        </div>
    </x-ap-card>

    <x-ap-card :title="__('Buckets')" icon="archive" :flush="true">
        @if ($buckets === [])
            <x-ap-empty icon="archive" :title="__('No buckets yet')">{{ __('Add a bucket, then an S3 key to reach it.') }}</x-ap-empty>
        @else
            <x-ap-table id="storage-buckets" :columns="$bucketColumns" :search-label="__('Search buckets')" :caption="__('Buckets')">
                @foreach ($buckets as $bucket)
                    <tr data-row data-search="{{ $bucket->name }}" @class(['is-disabled' => $bucket->suspended])>
                        <td data-col="name" data-label="{{ __('Bucket') }}" data-sort-value="{{ $bucket->name }}"><span class="mono">{{ $bucket->name }}</span></td>
                        <td data-col="usage" data-label="{{ __('Usage') }}" data-sort-value="{{ $bucket->usedBytes }}">
                            {{ $size($bucket->usedBytes) }} / {{ $mb($bucket->quotaMb) }}
                            <span class="muted">({{ $bucket->percent() }}%)</span>
                        </td>
                        <td data-col="objects" data-label="{{ __('Objects') }}" data-sort-value="{{ $bucket->objects }}">{{ $bucket->objects }}</td>
                        <td data-col="versioning" data-label="{{ __('Versioning') }}" data-sort-value="{{ $bucket->versioning ? 1 : 0 }}">
                            <x-ap-badge :tone="$bucket->versioning ? 'ok' : null">{{ $bucket->versioning ? __('On') : __('Off') }}</x-ap-badge>
                        </td>
                        <td data-col="date" data-label="{{ __('Created') }}">{{ $bucket->date }}</td>
                        <td class="col-actions">
                            @if ($canManage)
                                <x-ap-button variant="ghost" size="sm" :icon-only="true" icon="edit" :label="__('Edit :name', ['name' => $bucket->name])"
                                    x-on:click="$dispatch('ap-modal', { id: 'edit-bucket-{{ $bucket->name }}' })" />
                                <x-ap-button variant="ghost" size="sm" :icon-only="true" icon="trash" :label="__('Delete :name', ['name' => $bucket->name])"
                                    :form-action="route('storage.buckets.destroy', ['user' => $user, 'bucket' => $bucket->name])" form-method="DELETE"
                                    :confirm="sprintf(__('Delete bucket %s with every object in it, and the S3 keys limited to it? This cannot be undone.'), $bucket->name)" :confirm-danger="true" />
                            @endif
                        </td>
                    </tr>
                @endforeach
                <x-slot:footer>
                    <span class="muted">{{ __('Usage is counted every few minutes. Silo refuses an upload larger than the quota at once; uploads that add up beyond it are refused about a minute later.') }}</span>
                </x-slot:footer>
            </x-ap-table>
        @endif
    </x-ap-card>

    <x-ap-card :title="__('S3 keys')" icon="key" :flush="true">
        <x-slot:actions>
            @if ($canManage && $account->allowed)
                <x-ap-button size="sm" icon="plus" x-on:click="$dispatch('ap-modal', { id: 'add-key' })" :disabled="$account->keys >= $account->keysLimit">{{ __('Add S3 key') }}</x-ap-button>
            @endif
        </x-slot:actions>
        @if ($keys === [])
            <x-ap-empty icon="key" :title="__('No S3 keys yet')">{{ __('An S3 key lets apps and tools such as rclone or the AWS CLI reach the buckets. Its secret is shown once.') }}</x-ap-empty>
        @else
            <x-ap-table id="storage-keys" :columns="$keyColumns" :search-label="__('Search S3 keys')" :caption="__('S3 keys')">
                @foreach ($keys as $key)
                    <tr data-row data-search="{{ $key->name }} {{ $key->key }} {{ $key->bucket }}" @class(['is-disabled' => $key->expired])>
                        <td data-col="name" data-label="{{ __('Name') }}">{{ $key->name }}</td>
                        <td data-col="id" data-label="{{ __('Access key ID') }}" class="mono">{{ $key->key }}</td>
                        <td data-col="scope" data-label="{{ __('Buckets') }}">{!! $key->bucket !== '' ? '<span class="mono">'.e($key->bucket).'</span>' : e(__('Every bucket of the account')) !!}</td>
                        <td data-col="access" data-label="{{ __('Access') }}">
                            <x-ap-badge :tone="$key->access === 'ro' ? null : 'ok'">{{ $key->access === 'ro' ? __('Read only') : __('Read and write') }}</x-ap-badge>
                        </td>
                        <td data-col="expires" data-label="{{ __('Expires') }}">
                            @if ($key->expires === '')
                                <span class="muted">{{ __('Never') }}</span>
                            @else
                                {{ $key->expires }} @if ($key->expired)<x-ap-badge tone="danger">{{ __('Expired') }}</x-ap-badge>@endif
                            @endif
                        </td>
                        <td class="col-actions">
                            @if ($canManage)
                                <x-ap-button variant="ghost" size="sm" :icon-only="true" icon="trash" :label="__('Delete :name', ['name' => $key->name])"
                                    :form-action="route('storage.keys.destroy', ['user' => $user, 'key' => $key->key])" form-method="DELETE"
                                    :confirm="sprintf(__('Delete S3 key %s? Apps that use it stop reaching the buckets at once.'), $key->name)" :confirm-danger="true" />
                            @endif
                        </td>
                    </tr>
                @endforeach
            </x-ap-table>
        @endif
    </x-ap-card>

    <x-ap-card :title="__('Connect')" icon="link">
        <dl class="ap-dl">
            <dt>{{ __('Endpoint') }}</dt><dd class="mono">{{ $endpoint }}</dd>
            <dt>{{ __('Region') }}</dt><dd class="mono">{{ $account->region }}</dd>
            <dt>{{ __('Addressing') }}</dt><dd>{{ __('Path style (https://endpoint/bucket/object)') }}</dd>
        </dl>
        <h3>rclone</h3>
        <x-ap-code :label="__('rclone configuration')">[arcpanel]
type = s3
provider = Minio
endpoint = {{ $endpoint }}
region = {{ $account->region }}
access_key_id = ACCESS_KEY_ID
secret_access_key = SECRET
force_path_style = true
no_check_bucket = true</x-ap-code>
        <h3>AWS CLI</h3>
        <x-ap-code :label="__('AWS CLI commands')">aws configure set default.s3.addressing_style path
AWS_ACCESS_KEY_ID=ACCESS_KEY_ID AWS_SECRET_ACCESS_KEY=SECRET \
  aws --endpoint-url {{ $endpoint }} --region {{ $account->region }} s3 ls s3://BUCKET</x-ap-code>
        <p class="muted">{{ __('Buckets are created and deleted on this page, not through S3. Tools that try to create the bucket first need that check turned off, as no_check_bucket does for rclone.') }}</p>
    </x-ap-card>

    @if ($canManage && $account->allowed)
        <x-ap-modal id="add-bucket" :title="__('Add bucket')" icon="archive" :open="$errors->has('name') || $errors->has('quota')"
            :description="__('Bucket names are unique on the server.')">
            <x-ap-form :action="route('storage.buckets.store', ['user' => $user])" :focus-first="true">
                <x-ap-input name="name" :label="__('Bucket name')" :hint="__('3-63 lower case letters, digits and hyphens.')" required mono maxlength="63" autocomplete="off" />
                <x-ap-input name="quota" type="number" :label="__('Quota (MB)')" :value="$defaultQuota" min="1" required
                    :hint="$left === null ? __('A hard quota for this bucket.') : sprintf(__('A hard quota for this bucket; %s left in the package.'), $mb($left))" />
                <x-ap-checkbox name="versioning" :label="__('Keep versions of overwritten and deleted objects')" />
                <div class="ap-form-actions">
                    <x-ap-button type="submit" variant="primary" icon="check">{{ __('Add bucket') }}</x-ap-button>
                </div>
            </x-ap-form>
        </x-ap-modal>

        <x-ap-modal id="add-key" :title="__('Add S3 key')" icon="key" :open="$errors->has('expires') || $errors->has('save_as') || $errors->has('access')"
            :description="__('The secret is shown once, on the next page.')">
            <x-ap-form :action="route('storage.keys.store', ['user' => $user])" :focus-first="true">
                <x-ap-input name="name" id="key-name" :label="__('Key name')" required maxlength="32" autocomplete="off" />
                <x-ap-select name="bucket" :label="__('Buckets')" :options="['' => __('Every bucket of the account')] + collect($buckets)->mapWithKeys(fn ($b) => [$b->name => $b->name])->all()" value="" />
                <x-ap-select name="access" :label="__('Access')" :options="['rw' => __('Read and write'), 'ro' => __('Read only')]" value="rw" required />
                <x-ap-input name="expires" type="date" :label="__('Expires on')" :hint="__('(Optional) The key stops working at the start of that day (UTC).')" />
                <x-ap-input name="save_as" :label="__('Save the secret as')" mono maxlength="128" autocomplete="off"
                    :hint="__('(Optional) A secret store name such as /s3/media, which apps can reference.')" />
                <div class="ap-form-actions">
                    <x-ap-button type="submit" variant="primary" icon="check">{{ __('Add S3 key') }}</x-ap-button>
                </div>
            </x-ap-form>
        </x-ap-modal>
    @endif

    @if ($canManage)
        @foreach ($buckets as $bucket)
            <x-ap-modal id="edit-bucket-{{ $bucket->name }}" :title="sprintf(__('Edit bucket %s'), $bucket->name)" icon="edit">
                <x-ap-form :action="route('storage.buckets.update', ['user' => $user, 'bucket' => $bucket->name])">
                    <x-ap-input name="quota" id="quota-{{ $bucket->name }}" type="number" :label="__('Quota (MB)')" :value="$bucket->quotaMb" min="1" required
                        :hint="__('A quota below the usage stops uploads until the bucket is below it again.')" />
                    <x-ap-checkbox name="versioning" id="versioning-{{ $bucket->name }}" :label="__('Keep versions of overwritten and deleted objects')" :checked="$bucket->versioning" />
                    <div class="ap-form-actions">
                        <x-ap-button type="submit" variant="primary" icon="check">{{ __('Save') }}</x-ap-button>
                    </div>
                </x-ap-form>
            </x-ap-modal>
        @endforeach
    @endif
</x-ap-page>
