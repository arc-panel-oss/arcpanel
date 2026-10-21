---
type: changed
order: 30
---

- A queued task whose command fails and asks to run again (Let's Encrypt while DNS propagates) is retried at most `QUEUE_MAX_ATTEMPTS` times, 3 by default, and then fails; before, it was retried every five minutes without end. Every failed queue task carries the reason from its log in `error`.
