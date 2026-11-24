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

## Statement PDF imports

The audit editor uses the vendored Mozilla PDF.js library in `app/vendor/pdfjs/` to read text-based PDFs in the browser. Deploy that folder along with `app/statement-import.mjs`, `app/statement-parser.mjs`, `app/.htaccess`, and the PHP views. Serve `.mjs` files with a JavaScript MIME type (`text/javascript` or `application/javascript`); the included Apache rule sets this when overrides are enabled. Otherwise configure the equivalent MIME mapping in your host. No external API, Python, or server PDF utility is required.

PDFs are not uploaded or retained by the app. Only reviewed transaction fields enter the existing audit save form. Imports require a modern browser and support up to 15 MB / 60 pages per file. Scanned PDFs need OCR first; encrypted PDFs need an unlocked copy. Table extraction is heuristic, uses US month/day dates, and requires review; do not treat it as a reconciled statement. Credit-card credits/payment rows and bank transfers need particular attention to avoid counting transfers as income. The import preview flags possible duplicates but does not silently remove them.

The preview caps additions based on PHP's `max_input_vars` (six fields per item plus form overhead). Increase that setting if large audits exceed the displayed limit; also allow sufficient `post_max_size`. The existing `audit_complete` sentinel rejects truncated saves.

Run `node tests/statement-parser.mjs` and the PHP tests for regression checks.

CSV imports use `app/statement-csv.mjs` (no additional library). Venmo headers are detected after introductory rows, including exports with an empty first column. Quoted commas, quotes, multiline notes, and UTF-8 BOMs are supported. Completed payments use signed `Amount (total)`; bank/balance transfers and incomplete statuses are listed as skipped. Fees are flagged for review rather than added again. Other CSVs must contain Date (or Transaction Date), Description, and Amount columns, with US or ISO dates. The file stays in the browser and follows the same explicit review/save flow. Run `node tests/statement-csv.mjs` for CSV regression checks.

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
