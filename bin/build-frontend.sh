#!/usr/bin/env bash
# Compila o shell React (frontend/shopping.shell.react + frontend/remote.bridge.react)
# para work/frontend/shopping.shell.react, de onde o servidor o serve.
# Desenvolvimento com recompilação automática: (cd frontend/shopping.shell.react && npm run watch)
set -euo pipefail
exec "$(cd "$(dirname "$0")/.." && pwd)/frontend/shopping.shell.react/build.sh"
