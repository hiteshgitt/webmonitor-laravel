# ruyalab/webmonitor-laravel

Connect a Laravel app to **[Web Monitor](https://webmonitor.ruyalab.com)** — uptime, response time,
performance, SEO and security checks every minute.

## Install

```bash
composer require ruyalab/webmonitor-laravel
php artisan webmonitor:install
```

The package registers `GET /api/webmonitor/{endpoint}` automatically (no route or controller to edit).
`webmonitor:install` saves a new `WEBMONITOR_API_KEY` in `.env` and prints it with your site URL.

Then in the Web Monitor dashboard click **Add site**, choose **Laravel**, and paste the key.

Make sure `APP_URL` is your public `https://` address (or set `WEBMONITOR_SITE_URL`) — it is used for the
SEO and security checks. Run `php artisan webmonitor:install --new` to rotate the key.

## What it reports

All values are measured on your server at request time:

| Endpoint | Data |
|---|---|
| `health` | status, PHP and Laravel versions, memory, load |
| `performance` | memory, CPU load per core, disk space, OPcache |
| `seo` | homepage title, meta description, H1, image alt text, `lang`, canonical, links |
| `security` | HTTPS, supported PHP, `APP_DEBUG` off, `display_errors`/`expose_php`, security headers |
| `packages` | Composer dependencies with installed versions (from `composer.lock`) |
| `logs` | last lines of `storage/logs/laravel.log` |
| `analytics` | `null` — visitor analytics need an analytics provider |

Requests must send `Authorization: Bearer <WEBMONITOR_API_KEY>`. The route uses no session, cache or
database. Supports Laravel 10–13, PHP 8.1+.

MIT License · © Ruyalab
