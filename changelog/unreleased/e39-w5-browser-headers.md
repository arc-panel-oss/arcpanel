---
type: security
---

- Every response of the panel carries `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`, `Referrer-Policy: same-origin` and a Content-Security-Policy without inline scripts, error pages, assets and the REST API included. File and backup downloads keep the sandbox policy of the file manager. The outdated `X-XSS-Protection` header is gone. `v-update-sys-panel-headers` writes the policy again, for example after the tool ports change.
- New `v-change-sys-arcpanel-hsts yes|no` and a setting on the Security tab send `Strict-Transport-Security` for a day (`max-age=86400`, without `includeSubDomains`). They refuse while the panel certificate is not trusted, since browsers would then refuse the panel.
- The webmail and database tool ports send the same three headers, and the panel's session cookies no longer reach Roundcube, SnappyMail, phpMyAdmin or phpPgAdmin.
