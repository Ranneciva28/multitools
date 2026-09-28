#!/usr/bin/env bash
set -euo pipefail
# On a host with PHP 8.2+, Composer and Node. Creates a NEW directory only.
TARGET="${1:-threads-tools-app}"
if [ -e "$TARGET" ]; then echo "Target already exists: $TARGET" >&2; exit 1; fi
command -v composer >/dev/null || { echo 'Composer required' >&2; exit 1; }
command -v node >/dev/null || { echo 'Node required' >&2; exit 1; }
composer create-project laravel/laravel "$TARGET" '^12.0'
cp -R "$(dirname "$0")/overlay/." "$TARGET/"
mkdir -p "$TARGET/worker"
cp -R "$(dirname "$0")/worker/." "$TARGET/worker/"
cp "$(dirname "$0")/env.additions" "$TARGET/threads.env.example"
(cd "$TARGET/worker" && npm install && npx playwright install chromium)
chmod 700 "$TARGET/storage" || true
cat <<EOF
Installed source in $TARGET. Configure .env (see threads.env.example), run php artisan key:generate,
php artisan migrate --force, php artisan threads:make-admin, then configure scheduler/queue/worker services.
No production service was started by this script.
EOF
