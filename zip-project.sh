#!/usr/bin/env bash
set -euo pipefail

# Archives the whole project (source + docs) without generated/vendor directories.

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PARENT_DIR="$(dirname "$PROJECT_ROOT")"
TIMESTAMP="$(date +%Y%m%d_%H%M)"
ARCHIVE_PATH="$PARENT_DIR/photo_${TIMESTAMP}.zip"

cd "$PROJECT_ROOT"

zip -r "$ARCHIVE_PATH" . \
  -x "storage/*" \
  -x "storage/**" \
  -x "bootstrap/cache/*" \
  -x "bootstrap/cache/**" \
  -x "vendor/*" \
  -x "vendor/**" \
  -x "node_modules/*" \
  -x "node_modules/**" \
  -x "public/build/*" \
  -x "public/build/**" \
  -x ".git/*" \
  -x ".git/**"

echo "$ARCHIVE_PATH"
