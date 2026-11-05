---
type: added
---

- Alert channels (E16): email, Telegram, Slack, Discord and Mattermost next to the panel channel, with the new commands `v-add-alert-channel`, `v-change-alert-channel`, `v-delete-alert-channel` and `v-test-alert-channel`. The Telegram bot token and the webhook URLs are stored in the secret store of the channel's owner (`/arcpanel/alerts/ID`) and never shown again; `curl` gets them in a private file, never on its command line.
- A failed delivery is retried in the next three runs of the engine; a channel that fails ten times in a row is turned off and the panel channel says so. A channel sends at most 30 messages an hour, then one message with the number of alerts it held back.
