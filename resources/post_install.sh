#!/bin/bash

set -e

for adapter in hci0 hci1 hci2; do
    rfkill unblock "$adapter" >/dev/null 2>&1 || true
    hciconfig "$adapter" up >/dev/null 2>&1 || true
done

usermod -aG bluetooth www-data || true

exit 0