#!/bin/sh
set -e

real_hermesc="${PODS_ROOT}/hermes-engine/destroot/bin/hermesc"

if [ ! -x "$real_hermesc" ]; then
  echo "error: Hermes compiler not found at $real_hermesc" >&2
  exit 1
fi

exec "$real_hermesc" -w "$@"
