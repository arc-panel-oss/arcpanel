{{-- Usage bars of a user against its package (E11 spec §7.2). --}}
@php
    $labels = [
        'WEB_DOMAINS' => __('Web Domains'),
        'DNS_DOMAINS' => __('DNS Zones'),
        'MAIL_DOMAINS' => __('Mail Domains'),
        'MAIL_ACCOUNTS' => __('Mail Accounts'),
        'DATABASES' => __('Databases'),
        'CRON_JOBS' => __('Cron Jobs'),
        'DISK_QUOTA' => __('Disk'),
        'BANDWIDTH' => __('Bandwidth'),
    ];
    $sized = ['DISK_QUOTA', 'BANDWIDTH'];
    $size = fn (int|string $mb) => \App\Services\Dashboard\DashboardService::size((int) $mb);
@endphp
<div class="ap-usage-list">
    @foreach ($record->usage() as $key => $row)
        @php($unlimited = $row['limit'] === 'unlimited')
        <x-ap-usage-bar :label="$labels[$key]" :value="$row['used']" :limit="$unlimited ? null : (float) $row['limit']" :unlimited="$unlimited"
            :value-label="in_array($key, $sized, true) ? $size($row['used']) : $row['used']"
            :limit-label="$unlimited ? null : (in_array($key, $sized, true) ? $size($row['limit']) : $row['limit'])" />
    @endforeach
</div>
