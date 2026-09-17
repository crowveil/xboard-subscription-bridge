#!/usr/bin/env bash
# Run with: bash publish.sh [--check]
set -euo pipefail
cd -- "$(dirname -- "${BASH_SOURCE[0]}")"
command -v python3 >/dev/null 2>&1 || { echo '缺少 python3，请先安装。' >&2; exit 1; }
exec python3 tools/publish.py "$@"
