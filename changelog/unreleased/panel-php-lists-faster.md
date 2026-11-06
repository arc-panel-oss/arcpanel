---
type: changed
---

- The pages that list PHP versions, PHP-FPM profiles, php.ini settings or system packages open faster: the web domain edit and create pages, the package form and the PHP pages. `v-list-sys-php-versions` reads the web domains once instead of once per version (about 1.2 s down to 0.3 s on a test server), `v-list-sys-php-ini` starts PHP once, and `v-list-sys-packages` asks dpkg once. The output of the commands is unchanged.
