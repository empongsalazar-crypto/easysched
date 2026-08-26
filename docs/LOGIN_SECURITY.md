# Login Security

EasySched now applies several controls without revealing whether a username exists:

- Per-IP throttle: limits attacks from one network address.
- Per-account throttle: protects one account even when attempts come from different addresses.
- Fifteen-minute rolling window: stale counters expire.
- One-minute temporary lockout after eight failures: the lockout is short enough for local school use while still slowing repeated attacks.
- Exponential delay: failed attempts incur increasing server-side delay up to eight seconds.
- Generic response: invalid username, inactive account, wrong password, invalid input, and failed challenge use the same public login message.
- Adaptive challenge: after three failures, the login form displays a short arithmetic challenge. The challenge is stored in the server session and is never trusted from the browser.
- Audit event: failures store only a hash of the username and IP address, plus a reason. Passwords and raw usernames are never written to the audit log.

Normal users should not see the challenge. After a successful login, the IP and account counters and challenge are cleared.

The current free/local build records suspicious activity in `audit_logs` and shows an in-app security notice after a successful login when failed attempts were recorded for that account in the previous 24 hours. Email or SMS notifications require an approved institutional mail/SMS provider and are intentionally not enabled by default.

Audit events created by EasySched use Philippine Standard Time (`Asia/Manila`). Existing records created before this change may still show UTC; they are preserved and are not rewritten automatically.
