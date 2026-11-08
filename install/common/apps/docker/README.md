# Adding an app to the Docker catalog

The shipped catalog is `install/common/apps/docker/`. Each app has one shared
icon and a directory for every catalog version:

```text
install/common/apps/docker/<slug>/
  icon.svg
  <version>/
    manifest.json
    compose.yaml
```

Keep versions in separate directories. ArcPanel orders them with `sort -V` and
uses the newest version as the catalog default.

## 1. Choose the slug and version

- A slug is 2–32 characters: it starts with a lowercase letter and contains
  only lowercase letters, digits, and hyphens. The folder name and
  `manifest.json`'s `slug` must match.
- A version is 1–40 characters: it starts with a letter or digit and then may
  contain letters, digits, `.`, `_`, `+`, or `-`. The folder name and
  `manifest.json`'s `version` must match exactly.
- Put `icon.svg` directly in the slug folder. It must be a non-empty file.

For an update, add a new version directory and keep the previous one so an
existing app can continue to refer to its installed version.

## 2. Write `manifest.json`

Start from the [Redis manifest](redis/8.8.3/manifest.json) or the
[Miniflux manifest](miniflux/2.3.3/manifest.json). The validator reads the
manifest directly; there is no separate schema file.

| Field                   | Requirement                                                                                                                                                                                                                         |
| ----------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `schema`                | Required; the current value is `1`.                                                                                                                                                                                                 |
| `slug`, `version`       | Required; must match the containing directories.                                                                                                                                                                                    |
| `name`                  | Required; 1–64 characters.                                                                                                                                                                                                          |
| `summary`               | Required; 1–200 characters for the catalog list.                                                                                                                                                                                    |
| `description`           | Optional string. Explain what the app does and how its values or ports are used.                                                                                                                                                    |
| `category`              | Required; one of `monitoring`, `automation`, `dev`, `cms`, `storage`, `security`, `database`, `analytics`, or `other`.                                                                                                              |
| `homepage`, `changelog` | Optional HTTPS URLs.                                                                                                                                                                                                                |
| `license`               | Optional string naming the app's license.                                                                                                                                                                                           |
| `admin_only`            | Optional boolean; defaults to `false`.                                                                                                                                                                                              |
| `arch`                  | Required, non-empty list containing only `amd64` and/or `arm64`.                                                                                                                                                                    |
| `images`                | Required, non-empty list of image references ending in `@sha256:<64 lowercase hex digits>`. Image names and tags must be lowercase. A tagged example is `name:tag@sha256:...`. Every service image in Compose must be listed here.  |
| `requirements`          | Required object with `memory` and `disk` sizes, such as `128M` or `1G`. Each size has 1–7 digits followed by uppercase `M` or `G`.                                                                                                  |
| `limits`                | Required object with a `memory` size and `cpus` as a JSON string, such as `"256M"` and `"0.5"`. CPU values allow 1–3 digits and up to two decimal places.                                                                           |
| `ports`                 | Optional map keyed by 1–16 lowercase letters/digits, starting with a letter. Each entry has a Compose `service`, integer `container` port from 1 to 65535, and optional `description`. Use the `web` name for an HTTP health check. |
| `volumes`               | Optional list of objects with unique `name` values, such as `[{"name":"data"}]`. A name is 1–32 characters, starts with a lowercase letter, and contains lowercase letters, digits, or `_`.                                         |
| `variables`             | Optional list of unique environment variables. Names are uppercase, start with a letter, and must not start with reserved prefix `ARC_`. Each has a `label` and a `type`; see below.                                                |
| `healthcheck`           | Required object. `type` is `http` or `container`; `timeout` is 10–1800 seconds. HTTP checks also need `ports.web`, an absolute `path`, and a non-empty numeric `expect` list.                                                       |
| `dumps`                 | Optional list of backup commands. Each entry names an existing Compose `service`, a non-empty `command`, and a safe output `file` name.                                                                                             |
| `post_install`          | Optional string shown to the app owner after installation.                                                                                                                                                                          |

Each variable needs a `label` and one of these types: `string`, `int`, `bool`,
`enum`, `password`, `email`, or `url`. Use `options` for an `enum`; optional
`default` and `pattern` values must be strings. `required` and `immutable` are
booleans and default to `false`. Values are limited to 1024 characters and may
not contain quotes, `$`, backticks, backslashes, or line breaks. Type-specific
checks apply: for example, `bool` accepts `true` or `false`, and `email` must
look like an email address.

Defaults may use ArcPanel's supported placeholders, including `{{random:N}}`,
`{{user}}`, `{{domain}}`, `{{url}}`, `{{user.email}}`, and
`{{server.timezone}}`. A `{{random:N}}` default generates a random value of N
characters. Never put a real password, API key, or other secret in the
manifest or Compose file.

ArcPanel validates each variable value when it renders it: at install, on a
settings change, and in step 5 of the catalog check. The manifest-only step
does not check values. A `password` value needs at least 8 characters, so a
default such as `{{random:6}}` fails when rendered. ArcPanel also checks any
`pattern` against the rendered value.

## 3. Write `compose.yaml`

Use a `services` map and the exact image references declared in `images`.
Service names referenced by `ports` and `dumps` must exist. For each declared
volume, use `${ARC_DATA_<NAME>}` with the volume name in uppercase: `data`
becomes `${ARC_DATA_DATA}`, and `db_files` becomes `${ARC_DATA_DB_FILES}`. Each
bind source must be exactly the folder for one declared volume; ArcPanel refuses
subfolders and other paths. Do not use named Docker volumes. ArcPanel also
provides `ARC_UID`, `ARC_GID`, `ARC_USER`, `ARC_APP`, `ARC_DOMAIN`, `ARC_URL`,
and `ARC_PORT_<PORTNAME>` values. Do not declare a variable beginning with
`ARC_`; those names are reserved.

