#!/usr/bin/env bash
set -euo pipefail
for u in mad4b-private-mobile-css-family-wayfa mad4b-frontend-contract-wayfa mad4b-presentation-browser-audit mad4b-tour-story-browser-monitor; do
 rm -f "/etc/systemd/system/$u.service.d/50-shared-browser-qa-slot.conf"
done
systemctl daemon-reload
echo 'Original unit ExecStart restored; current site services untouched'
