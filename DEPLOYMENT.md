# Kata Deployment Notes

This app is intended for private personal use. Keep code in Git; keep real data and secrets out of Git.

## Private Config

Copy `app/config.example.php` to a private location outside the public web root, then point PHP at it with the `KATA_CONFIG_PATH` environment variable when possible.

The config controls:

- `data_dir`: private directory containing JSON files and backups.
- `allowed_ips`: exact IP addresses allowed to access the app. Empty means allow all, useful only for local development.
- `password_hash`: generated with `password_hash()`.
- `secure_cookies`: use `true` on HTTPS production.

## Private Data

Production JSON files should live outside the subdomain document root:

```text
/home/YOUR_CPANEL_USER/private/kata-data/
  finance.json
  goals.json
  three-month-goals.json
  backups/
```

Do not commit JSON files, backup folders, `.env` files, or private config files.

## Search Engines

All pages send `X-Robots-Tag: noindex, nofollow` and include matching robots meta tags. Do not link the private subdomain from WordPress or add it to a sitemap.

## MVP Security Checklist

- Private GitHub repo contains code only.
- Subdomain document root contains PHP app code only.
- JSON data directory is private.
- Config file is private.
- Password is stored as a hash.
- IP allowlist is set for production.
- HTTPS is enabled.
