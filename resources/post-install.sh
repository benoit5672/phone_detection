#!/bin/bash

export PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

set -e

RFKILL=$(command -v rfkill || true)
HCICONFIG=$(command -v hciconfig || true)

if [ -z "$RFKILL" ]; then
    echo "rfkill command not found"
    exit 1
fi

echo "Unblocking Bluetooth adapters"

if ! "$RFKILL" unblock bluetooth >/dev/null 2>&1; then
    echo "No Bluetooth rfkill entry was found or it could not be unblocked"
fi

echo "Bringing detected Bluetooth adapters up"

shopt -s nullglob
adapters=(/sys/class/bluetooth/hci*)
shopt -u nullglob

if (( ${#adapters[@]} == 0 )); then
    echo "No Bluetooth adapters were detected"
else
    if [ -z "$HCICONFIG" ]; then
        echo "hciconfig command not found"
        exit 1
    fi

    for adapter_path in "${adapters[@]}"; do
        adapter="$(basename "$adapter_path")"

        if "$HCICONFIG" "$adapter" up >/dev/null 2>&1; then
            echo "Adapter ${adapter} is up"
        else
            echo "Could not bring ${adapter} up"
        fi
    done
fi

usermod -aG bluetooth www-data 2>/dev/null || true

exit 0