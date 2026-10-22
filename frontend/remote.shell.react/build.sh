#!/bin/bash
set -e
cd "$(dirname "$0")"

# Install dependencies if needed (o bridge tem as suas: @noble/*)
if [ ! -d "node_modules" ]; then
    npm install
fi
if [ ! -d "../remote.bridge.react/node_modules" ]; then
    (cd ../remote.bridge.react && npm install)
fi

DEPLOY_DIR="../../work/frontend/remote.shell.react"

# Build React SPA (output goes to work/frontend/remote.shell.react via distDir in package.json)
# context.html is copied automatically by the build:clean script
npm run build:clean

echo "Deploy complete: $DEPLOY_DIR"
