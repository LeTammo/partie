#!/usr/bin/env bash
#
#   mkdir -p tools/mercure && cd tools/mercure \
#     && curl -sL https://github.com/dunglas/mercure/releases/download/v0.24.2/mercure_$(uname -s)_$(uname -m).tar.gz | tar xz
#
set -euo pipefail

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
MERCURE_DIR="$PROJECT_DIR/tools/mercure"

if [ ! -x "$MERCURE_DIR/mercure" ]; then
    echo "Mercure binary not found in $MERCURE_DIR - see the download instructions in this script." >&2
    exit 1
fi

export SERVER_NAME=':3000'
# Must match MERCURE_JWT_SECRET in .env.dev
export MERCURE_PUBLISHER_JWT_KEY='!ChangeThisMercureHubJWTSecretKey!'
export MERCURE_SUBSCRIBER_JWT_KEY='!ChangeThisMercureHubJWTSecretKey!'

cd "$MERCURE_DIR"
exec ./mercure run --config dev.Caddyfile
