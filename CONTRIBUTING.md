# ArcPanel - Contribution Guidelines

Thank you for your interest in ArcPanel. ArcPanel is based on HestiaCP 1.10.5, and we regularly merge upstream releases. Please keep that in mind when you change code: see [Keeping upstream merges cheap](#keeping-upstream-merges-cheap).

## Ways to contribute

- **Beta testing**:
  - Install a beta release (tags `v*-beta.*`, published in the `beta` suite of `apt.arcpanel.top`) on a test server. If you find a problem, file an issue report on [GitHub](https://github.com/arc-panel-oss/arcpanel/issues).
  - To build and install the latest code from a branch, use `v-update-sys-hestia-git` (ArcPanel alias: `v-update-sys-arcpanel-git`). See [Building packages](docs/docs/contributing/building.md).
- **Code review and bug fixes**:
  - Read over the code and if you notice errors (even spelling mistakes), submit a pull request with your changes.
- **New features**:
  - Is there a feature you would love to see? Open an issue first so we can discuss it, then submit a pull request. Please remember that it takes time to develop, implement and test new features, so we may not be able to accept every request, or we may put a feature on the backlog for later.
- **Translations**:
  - ArcPanel does not have its own translation project yet, so translation work is paused. If you find a wrong or missing translation, [open an issue](https://github.com/arc-panel-oss/arcpanel/issues) with the language, the page and the text concerned.

## Development Guidelines

Additional information on how to contribute to ArcPanel can be found in the [Development](docs/docs/contributing/development.md) documentation.

### Keeping upstream merges cheap

ArcPanel keeps the HestiaCP internals so that upstream releases can still be merged (see [ADR-0006](docs/adr/0006-rebranding-depth.md)):

- Do not rename internal identifiers: `/usr/local/hestia`, `/etc/hestiacp`, `HESTIA_*` variables, shell function names, PHP class names, the gettext domain `hestiacp` or the `hestiaweb` user.
- User-visible text says "ArcPanel". The check `test/brand-check.sh` fails when user-visible text says "Hestia" or links to `hestiacp.com`; it runs in CI and as a pre-commit hook.
- Keep the original copyright headers and the `LICENSE` file.

### Code formatting and comments

We ask that you follow existing naming schemes and coding conventions where possible, and that you add comments in your source code where appropriate to aid other developers in debugging and understanding your code in the future.

To ensure your changes meet our formatting requirements, please run `npm install` from the root of the repository before committing your changes. This will set up pre-commit hooks for automatic formatting, which will help to get your changes merged as quickly as possible.

### Workflow and process

Development takes place in topic branches that are merged into `master` through pull requests. Releases are tagged from `master`:

| Tag | Description |
| --- | --- |
| `vX.Y.Z-beta.N` | Beta release, published in the `beta` suite of `apt.arcpanel.top`. **Not intended for production use.** |
| `vX.Y.Z` | Stable release, published in the `stable` suite of `apt.arcpanel.top`. |

### Creating a new branch and submitting pull requests

The first step is to create a fork of the [`arc-panel-oss/arcpanel`](https://github.com/arc-panel-oss/arcpanel) repository under your GitHub account so that you may submit pull requests and patches.

Once you've created your fork, clone the repository to your computer and make sure that you've checked out the `master` branch. **Always** create a new topic branch for your work.

### Branch naming convention

- **Prefix:** `topic/` (such as **fix**, **feature**, **refactor**, etc.)
- **ID**: `888` (GitHub Issue ID if an issue exists)
- **Title:** `my-awesome-patch`

Branch name examples:

- `feature/777-my-awesome-new-feature` or `feature/my-other-new-feature`
- `fix/000-some-bug-fix` or `fix/this-feature-is-broken`
- `refactor/v-change-domain-owner`
- `test/mail-domain-ssl`

### Squashing commits for smaller changes

To aid other developers and keep the project's commit history clean, please **squash your commits** when it's appropriate. For example with smaller commits related to the same piece of code, such as commits labelled "Fixed item 1", "Adjusted color of button XYZ", "Adjusted alignment of button XYZ" can be squashed into one commit with the title "Fixed button issues in item".

### What happens when I submit a pull request?

- A maintainer will review your work and validate your request.
- Your changes will be tested to ensure that there are no issues.
- If changes need to be made, you will be notified via GitHub.
- Once approved, your code will be merged for inclusion in an upcoming release of ArcPanel.

All pull requests must include a brief but descriptive title, and a detailed description of the changes that you've made. **Only include commits that are related to your feature, bug fix, or patch in your pull request!**

### Fixes that also apply upstream

If your fix is for code that ArcPanel shares unchanged with the upstream project, consider also proposing it [upstream](https://github.com/hestiacp/hestiacp). This helps both projects and keeps future merges small.

## Thank you

We appreciate **all** contributions no matter what size; your feedback and input directly shape the future of ArcPanel.

Thank you for your time and we look forward to seeing your pull requests,<br>
The ArcPanel maintainers
