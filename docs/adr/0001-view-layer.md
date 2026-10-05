# ADR-0001 — View layer: Blade + Alpine.js, with Vue 3 islands

**Status:** Accepted (2026-10-05) · **Date:** 2026-10-04 · **Epic:** E1, E2

## Context

The legacy UI is server-rendered PHP with Alpine.js for interactivity (`web/templates/pages/*.php`, `web/js/src/`). About 78 pages must be rebuilt on Laravel over three quarters by one or two developers. Most pages are CRUD forms and lists over `v-*` JSON output. A few screens (File Manager, App Store, Node Apps, service config editors) are highly interactive. Vue 3 is already a dependency of the repository (docs site, Vitest).

## Options

| Option                                                                   | Cost per CRUD page                                            | Fit for interactive screens                                             | Risk                                                                                                                                                             |
| ------------------------------------------------------------------------ | ------------------------------------------------------------- | ----------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **A. Blade + Alpine.js everywhere**                                      | lowest: closest to the legacy markup and the team's habits    | poor for File Manager / App Store (state, virtual lists, drag-and-drop) | complex Alpine components become unmaintainable                                                                                                                  |
| **B. Inertia + Vue 3 (full SPA-like)**                                   | medium: every page is a Vue component, props from controllers | good                                                                    | new paradigm for every page; SSR not available in `hestia-php` setup; bundle size; i18n and auth bridging in JS; harder to keep Lighthouse scores on low-end VPS |
| **C. Blade + Alpine.js for CRUD, Vue 3 islands for interactive screens** | lowest for ~70 pages                                          | good for the ~5 screens that need it                                    | two component models to keep consistent (mitigated by shared design tokens and a shared i18n map)                                                                |
| D. Livewire                                                              | low                                                           | medium                                                                  | server round-trips for every interaction over `sudo v-*` latency; another runtime to learn                                                                       |

## Decision

**Option C.** Blade components (`<x-ap-*>`) with Alpine.js for all list/add/edit pages and the shell. Vue 3 islands, built as separate esbuild entry points and mounted on `data-island` elements, for: File Manager v2 (E3), App Store (E4/E5), Node Manager and Node Apps (E13/E14), service configuration editors and the log viewer (E7/E18). An island receives its initial data and CSRF token from `data-*` attributes and talks to JSON routes under the same module (`/app/files/api/*`). No Inertia, no SSR, no Vite in production ([05](../design/05-design-system-and-frontend.md) §7).

## Consequences

- Page migration stays cheap and mechanical ([10](../design/10-page-migration-and-retirement.md) §3).
- Two component libraries exist (Blade/Alpine and Vue) but share design tokens, icons, i18n and the same JSON error contract from `ArcCommand`.
- A later move to a full SPA (roadmap §7, post-2.0) remains possible because controllers already expose JSON for islands.
- Alpine's CSP build is required for a nonce-based Content Security Policy ([12](../design/12-security-model.md)).
- The first island is the Node.js page (E13, [spec](../features/specs/2026-10-05-006-e13-node-manager-design.md) §7.1): `scripts/esbuild-vue.js` compiles single-file components with `<script setup>` ahead of time, so pages load Vue's runtime-only build; islands use the panel stylesheet (no `<style>` blocks) and share `http.js`, `toast.js` and the components in `resources/js/islands/shared/`.
