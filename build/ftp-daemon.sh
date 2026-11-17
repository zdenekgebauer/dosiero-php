#!/bin/sh
# FTP daemon (ProFTPD) for the FtpStorage tests, shared by Lando and CI.
#   ftp-daemon.sh install   apt packages, as root, once per image
#   ftp-daemon.sh start     writes the config into $RUN_DIR and (re)starts the daemon, as root
# The virtual user maps to the owner of the project directory, so the tests running as that user
# can prepare and clean the fixtures through the filesystem. pure-ftpd was tried first: under
# Docker it dies switching capabilities and has no log outside syslog.
set -eu

PROJECT_DIR=$(cd "$(dirname "$0")/.." && pwd)
FTP_ROOT="$PROJECT_DIR/tests/Support/Data/ftp"
FTP_USER="${DOSIERO_FTP_LOGIN:-dosiero}"
FTP_PASSWORD="${DOSIERO_FTP_PASSWORD:-dosiero}"
FTP_PORT="${DOSIERO_FTP_PORT:-2121}"
RUN_DIR="${DOSIERO_FTP_RUN_DIR:-/tmp/dosiero-ftpd}"

install() {
    export DEBIAN_FRONTEND=noninteractive
    apt-get update -qq
    apt-get install -y -qq proftpd-core proftpd-mod-crypto openssl >/dev/null
}

start() {
    uid=$(stat -c %u "$PROJECT_DIR")
    gid=$(stat -c %g "$PROJECT_DIR")
    owner=$(stat -c %U "$PROJECT_DIR")
    group=$(stat -c %G "$PROJECT_DIR")

    if [ -f "$RUN_DIR/proftpd.pid" ]; then
        kill "$(cat "$RUN_DIR/proftpd.pid")" 2>/dev/null || true
        sleep 1
    fi
    mkdir -p "$RUN_DIR" "$FTP_ROOT"
    chown "$uid:$gid" "$FTP_ROOT"

    if [ ! -f "$RUN_DIR/cert.pem" ]; then
        openssl req -x509 -nodes -newkey rsa:2048 -days 3650 -subj /CN=localhost \
            -keyout "$RUN_DIR/key.pem" -out "$RUN_DIR/cert.pem" 2>/dev/null
    fi
    chmod 600 "$RUN_DIR/key.pem"

    hash=$(openssl passwd -6 "$FTP_PASSWORD")
    echo "$FTP_USER:$hash:$uid:$gid::$FTP_ROOT:/bin/false" > "$RUN_DIR/passwd"
    echo "$group:x:$gid:$FTP_USER" > "$RUN_DIR/group"
    chmod 600 "$RUN_DIR/passwd" "$RUN_DIR/group"

    cat > "$RUN_DIR/proftpd.conf" <<EOF
ModulePath /usr/lib/proftpd
LoadModule mod_ls.c
LoadModule mod_xfer.c
LoadModule mod_tls.c
ServerType standalone
DefaultServer on
ServerName "dosiero-test"
DefaultAddress 127.0.0.1
Port $FTP_PORT
UseIPv6 off
UseReverseDNS off
User $owner
Group $group
PidFile $RUN_DIR/proftpd.pid
ScoreboardFile $RUN_DIR/proftpd.scoreboard
SystemLog $RUN_DIR/proftpd.log
TransferLog none
WtmpLog off
AuthOrder mod_auth_file.c
AuthUserFile $RUN_DIR/passwd
AuthGroupFile $RUN_DIR/group
RequireValidShell off
DefaultRoot ~
Umask 022
AllowOverwrite on
PassivePorts 10000 32000
ListOptions "-a"
<IfModule mod_cap.c>
    CapabilitiesEngine off
</IfModule>
<IfModule mod_tls.c>
    TLSEngine on
    TLSRequired off
    TLSRSACertificateFile $RUN_DIR/cert.pem
    TLSRSACertificateKeyFile $RUN_DIR/key.pem
    TLSProtocol TLSv1.2 TLSv1.3
    # php's ftp_ssl_connect() does not reuse the control session on the data channel
    TLSOptions NoSessionReuseRequired
</IfModule>
EOF

    proftpd -c "$RUN_DIR/proftpd.conf"
}

case "${1:-}" in
    install) install ;;
    start) start ;;
    *) echo "usage: $0 install|start" >&2; exit 2 ;;
esac
