# ADR-0003 — Docker: single root engine with per-user Compose projects

**Status:** Proposed · **Date:** 2026-10-04 · **Epic:** E4

## Context

The App Store installs Docker Compose applications for hosting users on a shared server. Users must not gain root, must not see or affect each other's containers, must stay inside their package's CPU/memory limits, and must be able to back up and restore app data. The panel runs `v-*` as root through sudo and already applies per-user cgroup limits via `user-<uid>.slice`.

## Options

| Option                                                                                                                  | Isolation                                                                                                                               | Operational cost                                                                                                                                                                                      | Known problems                                                                                                                                                            |
| ----------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **A. One root Docker Engine; Compose projects created and operated only by `v-*` (root); users never touch the socket** | process-level: containers run as the user's uid/gid where the image allows, root-owned compose files, bind mounts under the user's home | one daemon, standard packages, well-understood                                                                                                                                                        | a container escape is a host compromise (true for any root engine); images that insist on running as root need a `user:` override or are excluded from the catalog        |
| B. Rootless Docker per user (`dockerd-rootless` in each user's `systemd --user`)                                        | strongest: engine itself runs as the user                                                                                               | one daemon per user (memory ~50–100 MB each), `slirp4netns`/`pasta` networking (slower, port mapping quirks), subuid/subgid management, overlayfs via fuse-overlayfs on some kernels, linger required | known to be fragile under upgrades; volume ownership mapped through subuids complicates backups and the File Manager; `loginctl enable-linger` for every user with an app |
| C. Group mode: users in the `docker` group run Compose themselves                                                       | none (docker group = root)                                                                                                              | lowest                                                                                                                                                                                                | unacceptable on a shared host                                                                                                                                             |
| D. Podman rootless                                                                                                      | like B with a lighter daemon-less model                                                                                                 | Podman packaging differs across Debian/Ubuntu releases; Compose compatibility varies                                                                                                                  | less predictable across the supported OS matrix                                                                                                                           |

## Decision

**Option A** for 2.0, with these rules enforced in `func/docker.sh` ([08](../design/08-app-store.md)):

- Project name `ap-<user>-<app>`; compose and env files root-owned under `/home/<user>/apps/<app>/`; data directories bind-mounted from `/home/<user>/apps/<app>/data` and owned by the user so File Manager and backups work unchanged.
- Containers run with `user: <uid>:<gid>` when the catalog manifest declares the image supports it; otherwise the manifest must document why root inside the container is needed and the app is `admin_only`.
- Never `privileged`, never `network_mode: host`, no host socket mounts, images pinned by tag + digest in the catalog, `read_only` rootfs where the manifest allows, `cap_drop: ALL` plus the manifest's `cap_add`.
- Resource limits from the user's package (`cpus`, `mem_limit`) and the containers' cgroup parent set to the user's slice so limits compose with `v-update-user-cgroup`.
- Ports always come from the port registry ([04](../design/04-port-registry.md)) and bind `127.0.0.1` unless the port is public.
- Users have no Docker CLI access; every lifecycle action is a `v-*` command callable only through the panel/API with its policies.

Rootless mode (B) stays a post-2.0 option behind `v-add-sys-docker --rootless` once it is proven on the supported OS matrix; the catalog format and commands are designed so the switch does not change the UI.

## Consequences

- Simple operations and backups; one engine to monitor and update (E33 keeps the previous image tag for rollback).
- The security argument rests on container hardening and catalog curation rather than on kernel-level user separation; this is stated in the docs and the admin can disable the store per package (`DOCKER='no'`).
- Admins who require rootless isolation wait for the post-2.0 option or do not enable the store.
