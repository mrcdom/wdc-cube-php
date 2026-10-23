#!/usr/bin/env bash
# Builds the React shell (frontend/store.shell.react + frontend/remote.bridge.react)
# into work/frontend/store.shell.react, where the server serves it from.
# Development with automatic rebuilds: (cd frontend/store.shell.react && npm run watch)
set -euo pipefail
exec "$(cd "$(dirname "$0")/.." && pwd)/frontend/store.shell.react/build.sh"
