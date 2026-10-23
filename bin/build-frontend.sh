#!/usr/bin/env bash
# Builds the React shell (frontend/shopping.shell.react + frontend/remote.bridge.react)
# into work/frontend/shopping.shell.react, where the server serves it from.
# Development with automatic rebuilds: (cd frontend/shopping.shell.react && npm run watch)
set -euo pipefail
exec "$(cd "$(dirname "$0")/.." && pwd)/frontend/shopping.shell.react/build.sh"
