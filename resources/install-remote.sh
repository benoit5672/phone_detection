#!/usr/bin/env bash

set -Eeuo pipefail

readonly SCRIPT_NAME="$(basename "$0")"

VENV_DIR="${VENV_DIR:-/opt/phone_detection/venv}"
PYTHON_BIN="${PYTHON_BIN:-python3}"
APT_UPDATE=1

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
  --venv DIR         Python virtual-environment directory
                     Default: $VENV_DIR
  --no-upgrade       Do not run apt-get update
  --no-apt-update    Alias for --no-upgrade
  --help, -h         Show this help

Environment variables:
  VENV_DIR           Override the virtual-environment directory
  PYTHON_BIN         Override the Python 3 executable used to create the venv

Examples:
  sudo ./$SCRIPT_NAME
  sudo ./$SCRIPT_NAME --venv /opt/phone_detection/venv
  sudo VENV_DIR=/srv/phone_detection/venv ./$SCRIPT_NAME
EOF
}

on_error() {
    local exit_code=$?
    error "Installation failed at line ${BASH_LINENO[0]} while running: ${BASH_COMMAND}"
    exit "$exit_code"
}

trap on_error ERR

while [[ $# -gt 0 ]]; do
    case "$1" in
        --venv)
            [[ $# -ge 2 ]] || die "Missing argument for --venv"
            VENV_DIR="$2"
            shift 2
            ;;
        --no-upgrade|--no-apt-update)
            APT_UPDATE=0
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

if [[ EUID == 0 ]]; then
    log "Script is already running as root"

    INSTALL_USER="${SUDO_USER:-root}"
    INSTALL_GROUP="$(id -gn "$INSTALL_USER")"
else
    set +e
    sudo -n true
    set -e
    if [[ $? == 0 ]]; then
        log "Passwordless sudo is available for user: $(id -un)"

        INSTALL_USER="$(id -un)"
        INSTALL_GROUP="$(id -gn)"
    else
        die "Passwordless sudo is required for user: $(id -un)"
    fi
fi

readonly INSTALL_USER
readonly INSTALL_GROUP

log "Application files will be owned by: ${INSTALL_USER}:${INSTALL_GROUP}"



[[ -r /etc/os-release ]] || die "/etc/os-release not found."

# shellcheck disable=SC1091
source /etc/os-release

[[ "${ID:-}" == "debian" ]] || die "This script supports Debian only."

if [[ -n "${VERSION_ID:-}" ]]; then
    debian_major="${VERSION_ID%%.*}"

    if [[ "$debian_major" =~ ^[0-9]+$ ]] && (( debian_major < 11 )); then
        die "Debian ${VERSION_ID} is not supported. Debian 11 or newer is required."
    fi
fi

command -v apt-get >/dev/null 2>&1 || die "apt-get was not found."
command -v "$PYTHON_BIN" >/dev/null 2>&1 || die "Python executable not found: $PYTHON_BIN"

export DEBIAN_FRONTEND=noninteractive

if [[ "$APT_UPDATE" -eq 1 ]]; then
    log "Updating APT package indexes"
    sudo apt-get update
fi

log "Installing system dependencies"

sudo apt-get install -y \
    --no-install-recommends \
    bluetooth \
    bluez \
    rfkill \
    iproute2 \
    python3 \
    python3-venv \
    ca-certificates

log "Checking Python version"

python_version="$("$PYTHON_BIN" -c 'import sys; print(".".join(map(str, sys.version_info[:3])))')"
log "Using ${PYTHON_BIN} ${python_version}"


log "Creating virtual-environment parent directory"

sudo install -d \
    -o "$INSTALL_USER" \
    -g "$INSTALL_GROUP" \
    -m 0755 \
    "$(dirname "$VENV_DIR")"

if [[ ! -x "${VENV_DIR}/bin/python3" ]]; then
    log "Creating Python virtual environment: ${VENV_DIR}"
    "$PYTHON_BIN" -m venv "$VENV_DIR"
else
    log "Using existing Python virtual environment: ${VENV_DIR}"
fi

readonly VENV_PYTHON="${VENV_DIR}/bin/python3"

[[ -x "$VENV_PYTHON" ]] || die "Virtual-environment Python was not created: $VENV_PYTHON"

log "Updating virtual-environment packaging tools"

sudo "$VENV_PYTHON" -m pip install \
    --disable-pip-version-check \
    --upgrade \
    pip \
    setuptools \
    wheel

log "Installing Python dependencies into the virtual environment"

sudo "$VENV_PYTHON" -m pip install \
    --disable-pip-version-check \
    --upgrade \
    requests \
    paho-mqtt

log "Checking installed Python packages"

"$VENV_PYTHON" - <<'PYTHON'
from importlib.metadata import version

import paho.mqtt
import requests

print(f"Python: {__import__('sys').version.split()[0]}")
print(f"requests: {version('requests')}")
print(f"paho-mqtt: {version('paho-mqtt')}")
PYTHON

log "Unblocking Bluetooth adapters"

if ! sudo rfkill unblock bluetooth; then
    log "No Bluetooth rfkill entry was found or it could not be unblocked"
fi

log "Bringing detected Bluetooth adapters up"

shopt -s nullglob
adapters=(/sys/class/bluetooth/hci*)
shopt -u nullglob

if (( ${#adapters[@]} == 0 )); then
    log "No Bluetooth adapters were detected"
else
    for adapter_path in "${adapters[@]}"; do
        adapter="$(basename "$adapter_path")"

        if sudo hciconfig "$adapter" up >/dev/null 2>&1; then
            log "Adapter ${adapter} is up"
        else
            log "Could not bring ${adapter} up; it may be unavailable, blocked, or managed by another service"
        fi
    done
fi

log "Installation completed successfully"
log "Use this Python interpreter:"
log "  ${VENV_PYTHON}"