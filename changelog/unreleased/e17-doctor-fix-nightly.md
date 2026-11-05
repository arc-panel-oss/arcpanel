---
type: added
---

- Doctor fixes and the nightly run (E17): `v-fix-sys-doctor` runs a failing check again and, when it still fails, starts a `doctor.fix` job with the existing command its check allows (for example `v-add-letsencrypt-domain`, `v-add-web-domain-ssl-force`, `v-restart-service fail2ban`), then checks again. Every night at 04:40 cron runs every check (`v-run-sys-doctor all '' cron`, added by the installers and the 2.7.0 upgrade); a check that changes to fail sends the `doctor.failed` alert, and on Mondays the run sends the weekly `doctor.digest` of the checks that are not ok.
