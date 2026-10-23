# HalkyLauncher Website

The official website and telemetry services for [HalkyLauncher](https://github.com/oleksandr1811/halkylauncher).

**Official website:** [https://halkylauncher.alex1811.ovh/](https://halkylauncher.alex1811.ovh/)

## Project Structure

```text
halky-site/
├── .gitignore                     # Git ignore rules for private configuration
├── index.html                     # Main website page
├── telemetry.html                 # Telemetry information page
├── telemetry.php                  # Launcher telemetry receiver (fixed POST URL)
├── telemetry-api.php              # Public aggregate statistics API (GET)
├── telemetry-view.php             # Public telemetry dashboard
├── telemetry-export.php           # reCAPTCHA-protected telemetry SQL export
├── 403.html / 404.html            # Custom error pages
├── auth-success.html              # Authentication success page
├── feed.xml                       # Atom/RSS news feed
├── assets/                        # Static icons and images
├── config/
│   ├── credentials.php            # Private database/reCAPTCHA config (ignored)
│   └── credentials.template.php   # Safe configuration template
└── includes/
    ├── app-config.php             # Private reCAPTCHA/export configuration loader
    ├── csrf.php                   # Session and CSRF helpers
    ├── database.php               # PDO connection and fail-closed config loader
    ├── rate-limit.php             # Endpoint throttling helper
    ├── recaptcha.php              # Server-side reCAPTCHA verification
    ├── security-headers.php       # Shared HTTP security headers
    └── telemetry-query.php        # Validated filters and SQL predicates
```

## Database and secrets

Copy `config/credentials.template.php` to a private `config/credentials.php`, or provide the database values through environment variables. Never commit or serve the private file. For production, prefer an absolute private config outside the public document root using `HALKY_CREDENTIALS_FILE` and `HALKY_APP_CONFIG_FILE`.

Supported database variables:

- `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`, `DB_CHARSET`
- `RECAPTCHA_SITE_KEY`, `RECAPTCHA_SECRET`, `RECAPTCHA_HOSTNAME`
- `EXPORT_MAX_ROWS`

The reCAPTCHA secret is verified only on the server. Configure a reCAPTCHA v2 Checkbox key for the official hostname `halkylauncher.alex1811.ovh`.

## Telemetry and export

- **Telemetry callback:** `telemetry.php` must remain at the fixed URL `https://halkylauncher.alex1811.ovh/telemetry.php`; launchers depend on this path and response contract.
- The public dashboard is available at `https://halkylauncher.alex1811.ovh/telemetry-view.php`.
- The public dashboard and export contain only anonymous opt-in telemetry from `telemetry_events`: `event_type`, `version`, and `created_at`.
- The SQL export is fixed to this table and these columns. It does not expose credentials, arbitrary tables, or run `mysqldump`.
- Export requests require a CSRF token, server-side reCAPTCHA v2 verification, rate limiting, and a configurable maximum row count.
- The existing `first-run` callback behavior is preserved: it records the first-run event and a corresponding run event.

## Security deployment notes

- Serve the site only over HTTPS.
- Rotate any database password that has ever been exposed or stored on a shared machine.
- Use a least-privilege database account; do not grant the web user permissions for unrelated databases.
- Keep private configuration outside the web root where possible and ensure PHP source files are never downloadable as text.
- The dashboard shows generic errors only; inspect server logs privately when troubleshooting.
- The export and public endpoints are rate-limited. Do not trust `X-Forwarded-For` unless a trusted reverse proxy is explicitly configured.
