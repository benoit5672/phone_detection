#!/usr/bin/env bash

set -Eeuo pipefail

readonly SCRIPT_NAME="$(basename "$0")"
readonly VENV_DIR="${VENV_DIR:-/opt/phone_detection/venv}"
readonly PYTHON_BIN="${PYTHON_BIN:-python3}"

log() {
    printf '[%s] %s\n' "$SCRIPT_NAME" "$*"
}

error() {
    printf '[%s] ERROR: %s\n' "$SCRIPT_NAME" "$*" >&2
}

die() {
    error "$*"
    exit 1
}

usage() {
    cat <<EOF
Usage:
  sudo $SCRIPT_NAME [options]

Options:
  --venv DIR       Python virtual environment directory
                   Default: $VENV_DIR
  --no-upgrade     Do not run apt-get update
  --help           Show this help

Environment variables:
  VENV_DIR         Override the virtual environment directory
  PYTHON_BIN       Override the Python executable
EOF
}

APT_UPDATED=1

while [[ $# -gt 0 ]]; do
    case "$1" in
        --venv)
            [[ $# -ge 2 ]] || die "Missing argument for --venv"
            VENV_DIR="$2"
            shift 2
            ;;
        --no-upgrade)
            APT_UPDATED=0
            shift
            ;;
        --help|-h)
            usage
            exit 0
            ;;
        *)
            die "Unknown option: $1"
            ;;
    esac
done

[[ "${EUID}" -eq 0 ]] || die "Run this script as root or with sudo."

[[ -r /etc/os-release ]] || die "/etc/os-release not found"
# shellcheck disable=SC1091
source /etc/os-release

[[ "${ID:-}" == "debian" ]] || die "This script supports Debian only."

export DEBIAN_FRONTEND=noninteractive

trap 'error "Installation failed at line $LINENO: $BASH_COMMAND"' ERR

if [[ "$APT_UPDATED" -eq 1 ]]; then
    log "Updating APT package indexes"
    apt-get update
fi

log "Installing system dependencies"

apt-get install -y --no-install-recommends \
    bluetooth \
    bluez \
    rfkill \
    python3 \
    python3-pip \
    python3-venv \
    ca-certificates

log "Creating Python virtual environment: ${VENV_DIR}"

install -d -m 0755 "$(dirname "$VENV_DIR")"

if [[ ! -x "${VENV_DIR}/bin/python" ]]; then
    "${PYTHON_BIN}" -m venv "${VENV_DIR}"
fi

"${VENV_DIR}/bin/python" -m pip install \
    --disable-pip-version-check \
    --upgrade \
    pip setuptools wheel

"${VENV_DIR}/bin/python3" -m pip install \
    --disable-pip-version-check \
    requests \
    paho-mqtt

log "Checking Python packages"

"${VENV_DIR}/bin/python3" - <<'PY'
import requests
import paho.mqtt

print(f"requests: {requests.__version__}")
print(f"paho-mqtt: {paho.mqtt.__version__}")
PY	

log "Unblocking Bluetooth adapters"

# Prefer the Bluetooth type, then fall back to the numeric indexes used
# by the original script.
if ! rfkill unblock bluetooth >/dev/null 2>&1; then
    for index in 0 1 2; do
        rfkill unblock "$index" >/dev/null 2>&1 || true
    done
fi

log "Bringing Bluetooth adapters up"

for adapter in hci0 hci1 hci2; do
    if command -v hciconfig >/dev/null 2>&1; then
        hciconfig "$adapter" up >/dev/null 2>&1 || true
    elif command -v btmgmt >/dev/null 2>&1; then
        adapter_index="${adapter#hci}"
        btmgmt --index "$adapter_index" power on >/dev/null 2>&1 || true
    fi
done

log "Adding www-data to the bluetooth group"

if getent passwd www-data >/dev/null 2>&1; then
    usermod -aG bluetooth www-data
else
    log "User www-data does not exist; group membership was skipped"
fi

log "Installation completed successfully"
log "Use this Python interpreter:"
log "  ${VENV_DIR}/bin/python3"