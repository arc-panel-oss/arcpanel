---
type: security
attention: true
---

- Two-factor authentication is turned on from the Security tab of the account: the panel draws the QR code itself, so the secret is no longer sent to a QR code service. The sign-in confirms it with a code from the app and then shows ten single-use recovery codes once, with a download. A recovery code signs in when the app is not at hand. New recovery codes can be created at any time, and the earlier ones then stop working.
- `v-add-user-2fa` now starts an enrolment and `v-change-user-2fa` confirms it with a code. `v-add-user-2fa-recovery-codes` and `v-check-user-2fa-recovery-code` are new. Only hashes of the recovery codes are kept.
- `v-list-user` prints `TWOFA` as `yes` or empty and the number of unused recovery codes as `TWOFA_RECOVERY_LEFT`. It no longer prints the secret or `QRCODE`, which access keys allowed to list users could read. The upgrade and the restore of an older archive remove the stored QR codes.
- A package change keeps the 2FA settings, the recovery codes and the time from which sessions are valid.
- Accounts that already use two-factor authentication keep it but have no recovery codes. The account page asks them to create codes. Until they do, an administrator who turns 2FA off is their only way back in.
