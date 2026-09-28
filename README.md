# Threads Tools — installation package

This package installs into **a new Laravel 12 application**. It is designed for a Linux VPS with PHP 8.2+, Composer, Node.js, MariaDB, a private desktop session (Xvfb/VNC), and a Telegram bot. The package contains no credentials, browser sessions, or customer payment records. It does not replace an existing project: inspect and merge the `overlay/` files if an existing Laravel app is provided later.

## What works in source

- Session based admin login, account records, one persistent Playwright profile per account, manual login launcher and status check.
- Campaigns, one independent draft per selected account, separate captions and image selections, approval, scheduling and queueing.
- A single authenticated local Playwright worker, account locking, concurrency cap, logs and private error screenshots.
- Telegram groups, FAQ/announcement/labeled simulation templates, signed `PAID` order webhook, manual approval, queued transaction messages.

The website **must** send the webhook only after its server verifies a genuine payment. Do not expose its HMAC secret to browsers or mobile clients. No customer impersonation or simulated payment proof is provided.

## Install on a new host

1. Install PHP 8.2+ with extensions required by Laravel, Composer, Node.js, MariaDB, and Chromium libraries. Create a dedicated unprivileged service user. Extract this ZIP on the VPS.
2. Run `./install.sh /var/www/threads-tools` from the extracted package. This downloads a fresh Laravel 12 skeleton and Playwright Chromium, then applies the overlay. It **refuses to overwrite** an existing target.
3. Copy the values in `threads.env.example` into `/var/www/threads-tools/.env`. Configure the DB, APP_URL, APP_KEY (`php artisan key:generate`), and generate *distinct* secrets with `openssl rand -hex 32`. Do not commit `.env`.
4. Create MariaDB database and user. Run `php artisan migrate --force` and `php artisan threads:make-admin`. Point OpenLiteSpeed's document root to `/var/www/threads-tools/public`, enforce HTTPS, deny direct access to `.env` and `storage`.
5. Create `/etc/threads-tools/worker.env` from `deploy/worker.env.example` with mode `0600`. The worker secret must match Laravel's `.env`. Set `THREADS_PROFILE_ROOT` to a directory accessible only by the service user. Ensure worker and PHP share read access to `storage/app/private/threads-media`.
6. Provide a private X11 desktop (`DISPLAY=:1`) through secured VNC or a local console; never publish VNC/noVNC to the open Internet. The **Add Account** button opens a headed browser there for up to 15 minutes. Complete signup, login, OTP, or CAPTCHA manually. Then click **Check Session** in the dashboard.
7. Adjust service user and paths in `deploy/*.service`; copy to `/etc/systemd/system/`, run `systemctl daemon-reload`, then enable/start `threads-worker.service`, `threads-queue.service`, and `threads-schedule.service`. The persistent scheduler does not deploy code. Open only the web server externally; worker binds `127.0.0.1`.
8. Confirm `curl http://127.0.0.1:3487/health` locally, `php artisan schedule:list`, `php artisan queue:monitor database:default` as appropriate, and access `/login`. Test one authorized Threads account and a private Telegram test group before general use.

## Order website integration

`POST /api/orders/paid` receives raw JSON with header `X-Order-Signature: <hex HMAC-SHA256 of raw body using ORDERS_WEBHOOK_SECRET>`:

```json
{"event_id":"payment-123","order_id":"PGI-01831","status":"PAID","product":"MacBook Air M1","amount_idr":6557257,"group_id":1}
```

The order website must verify payment server side before emitting this request; an order ID alone is not payment proof. Requests are idempotent per group/order. Admin reviews in Telegram → Verified Transaction Feed, then approves and sends. The feed contains no customer name, bank account, phone, screenshot, or transfer reference.

## Operational notes and current limits

- **Not deployed or live tested here.** The build environment had no PHP, Composer, repo, server access, or account credentials. Laravel migrations, real browser selectors, and Telegram delivery still need to be tested on the target VPS.
- Threads UI is outside the app's control. Login detection and composer selectors in `worker/server.mjs` must be checked against the actual current site. If publish confirmation is uncertain, worker returns an error; inspect the account before retry to avoid duplicate posts.
- This implementation accepts JPG/PNG/WebP images. Video, drag reorder, bulk caption variants, per-account preview cards, automatic community scheduler, customer submission threads, masking of payment screenshots, and detailed health UI are **not implemented**. Native media multi-select follows the library order.
- Worker screenshots are private files at `THREADS_PROFILE_ROOT/error-*.png` and their paths appear in activity details, visible only after admin login. Keep this directory outside the web root; use SSH to inspect.
- An existing Laravel app needs a migration and route merge; applying the overlay wholesale to it may overwrite its auth or routes.
