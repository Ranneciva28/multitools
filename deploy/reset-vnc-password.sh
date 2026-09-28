#!/usr/bin/env bash
set -Eeuo pipefail
APP=/home/tools.avicennarabama.com/multitools-app
REPO=/home/tools.avicennarabama.com/multitools-src
[[ $EUID -eq 0 && -f "$APP/artisan" && -f "$REPO/deploy/vnc-auth.py" ]] || { echo 'Run as root on the deployed tools VPS after pulling main.' >&2; exit 1; }
SITE_USER=$(stat -c '%U' "$APP")
SITE_GROUP=$(id -gn "$SITE_USER")
[[ "$SITE_USER" != root ]] || { echo 'Invalid site owner.' >&2; exit 1; }
[[ -t 0 ]] || { echo 'Run from an interactive SSH terminal.' >&2; exit 1; }
install -d -m 0755 /usr/local/libexec /etc/threads-tools
install -m 0755 "$REPO/deploy/vnc-auth.py" /usr/local/libexec/threads-vnc-auth
read -r -s -p 'Password VNC baru (bebas karakter/panjang, jangan Enter di tengah): ' VNC_PASSWORD
echo
[[ -n "$VNC_PASSWORD" ]] || { echo 'Password kosong; batal.' >&2; exit 1; }
read -r -s -p 'Ulangi password VNC: ' VNC_CONFIRM
echo
[[ "$VNC_PASSWORD" == "$VNC_CONFIRM" ]] || { echo 'Password berbeda; batal.' >&2; exit 1; }
printf %s "$VNC_PASSWORD" | /usr/local/libexec/threads-vnc-auth set
unset VNC_PASSWORD VNC_CONFIRM
chown "$SITE_USER:$SITE_GROUP" /etc/threads-tools/vnc-auth.json
chmod 0600 /etc/threads-tools/vnc-auth.json
sed "s|THREADS_USER|$SITE_USER|g" "$REPO/deploy/threads-vnc.service" > /etc/systemd/system/threads-vnc.service
systemctl daemon-reload
if systemctl is-active --quiet threads-desktop.service && \
   systemctl is-active --quiet threads-vnc.service && \
   systemctl is-active --quiet threads-novnc.service; then
  systemctl restart threads-vnc.service threads-novnc.service
else
  bash "$REPO/deploy/setup-private-desktop.sh"
fi
echo 'Password VNC diperbarui. Di noVNC klik Connect; pada layar login isi username: owner dan password baru.'
