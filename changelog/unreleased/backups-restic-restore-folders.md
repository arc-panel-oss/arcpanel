---
type: fixed
order: 30
---

- A restic restore of a user's folders works with restic 0.16, which Ubuntu 24.04 ships; it stopped with "Unable to download user folders", because restic before 0.17 refuses `--include` together with `--exclude`.
