# EasySched API

The frontend calls `api.php?action=...` on the same origin. Protected actions require a server session; mutating actions also require the CSRF token returned by login/bootstrap.

| Action | Method | Access | Purpose |
| --- | --- | --- | --- |
| `login` | POST | Public | Start a session with per-IP/account throttling and an adaptive challenge |
| `health` | GET | Public | Confirm the API and database connection are available |
| `logout` | POST | Authenticated | End the session |
| `bootstrap` | GET | Authenticated | Load role-scoped application state |
| `generate` | POST | Admin/Scheduler | Generate and publish a validated schedule |
| `save_schedule` | POST | Admin/Scheduler | Move a class after validation |
| `delete_schedule` | POST | Admin/Scheduler | Cancel a meeting while preserving history |
| `save_master` | POST | Admin/Scheduler | Manage academic records |
| `change_password` | POST | Authenticated | Change the current password |
| `save_settings` | POST | Admin | Change the active term |
| `export` | GET | Authenticated | Download a role-scoped CSV |

The API is intentionally same-origin and session-based. Do not expose a database provider's table REST endpoint directly to the browser.
