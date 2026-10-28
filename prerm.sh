#!/bin/bash
# Pre-removal script for Panasonic TV Plugin

echo "<INFO> Stopping Panasonic TV Daemon..."
pkill -f "panasonictv_daemon.py" 2>/dev/null || true

exit 0
