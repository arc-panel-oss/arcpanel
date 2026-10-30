---
type: added
---

- Git Deploy for every web domain: a new Deploy tab deploys the site from Git with atomic releases (`releases/`, `shared/`, `current`) and one-click rollback. Pull mode fetches the repository with a deploy key or an HTTPS token, on a webhook from GitHub, GitLab or Gitea or with "Deploy now", and builds it with a preset (none, Laravel, static site, or the repository's `.arcpanel/deploy.sh`). Push mode accepts a `tar.gz` built by GitHub Actions or GitLab CI, authenticated by the CI's short-lived OIDC token against the domain's trusted publishers; the server stores no secret for it.
- Each deploy runs as a task in the user's slice, one at a time per domain; a health check path rolls back automatically when the new release does not answer, and the site keeps serving `public_html` until the first deploy succeeds. Turning deploy off brings the previous document root back.
- The webhook and upload endpoint listens on the deploy port (8088, `DEPLOY_PORT`), opened only while a domain uses deploy. Server settings → Web has a Git Deploy card for the port, the largest archive, the build timeout and the allowed OIDC issuers; packages have a Git Deploy switch (`GIT_DEPLOY`, on by default).
- New commands `v-add-web-domain-deploy`, `v-change-web-domain-deploy`, `v-change-web-domain-deploy-credential`, `v-add-web-domain-deploy-publisher`, `v-delete-web-domain-deploy-publisher`, `v-schedule-web-domain-deploy`, `v-rollback-web-domain-deploy`, `v-list-web-domain-deploy`, `v-list-web-domain-deploys`, `v-delete-web-domain-deploy`, `v-change-sys-deploy` and `v-list-sys-deploy`.
- REST API v2: `GET`, `PUT` and `DELETE /users/{user}/web-domains/{domain}/deploy`, `POST …/deploy/runs`, `GET …/deploy/releases`, `POST …/deploy/releases/{id}/rollback`, `POST` and `DELETE …/deploy/publishers`, with the new permission set `v2-deploy`.
- The shipped PHP-FPM pool templates let PHP read the `releases` and `shared` folders of a domain; a custom pool template needs the same two paths before its domains can use deploy.
- Backups of a domain that uses Git Deploy keep only the live release and `shared/`; a restore serves that release at once.
