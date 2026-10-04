<?php

namespace App\Auth;

use Divinity76\CloudflareIpValidator\CloudflareIpValidator;
use Illuminate\Http\Request;

/**
 * The client IP exactly as the legacy app computes it (web/inc/helpers.php,
 * get_real_user_ip), because both apps compare it with the session's
 * user_combined_ip.
 */
final class ClientIp
{
    public static function of(Request $request): string
    {
        $ip = '';
        $remote = (string) $request->server('REMOTE_ADDR', '');
        if ($remote !== '' && filter_var($remote, FILTER_VALIDATE_IP)) {
            $ip = $remote;
        }

        $cf = (string) $request->server('HTTP_CF_CONNECTING_IP', '');
        if ($cf !== '' && filter_var($cf, FILTER_VALIDATE_IP) && $ip !== ''
            && CloudflareIpValidator::isCloudflareIp($ip)) {
            $ip = $cf;
        }

        // IPv4-mapped IPv6 address (::ffff:1.2.3.4)
        if (str_starts_with($ip, ':') && strpos($ip, '.') > 0) {
            $ip = substr($ip, strrpos($ip, ':') + 1);
        }

        return $ip;
    }
}
