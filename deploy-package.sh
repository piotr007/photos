#!/usr/bin/env bash
set -euo pipefail

# Builds a deployment ZIP (for manual FTP upload) without dev-only files.
# On the server you still need: composer install --no-dev, .env, migrate --force, storage:link, optimize.
# Run `npm run build` first - public/build IS included in the archive.

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PARENT_DIR="$(dirname "$PROJECT_ROOT")"
TIMESTAMP="$(date +%Y%m%d_%H%M)"
ARCHIVE_PATH="$PARENT_DIR/photo_deploy_${TIMESTAMP}.zip"

cd "$PROJECT_ROOT"

if [[ ! -f public/build/manifest.json ]]; then
  echo "public/build/manifest.json missing - run 'npm run build' first." >&2
  exit 1
fi

zip -r "$ARCHIVE_PATH" . \
  -x ".git/*" \
  -x ".git/**" \
  -x ".idea/*" \
  -x ".idea/**" \
  -x ".github/*" \
  -x ".github/**" \
  -x ".env" \
  -x ".env.*" \
  -x "vendor/*" \
  -x "vendor/**" \
  -x "node_modules/*" \
  -x "node_modules/**" \
  -x "storage/*" \
  -x "storage/**" \
  -x "public/storage/*" \
  -x "public/storage/**" \
  -x "bootstrap/cache/*" \
  -x "bootstrap/cache/**" \
  -x "backups/*" \
  -x "backups/**" \
  -x "docker/*" \
  -x "docker/**" \
  -x "docs/*" \
  -x "docs/**" \
  -x "tests/*" \
  -x "tests/**" \
  -x "database/database.sqlite" \
  -x ".phpunit.result.cache" \
  -x "*.log" \
  -x "commands-log.md" \
  -x "plan.md" \
  -x "README.md" \
  -x "AGENTS.md" \
  -x "CLAUDE.md" \
  -x "docker-compose.yml" \
  -x "zip-project.sh" \
  -x "deploy-package.sh"

echo "Prepared deployment archive: $ARCHIVE_PATH"
