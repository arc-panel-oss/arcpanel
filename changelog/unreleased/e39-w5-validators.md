---
type: security
---

- Cron schedules follow the crontab grammar in the commands, the pages and API v2: numbers within each field's range, `*`, ranges, steps and lists only. Names, macros and values outside the range are refused before any command runs.
- Commands refuse `|` in user and object names and any control character in single-line values; the full name of an account refuses quotes, `$`, backticks, `<` and `>`. The 2.6.0 upgrade lists existing records that the new rules refuse in the upgrade log; they keep working until they are changed.
- A secret that a Docker app uses can no longer be changed to a value that fails the checks of the app's variable, and an app start refuses such a value.
