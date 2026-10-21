---
type: security
---

- The input file of `v-add-sys-php-profile`, `v-change-sys-php-profile`, `v-check-sys-php-ini`, `v-change-sys-php-ini`, `v-change-sys-php-ini-values` and `v-change-app-process-env` must be a file the panel wrote: a plain name in `/tmp`, a regular file, not a link, owned by `arcpanelweb` or root (exit code 10 otherwise). A bad line is named by its number, never by its content.
- The php.ini check refuses `opcache.preload`, `opcache.preload_user`, `ffi.preload` and any `extension` or `zend_extension` that is not the bare name of an extension in the version's extension folder. PHP runs with a candidate file only when the check found no error, and then without opcache, preload or FFI.
- Restores apply a backup's PHP-FPM profile and backend template only when the user's package allows them; otherwise the domain gets the package's default profile or the `default` template, with a line in the restore log.
- Under a reseller package whose `PHP_VERSIONS` is `*`, a customer package can name only versions that `*` gives the reseller, so versions at their end of life stay out.
- Every command that sets a backend template (`v-change-web-domain-backend-tpl`, `v-add-web-domain-backend`, `v-change-user-template`, `v-add-user-package`, the imports and both restores) checks its PHP version against the package, as the panel does.
- `v-change-sys-php` accepts only an exact installed version, quotes its paths, takes the PHP lock and removes the other versions' `www.conf` only after the new one is in place.
- Switching a web domain's PHP version reloads PHP-FPM instead of restarting it, and the page and REST API v2 accept at most 6 PHP changes a minute per account (`429` above).
