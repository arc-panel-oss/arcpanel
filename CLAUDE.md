# CLAUDE.md

Project guidance for Claude Code in the ArcPanel repository. The full agent guide is shared with every AI tool:

@AGENTS.md

## Claude Code specifics

- **Language:** talk to the maintainer in Vietnamese (brainstorming, questions, progress, summaries). Write code, comments, commit messages, documentation and specs in standard English. See AGENTS.md §1.
- **Start of a task:** read `docs/roadmap.md`, `docs/design/README.md` and the newest spec of the epic in `docs/features/specs/` before proposing anything (AGENTS.md §3). Specs and their "Implementation notes" win over design documents.
- **Creative work (new feature, new page, new command, behaviour change):** brainstorm first and present all open questions together; then write or update the spec in `docs/features/specs/`; then implement test-first, milestone by milestone (AGENTS.md §7). Skip a separate plan document when the maintainer approves the spec and says to implement directly.
- **Verification:** run the suites named in AGENTS.md §8. CLI and HTTP checks run on the WSL test server `arcpanel-test` through `test/dev-sync.sh`; report what was run and what failed, with output.
- **Git:** work on `develop`, Conventional Commits with the epic scope (`feat(e12): …`), push only when the maintainer (PM/PL in `ai-manifest.yaml`) asks. Never force-push or skip hooks.
- **Commit trailers:** never append `Co-Authored-By` or `Claude-Session` (for example `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>` and `Claude-Session: https://claude.ai/code/session_…`). Do not pass them as `git commit` trailers, and strip them if they would be added automatically. See AGENTS.md §6.
- **Never read secret files** (`/root/admin-pass`, `.env*`, `auth.json`, `*secrets*`, `data/secrets/`, session files); existence checks only.
- **Scope discipline:** deliver the whole milestone or say exactly what is missing and why. Do not narrow tests, validations or scope to get green.
- **Prose for the maintainer:** lead with the outcome, keep code out of sentences, name at most one file per sentence, and put commands in fenced `bash` blocks.
