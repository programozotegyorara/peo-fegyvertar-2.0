#!/bin/bash
# Sync the plugin from the repo working tree to the local XAMPP htdocs install.
#
# Why this exists: XAMPP's Apache runs as `daemon` and can't traverse
# ~/Documents (macOS ACL). Symlinks from htdocs → repo fail at realpath, so
# HTTP requests don't load the plugin. Copying the files into htdocs works
# around it until we pick a permanent fix (move repo out of ~/Documents,
# git worktree inside htdocs, or grant Apache Full Disk Access).
#
# Usage:
#   bash bin/sync-to-htdocs.sh          # rsync repo → htdocs
#   bash bin/sync-to-htdocs.sh --watch  # (future) fswatch loop
#
# Source of truth remains the repo. This script pushes one-way.

set -euo pipefail

REPO_DIR="$(cd "$(dirname "$0")/.." && pwd)"
HTDOCS_PLUGIN="/Applications/XAMPP/xamppfiles/htdocs/fegyvertar2/wp-content/plugins/peo-fegyvertar-v2"

if [ ! -d "$HTDOCS_PLUGIN" ]; then
    mkdir -p "$HTDOCS_PLUGIN"
fi

rsync -a --delete \
    --exclude='.git/' \
    --exclude='.DS_Store' \
    --exclude='tests/Fixtures/tmp/' \
    "$REPO_DIR/" "$HTDOCS_PLUGIN/"

echo "Synced $REPO_DIR → $HTDOCS_PLUGIN"
