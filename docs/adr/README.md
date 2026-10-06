# Architecture Decision Records

ADRs record decisions that are expensive to reverse. Each one states the context, the options considered, the decision and its consequences. The roadmap (§6, Q1) lists the decisions that must be taken before the corresponding epic starts; they are drafted here with a recommendation and become **Accepted** after team review.

| ADR                                         | Title                                                                          | Epic   | Status                    |
| ------------------------------------------- | ------------------------------------------------------------------------------ | ------ | ------------------------- |
| [0001](./0001-view-layer.md)                | View layer: Blade + Alpine.js, Vue 3 islands for interactive screens           | E1, E2 | Accepted                  |
| [0002](./0002-url-scheme.md)                | URL scheme during migration: `/app/*` prefix, root at 2.0                      | E1, E8 | Accepted                  |
| [0003](./0003-docker-mode.md)               | Docker: single root engine with per-user Compose projects (rootless deferred)  | E4     | Accepted                  |
| [0004](./0004-reseller-quota.md)            | Reseller quota semantics: soft nested quota, enforced at allocation            | E11    | Proposed                  |
| [0005](./0005-pm2-process-model.md)         | Node process model: one pm2 daemon per user under `systemd --user`             | E14    | Proposed                  |
| [0006](./0006-rebranding-depth.md)          | Rebranding depth: visible + distribution + compatibility layer; own versioning | E9     | Partly superseded by 0007 |
| [0007](./0007-full-rename-and-hard-fork.md) | Full rename of internal names and hard fork from HestiaCP                      | E9     | Accepted                  |

## Format

```markdown
# ADR-NNNN — Title

**Status:** Proposed | Accepted | Superseded by ADR-MMMM · **Date:** YYYY-MM-DD · **Epic:** Ex

## Context

## Options

## Decision

## Consequences
```

New ADRs take the next number. An ADR is never edited after acceptance except to change its status; a new ADR supersedes it.