The generated `compose.arcpanel.yaml` override supplies `user`, `cap_drop`,
`security_opt`, `cgroup_parent`, `cpus`, `mem_limit`, `restart`, `logging`,
labels, and the port bindings declared in the manifest. Leave those settings out
of `compose.yaml`. Compose merges `security_opt` lists, and the final list must
be exactly `["no-new-privileges:true"]`. Do not publish additional ports in
`compose.yaml`.

The merged project is refused if it asks for privileged mode, added
capabilities, devices, host namespaces, the Docker socket, named volumes,
secrets, configs, a build, or another forbidden Compose feature. Networks must
be non-external bridge networks, and bind mounts must stay under this app's data
directory. The only allowed `extra_hosts` entry is
`host.docker.internal:host-gateway`. Containers must run as the app user, drop
all capabilities, use `no-new-privileges`, and stay in that user's cgroup.
These restrictions protect the host and other hosting users; do not work around
them in the Compose file.

For a `container` health check, define a Compose health check for the service
whose health ArcPanel should wait for. The catalog validator checks the
manifest fields; it does not require a Compose health-check stanza.

For shell expansion inside a container, use `$$VARIABLE` so Compose passes a
single `$` through to the container. The Redis example uses this for the
password passed to `redis-server`.

## 4. Worked example: Redis

The [Redis manifest](redis/8.8.3/manifest.json) and
[Compose file](redis/8.8.3/compose.yaml) show a small single-service app:

- `category` is `database`, and the Redis image is pinned by digest.
- The `redis` port points to service `redis` on container port `6379`; the
  description tells owners to connect through the private `127.0.0.1` address
  and port shown on the app page.
- The `data` volume maps to `${ARC_DATA_DATA}`, keeping persistent data in the
  app's own data folder.
- `PASSWORD` is a required password variable with a generated default. The
  Compose service maps it to `REDIS_PASSWORD`.
- The container health check verifies Redis responds to `PING`, and
  `post_install` tells the owner how to connect.

Use the same pattern when adding an app with a database or another persistent
service: declare every service image, port, volume, variable, and backup dump
in the manifest so ArcPanel can render and validate the complete project.

## 5. Run the catalog check

Run the validator on an ArcPanel test server with the Docker CLI and Compose
plugin installed. The Docker engine does not need to be running; the command
renders the Compose configuration but does not pull images.

```bash
# Validate the catalog installed with ArcPanel.
sudo /usr/local/arcpanel/bin/v-check-docker-catalog

# Validate a catalog directory and print machine-readable results.
sudo /usr/local/arcpanel/bin/v-check-docker-catalog /path/to/docker-catalog json
```

If the catalog directory, Docker CLI, or Compose plugin is missing, the command
stops with `E_NOTEXIST` before checking any apps. JSON output has uppercase keys:
`SLUG`, `VERSION`, `STATUS` (`ok` or `invalid`), and `ERRORS`.

The optional first argument is a catalog directory; the optional second
argument is `json` (the default output is human-readable). For each app and
version, the command runs these checks in order:

1. Confirm the optional directory exists, then confirm the Docker CLI and
   Compose plugin are available.
2. Check each app folder's slug. Find its valid version folders, each of which
   must contain `manifest.json`; report an app with no valid versions.
3. For every version, validate the manifest in this order: JSON object; schema,
   slug and version; name and summary; description; category; HTTPS homepage
   and changelog; license; `admin_only`; architecture; pinned images;
   requirements; limits; ports; volumes; unique volume names; variables;
   unique variable names; health-check type, HTTP fields and timeout; dumps;
   and post-install text.
4. Check that `icon.svg` and `compose.yaml` exist and are non-empty.
5. If the earlier checks found no errors, substitute defaults and safe sample
   values for variables that have no default, validate the rendered values,
   merge ArcPanel's override, and run `docker compose config`.
6. Inspect the rendered configuration in this order:
   - For each service, reject `privileged`, `cap_add`, `devices`,
     `device_cgroup_rules`, `network_mode`, `pid`, `ipc`, `uts`, `userns_mode`,
     `cgroup`, `build`, `volumes_from`, `sysctls`, `runtime`, `isolation`,
     `env_file`, `label_file`, `group_add`, `credential_spec`,
     `use_api_socket`, `gpus`, `models`, and `provider`.
   - Require the app user's UID/GID, `cap_drop: [ALL]`,
     `security_opt: [no-new-privileges:true]`, the app user's cgroup, and an
     image listed in the manifest.
   - Check `extra_hosts` (only `host.docker.internal:host-gateway` is allowed),
     then allow only bind or tmpfs mounts. Bind sources must be exactly one
     declared volume folder under the app's data directory and must not name
     `docker.sock`.
   - Check that published ports match the manifest, then reject external or
     non-bridge networks, named volumes, secrets, and configs.
   - Finally, require each manifest port and dump to name an existing
     Compose service.
7. Print the result for each version. Invalid entries make the command exit
   with `E_INVALID`.

Steps 5 and 6 run only when the preceding manifest and file checks pass. A first
run can therefore report manifest errors without Compose errors; fix those and
run the check again to reach the rendered-Compose checks. The
[`test/arcpanel-docker.bats`](../../../../test/arcpanel-docker.bats) suite
covers the shipped catalog and invalid manifest/Compose cases. When adding a
catalog app, add its slug to `APPS` in
[`test/arcpanel-docker-catalog.bats`](../../../../test/arcpanel-docker-catalog.bats).
That end-to-end suite installs each app with default variables, waits for its
health check, and verifies the hosting user and capabilities. Both BATS suites
run on the test server, not a production host; the catalog suite pulls the real
container images.
