#!/usr/bin/env bash
# Compila o shell React (frontend/remote.shell.react + frontend/remote.bridge.react)
# para work/frontend/remote.shell.react, de onde o servidor o serve.
# Desenvolvimento com recompilação automática: (cd frontend/remote.shell.react && npm run watch)
set -euo pipefail
exec "$(cd "$(dirname "$0")/.." && pwd)/frontend/remote.shell.react/build.sh"
