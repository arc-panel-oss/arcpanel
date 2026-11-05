---
type: added
---

- Site Doctor (E17): 12 read-only checks per domain, stored in `data/users/USER/doctor/DOMAIN.json`. Web domains: the A and AAAA records of the domain and its aliases (aware of NAT addresses and CDN proxies), the served certificate (trusted, covering every alias, not expiring within 14 days), the redirect from HTTP to HTTPS and the support state of the domain's PHP version. Mail domains: MX, SPF, DKIM (on and published), DMARC and the mail certificate. `v-run-sys-doctor USER [DOMAIN]` checks the domains of a user, `v-run-sys-doctor all` everything.
