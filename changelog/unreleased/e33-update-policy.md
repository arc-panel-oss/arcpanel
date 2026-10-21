---
type: changed
order: 5
---

- **Automatic updates follow the update policy.** New installs get the policy `patch` with a random 2-hour night window. The 2.5.0 upgrade gives a server whose automatic updates were on the policy `patch`, with a window that starts at the hour of its old nightly update and a delay of 2 days, and a server whose automatic updates were off the policy `notify`. An hourly check (`v-run-sys-arcpanel-autoupdate`) replaces the nightly `v-update-sys-arcpanel-all` line. `v-add-cron-arcpanel-autoupdate` now sets the policy `patch` and `v-delete-cron-arcpanel-autoupdate` the policy `notify`, keeping the window and the delay; `v-list-sys-arcpanel-autoupdate` reports `Enabled` for the policy `patch`, and its `json` output is valid JSON. The `auto_update` setting of REST API v2 maps to the same policies.
