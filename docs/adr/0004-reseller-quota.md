# ADR-0004 — Reseller quota semantics

**Status:** Accepted (2026-10-05) · **Date:** 2026-10-04 · **Epic:** E11

## Context

A reseller has its own package and owns sub-users, each with their own package. The roadmap requires that "quotas of sub-users must fit inside the reseller's own package". Two readings exist: a hard nested quota where the tree is throttled or suspended when the reseller's totals are exceeded, and a soft one where limits are checked when capacity is handed out. Hestia today enforces per-user object counts at creation time (`is_package_full`) and reports disk/bandwidth usage without automatic suspension unless filesystem quota is enabled.

## Options

| Option                                                                    | Behaviour                                                                                                                                                                                                              | Pros                                                                                                                                        | Cons                                                                                                                                                                           |
| ------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| **A. Soft nested quota, enforced at allocation**                          | `v-add-reseller-user` and package changes refuse when the sum of sub-user package limits (+ the reseller's own) would exceed the reseller's limits; runtime usage over the limit is reported and alerted, not enforced | matches existing Hestia semantics; no new enforcement loop; predictable for customers (their own package is the only thing that stops them) | a reseller can oversell relative to usage (but not relative to limits); disk overuse is caught by server-level checks as today                                                 |
| B. Hard nested quota                                                      | a cron job compares `R_*` usage with the reseller's package and suspends the newest sub-users (or the whole tree) when exceeded                                                                                        | strict                                                                                                                                      | new failure mode visible to end customers; suspensions caused by a neighbour's usage; requires un-suspend logic and support process; conflicts with filesystem quota semantics |
| C. Usage-based allocation (limits derived from usage, not package values) | allocate when current usage fits                                                                                                                                                                                       | allows overselling                                                                                                                          | unpredictable: a customer's growth makes another customer's creation fail                                                                                                      |

## Decision

**Option A.** Allocation-time checks live in `v-check-reseller-quota` and `v-add-reseller-user` ([03](../design/03-tenancy-and-authorization.md) §4.3, §5); `unlimited` on either side skips that key. Runtime roll-ups (`R_*`) are informational: shown on the reseller dashboard, exported to the admin's Users page, and (E16) turned into alerts at 90 % and 100 %. An optional hard mode may be added later as `POLICY_RESELLER_HARD_QUOTA` without changing the data model.

**Formula (E11 spec D7).** For each total key `WEB_DOMAINS`, `DNS_DOMAINS`, `MAIL_DOMAINS`, `MAIL_ACCOUNTS`, `DATABASES`, `CRON_JOBS`, `DISK_QUOTA` and `BANDWIDTH`: the reseller's own usage `U_X` plus the limit X of every customer's package plus X of the new package must not exceed the reseller's own limit X. A reseller limit of `unlimited` skips the key; a finite reseller limit refuses a package with `unlimited` for that key. The number of customers plus one must not exceed `USERS` of the reseller's package. Per-domain limits (`WEB_ALIASES`, `DNS_RECORDS`) are not totals and are not checked.

## Consequences

- Reseller onboarding never fails because of a neighbour's usage; it fails only when the reseller's plan is sold out, with a clear message naming the key.
- Admins must size reseller packages by limits, not by usage; the docs say so.
- Disk overuse by a tree is handled the same way as for a single user today (reports, admin action, filesystem quota if enabled).
