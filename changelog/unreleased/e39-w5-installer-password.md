---
type: security
---

- The installers keep the administrator password off the command lines of the commands they run, and the new `--password-file FILE` (`-P`) option reads it from a file; the help now warns that `--password` is visible to other users of the machine. The welcome e-mail no longer contains the password, and the installation summary, the panel certificate and the sorted configuration are written to private temporary files instead of fixed names in `/tmp`.
