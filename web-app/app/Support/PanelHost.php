<?php

namespace App\Support;

use App\Arc\ArcCommand;
use App\Arc\Options;
use App\Arc\SysConfig;
use Illuminate\Contracts\Cache\Repository;

/**
 * The names the panel answers to, from the configuration only (E39 W2 spec
 * §5.1, D6). Links in e-mails use link(); the trusted hosts middleware
 * refuses every Host header that trusted() does not match, so a forged
 * header cannot reach a link or a redirect.
 */
final class PanelHost
{
    /** Seconds the address list is kept; IP changes are rare. */
    private const TTL = 600;

    public function __construct(
        private readonly SysConfig $config,
        private readonly ArcCommand $arc,
        private readonly Repository $cache,
    ) {}

    /** The host part of a panel link: the server hostname, with the panel port unless it is 443. */
    public function link(): string
    {
        $port = $this->config->get('BACKEND_PORT', '8083');

        return $this->hostname().($port === '' || $port === '443' ? '' : ':'.$port);
    }

    /**
     * Anchored patterns of the trusted hosts: the hostname, every server IP
     * and its NAT IP, localhost, and the names of PANEL_HOSTNAMES.
     *
     * @return list<string>
     */
    public function trusted(): array
    {
        $hosts = [$this->hostname(), 'localhost', '127.0.0.1', '[::1]'];
        foreach (explode(',', $this->config->get('PANEL_HOSTNAMES')) as $name) {
            $hosts[] = trim($name);
        }
        foreach ($this->addresses() as $ip) {
            $hosts[] = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? '['.$ip.']' : $ip;
        }

        $hosts = array_unique(array_filter(array_map('strtolower', $hosts), fn (string $host) => $host !== ''));

        return array_values(array_map(fn (string $host) => '^'.preg_quote($host).'$', $hosts));
    }

    private function hostname(): string
    {
        return (string) gethostname();
    }

    /** @return list<string> the server IPs and their NAT IPs */
    private function addresses(): array
    {
        $cached = $this->cache->get('panel-host:addresses');
        if (is_array($cached)) {
            return $cached;
        }

        $ips = $this->arc->json('v-list-sys-ips', [], Options::allowFailure());
        $addresses = [];
        foreach (is_array($ips) ? $ips : [] as $ip => $data) {
            foreach ([(string) $ip, (string) (is_array($data) ? ($data['NAT'] ?? '') : '')] as $address) {
                if (filter_var($address, FILTER_VALIDATE_IP) !== false) {
                    $addresses[] = $address;
                }
            }
        }
        // A failed read is not kept, so the addresses come back on the next request
        if ($addresses !== []) {
            $this->cache->put('panel-host:addresses', $addresses, self::TTL);
        }

        return $addresses;
    }
}
