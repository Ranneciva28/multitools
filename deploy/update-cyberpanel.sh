#!/usr/bin/env bash
set -Eeuo pipefail
# Run with sudo/root after initial setup. No timer; updates only when invoked.
DOMAIN=tools.avicennarabama.com
WEBROOT="${1:-}"
[[ $EUID -eq 0 && -n "$WEBROOT" ]] || { echo 'Usage: sudo bash deploy/update-cyberpanel.sh /absolute/path/to/public_html' >&2; exit 1; }
# Resolve the parent only: public_html itself is a symlink after setup.
WEBROOT="$(realpath "$(dirname "$WEBROOT")")/$(basename "$WEBROOT")"
[[ "$WEBROOT" == /home/*/public_html || "$WEBROOT" == /home/*/public_html/* ]] || exit 1
SITE_BASE="/home/$(printf '%s' "$WEBROOT" | cut -d/ -f3)"
REPO="$SITE_BASE/multitools-src"
APP="$SITE_BASE/multitools-app"
[[ -d "$REPO/.git" && -f "$APP/artisan" && -L "$WEBROOT" ]] || { echo 'Initial setup missing or webroot changed.' >&2; exit 1; }
SITE_USER=$(stat -c '%U' "$APP")
PHP_BIN=$(systemctl show threads-queue.service -p ExecStart --value | sed -n 's/.*path=\([^ ;]*\).*/\1/p')
[[ -x "$PHP_BIN" ]] || PHP_BIN=$(command -v php)
PHP_DIR=$(dirname "$PHP_BIN")
run_site() { ( cd "$SITE_BASE" && runuser -u "$SITE_USER" -- env HOME="$SITE_BASE" PATH="$PHP_DIR:$PATH" "$@" ); }
run_site git -C "$REPO" fetch origin main
run_site git -C "$REPO" merge --ff-only origin/main
run_site cp -a "$REPO/overlay/." "$APP/"
run_site cp -a "$REPO/worker/." "$APP/worker/"
run_site "$PHP_BIN" "$(command -v composer)" install --working-dir="$APP" --no-dev --prefer-dist --no-interaction --optimize-autoloader
run_site npm --prefix "$APP/worker" install --omit=dev
run_site bash -c 'cd "$1" && npx playwright install chromium' _ "$APP/worker"
"$APP/worker/node_modules/.bin/playwright" install-deps chromium
run_site "$PHP_BIN" "$APP/artisan" migrate --force
run_site "$PHP_BIN" "$APP/artisan" optimize
NODE_BIN=$(command -v node)
sed -e "s|THREADS_USER|$SITE_USER|g" -e "s|/var/www/threads-tools|$APP|g" \
    -e "s|/usr/bin/node|$NODE_BIN|g" \
    "$REPO/deploy/threads-worker.service" > /etc/systemd/system/threads-worker.service
systemctl daemon-reload
systemctl restart threads-worker.service threads-queue.service threads-schedule.service
curl --fail --silent http://127.0.0.1:3487/health >/dev/null
echo "Updated $DOMAIN from $(run_site git -C "$REPO" rev-parse --short HEAD)"
