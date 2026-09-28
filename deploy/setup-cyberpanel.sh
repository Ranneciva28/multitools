#!/usr/bin/env bash
set -Eeuo pipefail
# Run as root: bash deploy/setup-cyberpanel.sh /absolute/path/to/domain/public_html
DOMAIN=tools.avicennarabama.com
REPO_URL=https://github.com/Ranneciva28/multitools.git
WEBROOT="${1:-}"
[[ $EUID -eq 0 ]] || { echo 'Run with sudo/root.' >&2; exit 1; }
[[ -d "$WEBROOT" ]] || { echo 'Pass the existing CyberPanel public_html directory as argument.' >&2; exit 1; }
WEBROOT=$(realpath "$WEBROOT")
[[ "$WEBROOT" == /home/*/public_html || "$WEBROOT" == /home/*/public_html/* ]] || { echo "Unexpected webroot: $WEBROOT" >&2; exit 1; }
SITE_BASE="/home/$(printf '%s' "$WEBROOT" | cut -d/ -f3)"
SITE_USER=$(stat -c '%U' "$WEBROOT")
[[ "$SITE_USER" != root && "$SITE_USER" != UNKNOWN ]] || { echo 'Cannot determine CyberPanel site owner.' >&2; exit 1; }
[[ -d "$SITE_BASE" && ! -L "$WEBROOT" ]] || { echo 'Webroot already linked or site base missing; stop.' >&2; exit 1; }
APP="$SITE_BASE/multitools-app"
REPO="$SITE_BASE/multitools-src"
RESUME=0
if [[ -e "$APP" ]]; then
  [[ -f "$APP/artisan" && -f "$APP/.env" && -d "$APP/vendor" ]] || { echo "Incomplete or unrelated app directory: $APP. Stop to protect it." >&2; exit 1; }
  RESUME=1
fi
for command in git composer node npm mariadb python3 systemctl runuser openssl curl; do command -v "$command" >/dev/null || { echo "Missing command: $command" >&2; exit 1; }; done
PHP_BIN=''
for candidate in "$(command -v php || true)" /usr/local/lsws/lsphp84/bin/php; do
  if [[ -x "$candidate" ]] && "$candidate" -r 'exit(PHP_VERSION_ID >= 80401 ? 0 : 1);'; then PHP_BIN="$candidate"; break; fi
done
[[ -n "$PHP_BIN" ]] || { echo 'PHP 8.4.1+ CLI not found.' >&2; exit 1; }
PHP_DIR=$(dirname "$PHP_BIN")
run_site() { ( cd "$SITE_BASE" && runuser -u "$SITE_USER" -- env HOME="$SITE_BASE" PATH="$PHP_DIR:$PATH" "$@" ); }
DB_NAME=multitools_db
DB_USER=multitools_app
if [[ "$RESUME" == 0 ]]; then
  if mariadb -NBe "SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME='$DB_NAME'" | grep -Fxq "$DB_NAME"; then
    echo "Database $DB_NAME already exists. Refusing to change it." >&2; exit 1
  fi
  if mariadb -NBe "SELECT User FROM mysql.user WHERE User='$DB_USER'" | grep -Fxq "$DB_USER"; then
    echo "DB user $DB_USER already exists. Refusing to change it." >&2; exit 1
  fi
fi
# Clone into a private location outside any public_html.
if [[ -e "$REPO" ]]; then
  [[ -d "$REPO/.git" ]] || { echo "Existing non-Git path: $REPO" >&2; exit 1; }
  [[ "$(run_site git -C "$REPO" remote get-url origin)" == "$REPO_URL" ]] || { echo 'Unexpected Git origin; stop.' >&2; exit 1; }
  run_site git -C "$REPO" fetch origin main
  run_site git -C "$REPO" merge --ff-only origin/main
else
  run_site git clone --depth 1 "$REPO_URL" "$REPO"
fi
if [[ "$RESUME" == 0 ]]; then
  run_site env THREADS_PHP_BIN="$PHP_BIN" bash "$REPO/install.sh" "$APP"
  DB_PASS=$(openssl rand -hex 24)
  WORKER_SECRET=$(openssl rand -hex 32)
  ORDER_SECRET=$(openssl rand -hex 32)
  mariadb <<SQL
CREATE DATABASE \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
SQL
  export APP DB_NAME DB_USER DB_PASS WORKER_SECRET ORDER_SECRET DOMAIN
  python3 - <<'PY'
from pathlib import Path
import os
p=Path(os.environ['APP'])/'.env'
lines=p.read_text().splitlines()
values={
 'APP_NAME':'"Threads Tools"','APP_ENV':'production','APP_DEBUG':'false','APP_URL':'https://'+os.environ['DOMAIN'],
 'APP_TIMEZONE':'Asia/Jakarta','DB_CONNECTION':'mysql','DB_HOST':'localhost','DB_PORT':'3306',
 'DB_DATABASE':os.environ['DB_NAME'],'DB_USERNAME':os.environ['DB_USER'],'DB_PASSWORD':os.environ['DB_PASS'],
 'QUEUE_CONNECTION':'database','CACHE_STORE':'database','SESSION_DRIVER':'database',
 'THREADS_WORKER_URL':'http://127.0.0.1:3487','THREADS_WORKER_SECRET':os.environ['WORKER_SECRET'],
 'ORDERS_WEBHOOK_SECRET':os.environ['ORDER_SECRET'],'TELEGRAM_BOT_TOKEN':''
}
output=[]
for line in lines:
 key=line.split('=',1)[0]
 if key in values:
  output.append(key+'='+values.pop(key))
 else: output.append(line)
output.extend(k+'='+v for k,v in values.items())
p.write_text('\n'.join(output)+'\n')
p.chmod(0o600)
PY
  run_site "$PHP_BIN" "$APP/artisan" key:generate --force
else
  # The initial run already created credentials. Never rotate them during resume.
  ENV_DB=$(python3 - "$APP/.env" <<'PYENV'
import sys
for line in open(sys.argv[1]):
    if line.startswith('DB_DATABASE='):
        print(line.split('=', 1)[1].strip()); break
PYENV
)
  [[ "$ENV_DB" == "$DB_NAME" ]] || { echo 'Existing .env points to a different database.' >&2; exit 1; }
  mariadb -NBe "SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME='$DB_NAME'" | grep -Fxq "$DB_NAME" || { echo 'Expected database is missing.' >&2; exit 1; }
  WORKER_SECRET=$(python3 - "$APP/.env" <<'PYENV'
import sys
for line in open(sys.argv[1]):
    if line.startswith('THREADS_WORKER_SECRET='):
        print(line.split('=', 1)[1].strip()); break
PYENV
)
  [[ "$WORKER_SECRET" =~ ^[0-9a-f]{64}$ ]] || { echo 'Existing worker secret is invalid.' >&2; exit 1; }
  run_site cp -a "$REPO/overlay/." "$APP/"
  run_site cp -a "$REPO/worker/." "$APP/worker/"
fi
run_site "$PHP_BIN" "$APP/artisan" migrate --force
run_site "$PHP_BIN" "$APP/artisan" optimize
run_site "$PHP_BIN" "$APP/artisan" route:list --path=login >/dev/null
# Service definitions live outside the public document root.
mkdir -p /etc/threads-tools
WORKER_ENV=/etc/threads-tools/worker.env
cat > "$WORKER_ENV" <<ENV
THREADS_WORKER_SECRET=$WORKER_SECRET
THREADS_PROFILE_ROOT=$APP/storage/browser-profiles/threads
THREADS_MEDIA_ROOT=$APP/storage/app/private/threads-media
THREADS_CONCURRENCY=1
THREADS_WORKER_PORT=3487
DISPLAY=:1
ENV
chmod 600 "$WORKER_ENV"
NODE_BIN=$(command -v node)
for unit in threads-worker threads-queue threads-schedule; do
  sed -e "s|THREADS_USER|$SITE_USER|g" -e "s|/var/www/threads-tools|$APP|g" \
      -e "s|/usr/bin/php|$PHP_BIN|g" -e "s|/usr/bin/node|$NODE_BIN|g" \
      "$REPO/deploy/$unit.service" > "/etc/systemd/system/$unit.service"
done
systemctl daemon-reload
systemctl enable --now threads-worker.service threads-queue.service threads-schedule.service
curl --fail --silent http://127.0.0.1:3487/health >/dev/null
# Only switch the webroot after CLI and worker health pass. Preserve old content.
BACKUP="${WEBROOT}.backup.$(date +%Y%m%d-%H%M%S)"
mv "$WEBROOT" "$BACKUP"
ln -s "$APP/public" "$WEBROOT"
# OpenLiteSpeed serves CyberPanel's existing vhost; reload is enough for the new symlink.
if [[ -x /usr/local/lsws/bin/lswsctrl ]]; then /usr/local/lsws/bin/lswsctrl restart; fi
echo "DEPLOYED: https://$DOMAIN"
echo "Source: $REPO | Laravel: $APP | Webroot backup: $BACKUP"
echo "Run: cd '$APP' && sudo -u '$SITE_USER' '$PHP_BIN' artisan threads:make-admin"
echo "Set TELEGRAM_BOT_TOKEN in $APP/.env after creating a bot, then run: sudo -u '$SITE_USER' '$PHP_BIN' '$APP/artisan' optimize"
echo 'Threads login also needs a secured X11/VNC desktop on DISPLAY=:1; no VNC port was opened.'
