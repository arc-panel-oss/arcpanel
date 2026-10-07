# E34 REST API v2, batch 2 — design

**Status:** Approved 2026-10-07 · **Epic:** E34 (REST API v2) · **Base design:** [02 Laravel foundation](../../design/02-laravel-foundation.md) §4.3, [03 Tenancy](../../design/03-tenancy-and-authorization.md) §7–§8 · **Previous spec:** [E34 batch 1](./2026-10-07-002-e34-api-v2-design.md) · **Roadmap:** [E34](../../roadmap.md#e34--rest-api-v2)

## 1. Summary

Batch 1 built the foundation of API v2 and the users, packages and web domains. This spec does two things:

1. It records the decisions **shared by every remaining batch** (D18–D24): permission sets per area, one task endpoint for long operations, pagination, protection against duplicates on retries, access keys through the API, what stays out of v2, and one way to download files.
2. It designs **batch 2**: DNS zones and records, mail domains and accounts, databases and cron jobs. All four are plain JSON resources with the shape of the web domains of batch 1 and nothing queued except the Let's Encrypt certificate of a mail domain.

§10 outlines batches 3–6 (tasks and backups, applications, files, server and account). Each gets its own spec when it starts; the decisions of §2 bind them.

Everything in batch 1 stays valid. Where this spec changes batch 1 behaviour (pagination, area sets, the `Link` header of `202`), the change is additive (D14).

## 2. Decisions shared by batches 2–6

| #   | Question                    | Decision                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                         |
| --- | --------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| D18 | Permission sets per area    | `v2-read` and `v2-write` stay and remain the union of every area. Each area gets a set of its own with its read and write commands, the restarts they lead to and the foundation reads (`GET /me`, the user): `v2-accounts` (users, packages) and `v2-web` (web domains) for batch 1, `v2-dns`, `v2-mail`, `v2-db`, `v2-cron` in batch 2, then `v2-backup`, `v2-apps`, `v2-files`, `v2-server`. A key may hold several sets. A plan that crosses areas needs every set it touches: a `v2-web` key that creates a web domain with `dns: true` is refused before the first step, with the missing command named. Area sets are marked `API_VERSION='2'`, so API v1 skips them like `v2-read` and `v2-write`.                       |
| D19 | Long operations             | One read-only task endpoint, `GET /api/v2/tasks/{id}`, built in batch 3 as a facade over the sources the panel has: the system task, the Node.js task of a user, the Docker task of an app, and the queues of backups and Let's Encrypt. No new store; when E15 (Job Center) arrives it becomes the source and the endpoint stays. The schema is fixed now: `id`, `kind`, `status` (`queued`, `running`, `succeeded`, `failed`), `exit_code`, `error`, `started_at`, `finished_at`, `log` (the tail), `resource` (path of the object it works on). Every `202` carries `Link: </api/v2/tasks/{id}>; rel="monitor"`. The Let's Encrypt `202` of batch 1 keeps its `Location` (the domain) and gains the `Link` header in batch 3. |
| D20 | Pagination                  | Every list accepts `?limit=` (1–1000) and `?offset=` (0 or more), and `meta.total` is always present. Without `limit` a list answers in full, as in batch 1, so existing clients see no change. Logs (batch 6) are the exception: 100 lines unless asked for more. The order is that of the `v-list-*` command, so pages are stable while nothing changes. Applies to the lists of batch 1 too.                                                                                                                                                                                                                                                                                                                                  |
| D21 | Duplicates on retries       | Resources whose id the server assigns (DNS records, cron jobs, later firewall rules) get it from the API: the next id after the highest one is passed to the command, so the dry run shows it and the `201` carries `Location`. A create identical to an existing object (§6.1, §6.4) answers `409 E_EXISTS` with `error.existing`, the path of that object. Resources named by the client keep the D17 rule. No `Idempotency-Key`.                                                                                                                                                                                                                                                                                              |
| D22 | Access keys through the API | Batch 6 lets a key create keys, with only the permission sets that the calling key holds itself (an administrator's key without sets may give any). The secret is returned once, by the create.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                  |
| D23 | Not in v2                   | Web terminal, impersonation, the config file editors of services, the white-label logo upload, phpMyAdmin single sign-on (its token is bound to the browser's IP), live log streams (v2 returns snapshots of logs).                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              |
| D24 | Binary downloads            | One mechanism, designed in batch 3 for backup archives: the endpoint answers the file itself through `X-Accel-Redirect` to an internal nginx location, as the panel's pages do. Database dumps (batch 3) and file downloads (batch 5) use it. Batch 2 is JSON only.                                                                                                                                                                                                                                                                                                                                                                                                                                                              |

## 3. Decisions of batch 2

| #   | Question               | Decision                                                                                                                                                                                                                                                                                                                                                                                                                                                                                           |
| --- | ---------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| D25 | Code shape             | As batch 1 (D2): API controllers of their own on the planners the pages already use (`DnsService`, `MailDomainPlanner`, `MailAccountPlanner`, `DatabaseService`, `CronService`), with `PlanGate`. The few checks that still live in web controllers move into `DnsActions`, `MailActions` and `DatabaseActions`, which the web controllers then call. A generic CRUD controller was rejected: it would hide the `operationId`s and schemas and spread one authorization mistake to every resource. |
| D26 | Paths                  | Under the owner, like web domains: `/users/{user}/dns-zones`, `/users/{user}/mail-domains`, `/users/{user}/databases`, `/users/{user}/cron-jobs`. Outside the principal's tree `404`, refused inside it `403` (D4).                                                                                                                                                                                                                                                                                |
| D27 | Let's Encrypt for mail | Queued, like the web domains (D15): `202` with `Location` of the mail domain. The mail page runs `v-add-letsencrypt-domain` at once; the API does not, so that it never has to change a `200` into a `202`. `v-schedule-letsencrypt-domain` gets a last optional argument `[MAIL]`.                                                                                                                                                                                                                |
| D28 | Cron schedule          | One field `schedule` with the five crontab fields (`*/5 * * * *`), validated field by field, instead of five fields. The answer carries the same string.                                                                                                                                                                                                                                                                                                                                           |
| D29 | Database names         | Bodies use the short names (`shop`, not `alice_shop`); `v-add-database` adds the owner prefix (lower case for PostgreSQL). Paths and answers use the full name, and answers carry `short_name` too. A short name that already starts with the prefix is refused (`422`), so `alice_alice_shop` cannot happen by mistake.                                                                                                                                                                           |
| D30 | Area of a route        | Each route group declares its area (`->defaults('api_area', 'dns')`). Tests use it to give a key exactly the area set of the endpoint under test.                                                                                                                                                                                                                                                                                                                                                  |

## 4. Milestones

| #   | Milestone      | Content | Done when                                                                                                                                                                                      |
| --- | -------------- | ------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| M1  | Shared         | §5      | Pagination on every list of batch 1; area sets `v2-accounts` and `v2-web` with D30 on the batch 1 routes; `error.existing`; the test helper for area keys; BATS and Pest cases for all of them |
| M2  | DNS            | §6.1    | Zone, record and DNSSEC endpoints and `v2-dns`, with the test matrix of §8                                                                                                                     |
| M3  | Mail domains   | §6.2    | Mail domain endpoints (SSL, Let's Encrypt, SMTP relay, DNS records) and `v2-mail`; `v-schedule-letsencrypt-domain … MAIL` with BATS cases                                                      |
| M4  | Mail accounts  | §6.3    | Mail account endpoints, added to `v2-mail`                                                                                                                                                     |
| M5  | Databases      | §6.4    | Database endpoints and `v2-db`                                                                                                                                                                 |
| M6  | Cron           | §6.5    | Cron job and report endpoints and `v2-cron`                                                                                                                                                    |
| M7  | Docs and smoke | §8, §9  | Smoke green on `arcpanel-test`; `docs/api/v2/openapi.json` current; documents of §9 updated; implementation notes appended                                                                     |

Commits: `feat(e34): …` per milestone, `docs(e34): …` for M7.

## 5. Shared changes (M1)

### 5.1 Pagination

- `App\Api\Paginates` takes the full list, reads `limit` and `offset` (validated: integers, `limit` 1–1000, `offset` ≥ 0, else `422 E_VALIDATION`), slices it and returns the `meta` object: `{"total": N}` without `limit`, `{"total": N, "limit": L, "offset": O}` with it.
- Every list controller of batch 1 (`listUsers`, `listPackages`, `listWebDomains`) and of batch 2 uses it. `arcpanel:api-docs` adds the two query parameters and the `meta` schema to every list operation.

### 5.2 Area sets

- New set files in `install/common/api/`: `v2-accounts`, `v2-web` (M1), `v2-dns` (M2), `v2-mail` (M3, M4), `v2-db` (M5), `v2-cron` (M6). Each has `ROLE='user'`, `API_VERSION='2'` and its `COMMANDS`. They are installed and refreshed like `v2-read` and `v2-write`.
- The access key page shows a one-line hint under each v2 set, as it does for `v2-read` and `v2-write`.
- Rules checked by Pest unit tests: every area set is part of `v2-write`; the read commands of every area set are part of `v2-read`; `v2-write` holds nothing that no area set holds.

### 5.3 Errors

- The `Error` schema gets the optional field `existing`, a path under `/api/v2` (D21). `ApiException` takes it as an optional argument.

### 5.4 Test helper

- `apiAs()` gets an `area:` option: the key then holds only the area set of the route under test (D30) instead of `v2-write`. The main path of every batch 2 endpoint runs with such a key.

## 6. Endpoints of batch 2

All paths start with `/api/v2/users/{user}`. Every role may call them for the users of its tree (D26); "admin" marks what only an administrator may do, as on the pages. Unless a row says otherwise: a create answers `201` with `Location`, `DELETE` answers `204`, `suspend` and `unsuspend` are idempotent (`200` and no command when the object is already in that state, as in batch 1), `rebuild` is for administrators (`403` otherwise), and every write accepts `?dry_run=1`.

### 6.1 DNS (`v2-dns`)

| Method and path                                   | `operationId`        | Notes                                                                                                                                        |
| ------------------------------------------------- | -------------------- | -------------------------------------------------------------------------------------------------------------------------------------------- |
| `GET /dns-zones`                                  | `listDnsZones`       |                                                                                                                                              |
| `POST /dns-zones`                                 | `createDnsZone`      | `domain`; optional `ip` (first address of `v-list-user-ips`), `template`, `nameservers` (1–8, default the user's), `dnssec`, `ttl`, `expiry` |
| `GET /dns-zones/{domain}`                         | `getDnsZone`         |                                                                                                                                              |
| `PATCH /dns-zones/{domain}`                       | `updateDnsZone`      | `ip`, `template`, `ttl`, `soa`, `expiry`, `dnssec`                                                                                           |
| `DELETE /dns-zones/{domain}`                      | `deleteDnsZone`      |                                                                                                                                              |
| `POST /dns-zones/{domain}/suspend`                | `suspendDnsZone`     |                                                                                                                                              |
| `POST /dns-zones/{domain}/unsuspend`              | `unsuspendDnsZone`   |                                                                                                                                              |
| `POST /dns-zones/{domain}/rebuild`                | `rebuildDnsZone`     | admin                                                                                                                                        |
| `GET /dns-zones/{domain}/dnssec`                  | `getDnsZoneDnssec`   | Public key and DS record (`v-list-dnssec-public-key`); `404` when the zone has no DNSSEC                                                     |
| `GET /dns-zones/{domain}/records`                 | `listDnsRecords`     | Also filters `?name=` and `?type=` (exact), for ACME DNS-01 clients                                                                          |
| `POST /dns-zones/{domain}/records`                | `createDnsRecord`    | `name`, `type`, `value`; optional `priority`, `ttl`. Id per D21                                                                              |
| `GET /dns-zones/{domain}/records/{id}`            | `getDnsRecord`       |                                                                                                                                              |
| `PATCH /dns-zones/{domain}/records/{id}`          | `updateDnsRecord`    | `name`, `type`, `value`, `priority`, `ttl`; the id does not change                                                                           |
| `DELETE /dns-zones/{domain}/records/{id}`         | `deleteDnsRecord`    |                                                                                                                                              |
| `POST /dns-zones/{domain}/records/{id}/suspend`   | `suspendDnsRecord`   |                                                                                                                                              |
| `POST /dns-zones/{domain}/records/{id}/unsuspend` | `unsuspendDnsRecord` |                                                                                                                                              |

- **Plans** come from `DnsService` (`planCreate`, `planUpdate`, `planAddRecord`, `planUpdateRecord`): changes run with `RESTART=no` and one `v-restart-dns` at the end, as on the pages. Single actions run their command with its own restart, as in batch 1.
- **Templates:** `template` needs `DnsPolicy::updateTemplates()` (administrators, or `POLICY_USER_EDIT_DNS_TEMPLATES`), on create and update, else `403`. The check of a template name moves from `DnsController::assertTemplate` into `DnsActions`.
- **DNSSEC:** `dnssec` on a server without support (`DNS_CLUSTER_SYSTEM` other than `arcpanel-zone`, or no `SUPPORT_DNSSEC`) is a `422` on that field.
- **Defaults:** a new zone has TTL 14400 and expires in one year; only values that differ add a step, as on the add page. `soa` can only be changed after the create (the command sets it).
- **Duplicates:** a zone the user has answers `409 E_EXISTS` before any check (D17). A record with the same `name`, `type`, `value` and `priority` as an existing one answers `409 E_EXISTS` with `existing` (D21).
- **Ids:** `createDnsRecord` passes the next id to `v-add-dns-record`. Two creates at the same moment may pick the same id; the second fails with the command's `E_EXISTS` (`409`) and the client reads the list again. No lock.

### 6.2 Mail domains (`v2-mail`)

| Method and path                            | `operationId`                  | Notes                                                                                                                                                            |
| ------------------------------------------ | ------------------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `GET /mail-domains`                        | `listMailDomains`              |                                                                                                                                                                  |
| `POST /mail-domains`                       | `createMailDomain`             | `domain`; optional `antispam`, `antivirus`, `dkim` (defaults of the add page)                                                                                    |
| `GET /mail-domains/{domain}`               | `getMailDomain`                |                                                                                                                                                                  |
| `PATCH /mail-domains/{domain}`             | `updateMailDomain`             | The General tab: `antispam`, `antivirus`, `dkim`, `reject`, `catchall` (address or `null`), `webmail` (client name or `null`), `rate_limit` (admin, else `403`)  |
| `DELETE /mail-domains/{domain}`            | `deleteMailDomain`             |                                                                                                                                                                  |
| `POST /mail-domains/{domain}/suspend`      | `suspendMailDomain`            |                                                                                                                                                                  |
| `POST /mail-domains/{domain}/unsuspend`    | `unsuspendMailDomain`          |                                                                                                                                                                  |
| `POST /mail-domains/{domain}/rebuild`      | `rebuildMailDomain`            | admin                                                                                                                                                            |
| `PUT /mail-domains/{domain}/ssl`           | `setMailDomainSsl`             | `certificate`, `key`, `ca`; without `certificate` nothing else can change, so it is required                                                                     |
| `DELETE /mail-domains/{domain}/ssl`        | `deleteMailDomainSsl`          | Idempotent: `204` without SSL                                                                                                                                    |
| `POST /mail-domains/{domain}/letsencrypt`  | `requestMailDomainLetsEncrypt` | `202` with `Location` of the mail domain (D27); optional `key_algorithm`                                                                                         |
| `PUT /mail-domains/{domain}/smtp-relay`    | `setMailDomainSmtpRelay`       | `host`, `port` (587), `username`, `password`                                                                                                                     |
| `DELETE /mail-domains/{domain}/smtp-relay` | `deleteMailDomainSmtpRelay`    | Idempotent                                                                                                                                                       |
| `GET /mail-domains/{domain}/dns-records`   | `getMailDomainDnsRecords`      | The records the domain needs (A of `mail.` and the webmail alias, MX, SPF, DMARC, DKIM), from `MailService::dnsHints()`, each as `{name, type, value, priority}` |

- **Plans** come from `MailDomainPlanner` (`planCreate`, `planGeneral`, `planSsl`, `planRelay`).
- **Server switches:** `antispam` without `ANTISPAM_SYSTEM`, `antivirus` without `ANTIVIRUS_SYSTEM`, and `webmail` without `IMAP_SYSTEM` and `WEBMAIL_SYSTEM` are `422` on their field (the page hides them). A webmail client the server does not have is a `422`; the check moves into `MailActions`.
- **SSL:** a first uploaded certificate needs `key` (moved into `MailActions` with the web page's rule). Replacing a Let's Encrypt certificate with an uploaded one removes the Let's Encrypt certificate first, in the same plan (the page needs two saves). `ssl.letsencrypt` turns true once the queued certificate is installed.
- **SMTP relay:** re-added only when `host`, `username` or `port` changed or a `password` was sent, as on the page. The password goes to the command as a `Secret` file and is never returned.
- **`v-schedule-letsencrypt-domain USER DOMAIN [ALIASES] [KEY_ALGO] [MAIL]`:** with `MAIL=yes` it queues `v-add-letsencrypt-domain USER DOMAIN '' yes KEY_ALGO`. A line queued before for the same domain and the same kind (web or mail) is replaced; a web line and a mail line of one domain may both wait.
- **Duplicates:** a mail domain the user has answers `409 E_EXISTS` before any check.

### 6.3 Mail accounts (`v2-mail`)

| Method and path                                            | `operationId`               | Notes                                                                                                                                             |
| ---------------------------------------------------------- | --------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------- |
| `GET /mail-domains/{domain}/accounts`                      | `listMailAccounts`          |                                                                                                                                                   |
| `POST /mail-domains/{domain}/accounts`                     | `createMailAccount`         | `account`, `password`; optional `quota_mb` (`null` unlimited), `aliases`, `forwards`, `forward_only`, `rate_limit` (admin), `send_credentials_to` |
| `GET /mail-domains/{domain}/accounts/{account}`            | `getMailAccount`            |                                                                                                                                                   |
| `PATCH /mail-domains/{domain}/accounts/{account}`          | `updateMailAccount`         | `quota_mb`, `aliases`, `forwards`, `forward_only`, `discard_all`, `autoreply` (text or `null`), `rate_limit` (admin)                              |
| `PUT /mail-domains/{domain}/accounts/{account}/password`   | `changeMailAccountPassword` | `204`                                                                                                                                             |
| `DELETE /mail-domains/{domain}/accounts/{account}`         | `deleteMailAccount`         |                                                                                                                                                   |
| `POST /mail-domains/{domain}/accounts/{account}/suspend`   | `suspendMailAccount`        |                                                                                                                                                   |
| `POST /mail-domains/{domain}/accounts/{account}/unsuspend` | `unsuspendMailAccount`      |                                                                                                                                                   |

- **Plans** come from `MailAccountPlanner` (`planCreate`, `planGeneral`, `planForwarding`, `planAutoreply`). A `PATCH` plans only the tabs whose fields it sends.
- **Lists replace:** `aliases` and `forwards` sent in a `PATCH` are the new full lists; the planner adds and removes the difference.
- **`discard_all`:** the `:blackhole:` forward of the page; `true` with `forwards` is a `422`.
- **Password** follows `DatabaseService::PASSWORD_RULE`, as on the page, and goes to the command as a `Secret` file.
- **`send_credentials_to`:** an e-mail address; after a successful create the panel mails the account's settings there through `HostingCredentialsMailer`, as the page's `email` field does. A failed mail does not fail the create. When the field is sent, the `201` answer carries `meta.credentials_sent` (`true` or `false`) next to `data`; an additive field of the single-object answer.
- **Duplicates:** an account the domain has answers `409 E_EXISTS` before any check.

### 6.4 Databases (`v2-db`)

| Method and path                        | `operationId`            | Notes                                                                                                                                                            |
| -------------------------------------- | ------------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `GET /databases`                       | `listDatabases`          |                                                                                                                                                                  |
| `POST /databases`                      | `createDatabase`         | `name`, `db_user` (short names, D29), `password`; optional `type` (`mysql`, `pgsql`; default the first of `DB_SYSTEM`), `host`, `charset`, `send_credentials_to` |
| `GET /databases/{database}`            | `getDatabase`            | Full name in the path                                                                                                                                            |
| `PATCH /databases/{database}`          | `updateDatabase`         | `db_user`                                                                                                                                                        |
| `PUT /databases/{database}/password`   | `changeDatabasePassword` | `204`                                                                                                                                                            |
| `DELETE /databases/{database}`         | `deleteDatabase`         |                                                                                                                                                                  |
| `POST /databases/{database}/suspend`   | `suspendDatabase`        |                                                                                                                                                                  |
| `POST /databases/{database}/unsuspend` | `unsuspendDatabase`      |                                                                                                                                                                  |
| `POST /databases/{database}/rebuild`   | `rebuildDatabase`        | admin                                                                                                                                                            |

- **Plans** come from `DatabaseService` (`planCreate`, `planUpdate`). The checks of `DatabaseController::store` (type in `DB_SYSTEM`, host in `v-list-database-hosts`, the full name) move into `DatabaseActions`. A type or host the server does not have is a `422` whose message lists the allowed values.
- **Duplicates:** a full name the user has answers `409 E_EXISTS` before any check.
- **`send_credentials_to`:** as in §6.3.
- Dumps come with batch 3 (D24); phpMyAdmin stays with the panel (D23).

### 6.5 Cron (`v2-cron`)

| Method and path                  | `operationId`      | Notes                                                                                        |
| -------------------------------- | ------------------ | -------------------------------------------------------------------------------------------- |
| `GET /cron-jobs`                 | `listCronJobs`     | `meta.timezone`: the server's time zone (`v-get-sys-timezone`)                               |
| `POST /cron-jobs`                | `createCronJob`    | `schedule` (D28), `command`. Id per D21                                                      |
| `GET /cron-jobs/{id}`            | `getCronJob`       |                                                                                              |
| `PATCH /cron-jobs/{id}`          | `updateCronJob`    | `schedule`, `command`                                                                        |
| `DELETE /cron-jobs/{id}`         | `deleteCronJob`    |                                                                                              |
| `POST /cron-jobs/{id}/suspend`   | `suspendCronJob`   |                                                                                              |
| `POST /cron-jobs/{id}/unsuspend` | `unsuspendCronJob` |                                                                                              |
| `GET /cron-reports`              | `getCronReports`   | `{"enabled": bool}`, from the user's `CRON_REPORTS`                                          |
| `PUT /cron-reports`              | `setCronReports`   | `{"enabled": bool}`; `v-add-cron-reports` or `v-delete-cron-reports`, nothing when unchanged |

- **Plans** come from `CronService` (`planCreate`, `planUpdate`, `setReports`).
- **Ids:** `createCronJob` passes the next id to `v-add-cron-job` (its `[JOB]` argument), as §6.1 does for records.
- **Duplicates:** a job with the same `schedule` and `command` answers `409 E_EXISTS` with `existing`.
- **`schedule`:** five fields separated by single spaces, each checked by the rule the page applies to its five fields; macros such as `@daily` are refused (`422`), because the commands store five fields.

## 7. Wire format

Field names follow the records in snake_case (batch 1 §5): booleans for `yes`/`no`, numbers as numbers, `null` for unlimited, ISO 8601 dates, enums in the document. Passwords, private keys and relay passwords are accepted and never returned.

| Resource (schema) | Fields                                                                                                                                                                                                                                                                                                         |
| ----------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `DnsZone`         | `domain`, `user`, `ip`, `template`, `ttl`, `expiry` (date), `soa`, `serial`, `dnssec` (`null` where the server has no DNSSEC), `records` (count), `suspended`, `created_at`                                                                                                                                    |
| `DnsRecord`       | `id`, `name`, `type` (enum of the page's types), `value`, `priority` (`null` for types without one), `ttl` (`null`: the zone's), `suspended`, `created_at`                                                                                                                                                     |
| `DnsZoneDnssec`   | `domain`, `record` (the DNSKEY record), `key_tag`, `flag`, `algorithm`, `key` (the public key), `ds` (the DS record): the fields of `v-list-dnssec-public-key`                                                                                                                                                 |
| `MailDomain`      | `domain`, `user`, `antispam`, `antivirus`, `dkim`, `reject`, `catchall`, `webmail`, `rate_limit`, `ssl` (`enabled`, `letsencrypt`, `key_algorithm`; `certificate` on a single domain only, as batch 1), `smtp_relay` (`host`, `port`, `username`, or `null`), `accounts`, `disk_mb`, `suspended`, `created_at` |
| `MailDnsRecord`   | `name`, `type`, `value`, `priority`                                                                                                                                                                                                                                                                            |
| `MailAccount`     | `account`, `address`, `aliases`, `forwards`, `forward_only`, `discard_all`, `autoreply`, `quota_mb`, `rate_limit`, `disk_mb`, `suspended`, `created_at`                                                                                                                                                        |
| `Database`        | `name`, `short_name`, `user`, `db_user`, `type`, `host`, `charset`, `disk_mb`, `suspended`, `created_at`                                                                                                                                                                                                       |
| `CronJob`         | `id`, `schedule`, `command`, `suspended`, `created_at`                                                                                                                                                                                                                                                         |

## 8. Testing

- **Pest feature**, one file per area (`ApiV2DnsTest`, `ApiV2MailTest`, `ApiV2MailAccountsTest`, `ApiV2DatabasesTest`, `ApiV2CronTest`), through the real middleware with `ArcCommandFake`, as `ApiV2WebDomainsTest`:
  - the read matrix of admin, reseller A, reseller B and user with cross-tenant and unknown-user `404`;
  - the main path of every write with a key that holds **only the area set** (§5.4);
  - read-only `403`, dry run that runs no command, a `v2-read` key refused before any command with the command named, the failed-step body;
  - `409` for duplicates with `existing`, validation errors with API field names, the admin-only fields and actions;
  - pagination and the `name`/`type` filter of records.
- **Pest, M1:** pagination of the batch 1 lists; a `v2-web` key creating a web domain with `dns: true` is refused with `v-add-dns-domain` named and runs nothing; `error.existing` in the document.
- **Pest unit:** the Resources (types, `null`s, no secret fields), `Paginates`, the set rules of §5.2, the `schedule` parser, the database name rule.
- **BATS** (`test/arcpanel-api-v2.bats`): every v2 set file is installed, marked `API_VERSION='2'` and lists existing commands; API v1 skips the area sets; `v-schedule-letsencrypt-domain … MAIL` queues the mail line, keeps one line per domain and kind, and refuses an unknown key type.
- **Smoke** (`test/arcpanel-api-v2-smoke.sh`): a zone and its records, including an ACME DNS-01 round (TXT record created and deleted) with a key that holds only `v2-dns`; a mail domain with an account, its DNS records and a queued Let's Encrypt line (removed afterwards); a database; a cron job and its `409` duplicate; then everything is deleted.
- The web page tests (`DnsTest`, `MailTest`, `MailAccountsTest`, `DatabasesTest`, `CronTest`, `DnsPlannerTest`, `MailPlannerTest`) pass unchanged after the moves of D25.

## 9. Documentation changes

- `docs/roadmap.md`: the E34 status line names batch 2 and the plan of batches 3–6.
- `docs/api/README.md`: area sets, pagination, `error.existing`, the new resources.
- `docs/design/13-data-model-reference.md`: the area set files and the `[MAIL]` argument.
- `docs/design/03-tenancy-and-authorization.md` §8: area sets.
- `AGENTS.md` §11: a new v2 endpoint declares its area, lists its commands in its area set as well as in `v2-read` or `v2-write`, and passes with a key holding only that set.
- `docs/api/openapi.yaml` (API v1): regenerated, so that its overview names the new sets. `scripts/build-openapi.js` already leaves out every set marked `API_VERSION='2'`; it needs no change.

## 10. Later batches (outline)

| Batch | Content                                                                                                                                                                                                                                                                                                                                    | Set         |
| ----- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ----------- |
| 3     | `GET /tasks/{id}` (D19) over the system, Node.js and Docker tasks and the backup and Let's Encrypt queues (a command that reads queue state is needed); `Link rel="monitor"` on every `202`; backup archives (create, list, show, restore all or selected objects, delete, download per D24); restic snapshots; exclusions; database dumps | `v2-backup` |
| 4     | Node.js (runtimes, global packages, apps: install, build, actions, environment, scale), Docker (engine, catalog, apps: environment, limits, domain), the port registry of E12 (a user's ranges, the administrator's registry); every long operation answers `202` with a task                                                              | `v2-apps`   |
| 5     | Files: list, read, write, create, rename, copy, move, delete, permissions, compress, extract, search; chunked upload; download per D24; restore of a file from a snapshot                                                                                                                                                                  | `v2-files`  |
| 6     | Server and account: services, IP addresses, firewall (rules with D21, ipsets, bans), logs (D20), statistics, updates, system packages, notifications, SSH keys, access keys (D22), server settings except D23                                                                                                                              | `v2-server` |

## 11. Out of scope of batch 2

- Renumbering DNS records; bulk operations; option endpoints (lists of templates, hosts, webmail clients); the error messages name the allowed values instead.
- Database dumps (batch 3), phpMyAdmin (D23), webhooks (E29).
- Changes to the mail page's synchronous Let's Encrypt (D27 concerns the API only).

## 12. Risks

| Risk                                                                    | Mitigation                                                                                                           |
| ----------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------- |
| An area set lacks a command its endpoint runs                           | Every main path is tested with a key holding only the area set (§5.4); the set rules of §5.2 are unit-tested         |
| Two creates pick the same id (D21)                                      | The command refuses the second with `E_EXISTS` (`409`); the client reads the list again. Accepted without a lock     |
| A moved check changes the web pages                                     | The web page tests pass unchanged (§8); the moves keep the pages' messages                                           |
| A queued mail certificate fails silently                                | Same as batch 1 (D15): clients poll `GET` until `ssl.letsencrypt` is true; batch 3's task endpoint reports the queue |
| Pagination over a full `v-list-*` output costs as much as no pagination | Accepted: the commands have no paging; the gain is in the size of the answer, which matters most to agents           |

## 13. Implementation notes

The work log of each milestone is appended here, as in the batch 1 spec.
