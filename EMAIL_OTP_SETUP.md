# Email OTP Setup

EasySched uses Resend to deliver registration-verification and password-reset codes.

1. Create a Resend account and verify the sending domain or address.
2. Create an API key with permission to send email.
3. In Render, add these server-side environment variables:

```text
RESEND_API_KEY=re_...
EASYSCHED_EMAIL_FROM=EasySched <no-reply@your-verified-domain.example>
```

4. Run the updated `schema.postgres.sql` in Supabase. It creates the `email_otps` table without deleting existing records.
5. Redeploy Render.

OTP codes are six digits, expire after ten minutes, allow five attempts, and can be requested only once per minute. Only a hash of each code is stored.

The registration request is created only after the email code is verified. Password reset uses the same verification flow and does not reveal whether an account exists.
