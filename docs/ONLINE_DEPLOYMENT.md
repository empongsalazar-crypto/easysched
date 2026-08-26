# Online Deployment

The recommended student setup is local-first: SQLite remains the operational database and Supabase PostgreSQL receives a one-way cloud mirror. Local saves work without internet. While a logged-in EasySched page is open, pending changes are uploaded automatically and retried every 15 seconds.

## Setup

1. Create a PostgreSQL database and copy its pooled SSL connection URL.
2. Enable PHP `pdo_pgsql` on the hosting server.
3. Set `EASYSCHED_CLOUD_DATABASE_URL` as a server-side environment variable. Do not set `EASYSCHED_DATABASE_URL` for local-first mode.
4. Run `schema.postgres.sql` once against the empty database.
5. Open the application and sign in. SQLite is seeded locally and the first cloud backup mirrors it to PostgreSQL.
6. Change all demonstration passwords before using real records.

Use the Supabase Session Pooler URL or the Neon connection URL with `sslmode=require`. The cloud project must be dedicated to this EasySched mirror because each successful backup transaction replaces the listed EasySched tables with the authoritative local snapshot. Other unrelated Supabase tables are not touched.

Apache example:

```apache
SetEnv EASYSCHED_CLOUD_DATABASE_URL "postgresql://user:password@host:5432/postgres?sslmode=require"
```

The sidebar reports **Local + cloud backed up**, **Waiting for cloud backup**, or **Cloud retry pending**. Cloud backup runs while EasySched is open and a user is signed in. If the application is closed, pending local data uploads the next time it is opened and authenticated.

## Migration

Do not copy the SQLite file into the web host. Export reviewed master data and schedule history, then import it into PostgreSQL in dependency order using `docs/MIGRATION.md`. Keep the SQLite file as a rollback backup.

## Production checklist

- HTTPS enabled
- `pdo_pgsql` enabled
- Database URL stored only in hosting secrets
- Backups and restore test configured
- Seed accounts rotated or disabled
- PHP error display disabled; logs retained privately
- One online generation worker or queue if multiple schedulers may generate concurrently
