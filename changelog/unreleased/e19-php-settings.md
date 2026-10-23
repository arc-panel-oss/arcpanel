---
type: added
---

- Per-domain PHP settings: the PHP tab of a web domain has a "PHP settings" card where its owner sets `memory_limit`, `upload_max_filesize`, `post_max_size`, `max_execution_time`, `max_input_vars`, `display_errors` and `date.timezone` for that domain. They win over the PHP-FPM profile and php.ini and stay through rebuilds, PHP version and profile changes, renames, backups and restores.
- Packages set the maximums of these settings (`PHP_MAX_MEMORY`, `PHP_MAX_UPLOAD`, `PHP_MAX_EXECUTION_TIME`, `PHP_MAX_INPUT_VARS`; 512M, 256M, 300 seconds and 10000 when a package does not set them); a reseller's packages stay within the reseller's. A lower maximum caps the values already set, and a higher one gives them back.
- New commands `v-change-web-domain-php-setting`, `v-delete-web-domain-php-setting` and `v-list-web-domain-php-settings`.
- REST API v2: `GET`, `PUT` and `DELETE /users/{user}/web-domains/{domain}/php/settings` (`getWebDomainPhpSettings`, `updateWebDomainPhpSettings`, `resetWebDomainPhpSettings`) and the maximums in the `php` object of packages.
