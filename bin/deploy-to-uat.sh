#!/bin/bash
# Deploy peo-fegyvertar plugin to UAT server via rsync over SSH.
#
# Usage:
#   bash bin/deploy-to-uat.sh user@uat-server.example.com /path/to/wp-content/plugins/peo-fegyvertar
#
# Example:
#   bash bin/deploy-to-uat.sh deploy@192.168.1.50 /var/www/html/wp-content/plugins/peo-fegyvertar
#
# What it excludes:
#   - test/                        (test scripts, reset tools)
#   - includes/endpoints/backup/   (deprecated endpoint backups)
#   - integrations/stripe/secrets.php (legacy hardcoded key file)
#   - log/*.log                    (local dev logs)
#   - .DS_Store, .git
#
# Source of truth is the repo. This script pushes one-way.

set -euo pipefail

if [ $# -lt 2 ]; then
    echo "Usage: $0 <ssh-target> <remote-plugin-path>"
    echo "  e.g. $0 deploy@uat.example.com /var/www/html/wp-content/plugins/peo-fegyvertar"
    exit 1
fi

SSH_TARGET="$1"
REMOTE_PATH="$2"
REPO_DIR="$(cd "$(dirname "$0")/.." && pwd)"
PLUGIN_DIR="$REPO_DIR/peo-fegyvertar"

if [ ! -f "$PLUGIN_DIR/peo-fegyvertar.php" ]; then
    echo "ERROR: Plugin not found at $PLUGIN_DIR"
    exit 1
fi

echo "Deploying peo-fegyvertar → $SSH_TARGET:$REMOTE_PATH"
echo ""

rsync -avz --delete \
    --exclude='.DS_Store' \
    --exclude='.git/' \
    --exclude='test/' \
    --exclude='includes/endpoints/backup/' \
    --exclude='integrations/stripe/secrets.php' \
    --exclude='log/*.log' \
    "$PLUGIN_DIR/" "$SSH_TARGET:$REMOTE_PATH/"

echo ""
echo "Done. Deployed to $SSH_TARGET:$REMOTE_PATH"
echo ""
echo "Post-deploy checklist:"
echo "  1. Set env vars: PEOFT_DB_HOST, PEOFT_DB_USER, PEOFT_DB_PASS, PEOFT_DB_NAME"
echo "  2. Verify PEOFT_CONFIG table has correct UAT keys (Stripe, Circle, Számlázz.hu)"
echo "  3. Insert cors_origin into PEOFT_CONFIG if cross-domain REST needed"
echo "  4. Ensure WP_DEBUG = false in wp-config.php"
