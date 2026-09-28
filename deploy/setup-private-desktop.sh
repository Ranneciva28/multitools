#!/usr/bin/env bash
set -Eeuo pipefail
# A private X11 desktop and noVNC endpoint for manual Threads sign-in.
# noVNC and VNC bind only to loopback; access noVNC through an SSH tunnel.
APP=/home/tools.avicennarabama.com/multitools-app
[[ $EUID -eq 0 && -f "$APP/artisan" ]] || { echo 'Run as root on the deployed tools VPS.' >&2; exit 1; }
SITE_USER=$(stat -c '%U' "$APP")
SITE_GROUP=$(id -gn "$SITE_USER")
[[ "$SITE_USER" != root ]] || { echo 'Invalid site owner.' >&2; exit 1; }
if [[ -S /tmp/.X11-unix/X1 ]] && ! systemctl is-active --quiet threads-desktop.service; then
  echo 'DISPLAY=:1 is already in use by an unrelated X server; stop to protect it.' >&2
  exit 1
fi
export DEBIAN_FRONTEND=noninteractive
apt-get update
apt-get install -y --no-install-recommends xvfb xauth x11vnc novnc websockify
[[ -f /usr/share/novnc/vnc.html ]] || { echo 'noVNC web assets missing.' >&2; exit 1; }
install -d -m 0755 /etc/threads-tools
AUTH=/etc/threads-tools/Xauthority
VNC_PASSFILE=/etc/threads-tools/vnc.pass
if [[ ! -f "$AUTH" ]]; then
  install -m 0600 -o "$SITE_USER" -g "$SITE_GROUP" /dev/null "$AUTH"
  # xauth creates lock files beside Xauthority, so add the cookie as root.
  xauth -f "$AUTH" add :1 MIT-MAGIC-COOKIE-1 "$(openssl rand -hex 16)"
  chown "$SITE_USER:$SITE_GROUP" "$AUTH"
fi
if [[ ! -f "$VNC_PASSFILE" ]]; then
  [[ -t 0 ]] || { echo 'Interactive SSH terminal required for initial VNC password.' >&2; exit 1; }
  read -r -s -p 'Choose 8-character VNC password (letters/numbers): ' VNC_PASSWORD
  echo
  [[ "$VNC_PASSWORD" =~ ^[A-Za-z0-9]{8}$ ]] || { echo 'Password must be exactly 8 letters/numbers.' >&2; exit 1; }
  x11vnc -storepasswd "$VNC_PASSWORD" "$VNC_PASSFILE" >/dev/null 2>&1
  unset VNC_PASSWORD
  chown "$SITE_USER:$SITE_GROUP" "$VNC_PASSFILE"
  chmod 0600 "$VNC_PASSFILE"
fi
cat > /etc/systemd/system/threads-desktop.service <<UNIT
[Unit]
Description=Private X11 desktop for Threads login
After=network.target
[Service]
Type=simple
User=$SITE_USER
Environment=XAUTHORITY=$AUTH
ExecStart=/usr/bin/Xvfb :1 -screen 0 1280x900x24 -nolisten tcp -auth $AUTH
Restart=on-failure
RestartSec=3
[Install]
WantedBy=multi-user.target
UNIT
cat > /etc/systemd/system/threads-vnc.service <<UNIT
[Unit]
Description=Loopback VNC for Threads desktop
Requires=threads-desktop.service
After=threads-desktop.service
[Service]
Type=simple
User=$SITE_USER
Environment=XAUTHORITY=$AUTH
ExecStart=/usr/bin/x11vnc -display :1 -auth $AUTH -localhost -rfbauth $VNC_PASSFILE -rfbport 5901 -forever -shared -noxdamage
Restart=on-failure
RestartSec=3
[Install]
WantedBy=multi-user.target
UNIT
cat > /etc/systemd/system/threads-novnc.service <<'UNIT'
[Unit]
Description=Loopback noVNC for Threads desktop
Requires=threads-vnc.service
After=threads-vnc.service
[Service]
Type=simple
ExecStart=/usr/bin/websockify --web=/usr/share/novnc 127.0.0.1:6080 127.0.0.1:5901
Restart=on-failure
RestartSec=3
[Install]
WantedBy=multi-user.target
UNIT
install -d -m 0755 /etc/systemd/system/threads-worker.service.d
cat > /etc/systemd/system/threads-worker.service.d/desktop.conf <<UNIT
[Unit]
Requires=threads-desktop.service
After=threads-desktop.service
[Service]
Environment=XAUTHORITY=$AUTH
UNIT
systemctl daemon-reload
systemctl enable --now threads-desktop.service threads-vnc.service threads-novnc.service
systemctl restart threads-worker.service
for attempt in {1..10}; do
  if [[ -S /tmp/.X11-unix/X1 ]] && curl --fail --silent http://127.0.0.1:6080/vnc.html >/dev/null; then break; fi
  sleep 1
done
[[ -S /tmp/.X11-unix/X1 ]] || { echo 'Xvfb failed. Check journalctl -u threads-desktop -n 60.' >&2; exit 1; }
curl --fail --silent --output /dev/null http://127.0.0.1:6080/vnc.html
echo 'Private desktop ready. Tunnel from your Windows PC: ssh -N -L 6080:127.0.0.1:6080 root@43.156.103.87'
echo 'Then open http://127.0.0.1:6080/vnc.html in your PC browser and enter the VNC password.'
