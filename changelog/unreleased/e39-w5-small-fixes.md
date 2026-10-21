---
type: security
---

- Starting and ending a "Login as" session gives both panel sessions a new ID.
- A read-only session that picks a language keeps it for itself, without changing the account's language.
- An archive belongs to a user only when its whole name is the user, a dot and the archive's date, so a user `bob` no longer reaches the archives of a user `bob.x`.
- The Docker commands that act on many apps (`v-backup-docker-apps`, `v-delete-user-docker-apps`, `v-rebuild-docker-apps`, `v-suspend-user-docker-apps`, `v-unsuspend-user-docker-apps`, `v-run-docker-app-task`, `v-run-sys-docker-task`) honour demo mode.
- No panel page has inline scripts or `on…` attributes any more; the single sign-on forms and the auto-submitting selects use the panel's script files.
