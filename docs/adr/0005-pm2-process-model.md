# ADR-0005 — Node process model: one pm2 daemon per user under `systemd --user`

**Status:** Accepted (2026-10-05, [E14 spec](../features/specs/2026-10-05-007-e14-node-apps-design.md) D2) · **Date:** 2026-10-04 · **Epic:** E14

## Context

Node apps must survive reboots, be restarted on crash, expose logs, respect the user's package limits, and be managed only through `v-*` commands. Node runtimes are installed per user with nvm (E13), so `pm2` itself is a per-user npm package. Hestia already uses systemd slices per user for cgroup limits (`v-update-user-cgroup`) and bubblewrap jails for SSH.

## Options

| Option                                                                                                                                                                         | Isolation and limits                                                                                                                  | Reboot persistence       | Cost                                   | Problems                                                                                                                                                      |
| ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------- | ------------------------ | -------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **A. One pm2 daemon per user as a `systemd --user` service (pm2's own `pm2 startup systemd -u <user>` unit), `loginctl enable-linger <user>` only while the user has ≥ 1 app** | processes run as the user inside `user-<uid>.slice`: package CPU/memory limits apply for free; a crash loop cannot affect other users | yes (linger + user unit) | one daemon (~40 MB) per user with apps | many users with apps → many daemons; linger has to be managed; pm2 inside `~/.nvm` needs the right PATH in the unit                                           |
| B. One system-wide pm2 as root running apps with `--uid/--gid`                                                                                                                 | shared daemon; limits must be set per process by hand (no slice inheritance); root daemon is a privilege concentration                | yes                      | lowest memory                          | a daemon bug or a `pm2 kill` by a bad command stops every user's apps; logs and `pm2 jlist` mix tenants; Node version per app must be resolved by path tricks |
| C. systemd user units per app (no pm2)                                                                                                                                         | same as A                                                                                                                             | yes                      | zero extra daemon                      | lose pm2 features (cluster mode, reload, `jlist` metrics, log rotation) that the UI relies on; users with SSH expect `pm2`                                    |
| D. supervisord / custom supervisor                                                                                                                                             | —                                                                                                                                     | —                        | —                                      | a second process manager to package and document                                                                                                              |

## Decision

**Option A** ([07](../design/07-node-platform.md)). `v-add-node-app` installs `pm2` into the user's default Node version if absent (that runtime is recorded as `PM2_RUNTIME` and cannot be removed while apps exist), renders the user unit `arcpanel-pm2.service` from ArcPanel's own template (`install/common/node/arcpanel-pm2.service`, the shape of pm2's systemd template: `Type=forking`, `pm2 resurrect`, `pm2 kill`; the output of `pm2 startup` is not parsed), enables linger, and writes `~/web/<domain>/nodeapp/ecosystem.config.cjs`. `v-delete-node-app` disables linger and the unit when the last app is removed; `v-suspend-user` stops the daemon through an additive hook. `v-rebuild-user` regenerates the unit. The daemon count is bounded by the number of users with apps; the `developer` package documents the expected memory overhead.

## Consequences

- Apps inherit the user's cgroup limits and the SSH/SFTP jail's view of the home directory without extra work.
- Node version switches are per app (pm2 `interpreter` set to the nvm path) and per user; no global Node is required.
- Hosts with hundreds of Node users need to budget ~40 MB per active user for pm2; option B remains documented as a possible future "shared daemon" mode if that becomes a problem, with the same `v-*` surface.
