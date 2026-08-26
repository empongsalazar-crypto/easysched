# HTTPS Setup

EasySched keeps plain HTTP available for local development. Production HTTPS enforcement is opt-in so a localhost defense installation is not broken by a missing certificate.

## Free XAMPP localhost HTTPS

1. In `C:\xampp\apache\conf\httpd.conf`, confirm these lines are enabled (no leading `#`):

```apache
LoadModule ssl_module modules/mod_ssl.so
Include conf/extra/httpd-ssl.conf
```

2. Restart Apache and test:

```text
https://localhost/EasySched-Defense/
```

XAMPP's bundled development certificate may show a browser warning because it is not publicly trusted. This is acceptable only for local development and defense testing.

3. After the HTTPS URL works, add this to `httpd.conf`:

```apache
SetEnv EASYSCHED_FORCE_HTTPS "1"
```

4. Restart Apache. Opening the HTTP URL should now redirect to HTTPS.

## Reverse-proxy hosting

When Render, Railway, Cloudflare, Nginx, or another trusted reverse proxy terminates HTTPS, configure both variables on the PHP server:

```text
EASYSCHED_FORCE_HTTPS=1
EASYSCHED_TRUST_PROXY=1
```

Only enable `EASYSCHED_TRUST_PROXY` when requests can reach PHP exclusively through a trusted proxy that replaces `X-Forwarded-Proto`. Do not enable it on a directly exposed server where clients can supply that header themselves.

## Result

On verified HTTPS connections EasySched automatically uses:

- Secure, HttpOnly, SameSite session cookies
- Strict session mode and cookie-only session identifiers
- HTTP Strict Transport Security
- Content Security Policy with insecure-request upgrading
- Cross-origin opener and resource protection
- No-cache protection for authenticated responses
