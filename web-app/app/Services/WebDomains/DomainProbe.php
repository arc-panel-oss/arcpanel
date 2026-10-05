<?php

namespace App\Services\WebDomains;

/**
 * Facts a plan needs from the server, asked only when the plan needs them
 * (web/edit/web probes with v-list-dns-domain and v-list-mail-domain).
 */
interface DomainProbe
{
    public function dnsDomainExists(string $user, string $domain): bool;

    public function mailDomainExists(string $user, string $domain): bool;
}
