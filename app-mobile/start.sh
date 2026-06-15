#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")"

export PATH="/opt/homebrew/opt/ruby/bin:/opt/homebrew/bin:$PATH"

PLATFORM="${1:-ios}"
API_URL="${TECHCALENDAR_API_URL:-https://techcalendar.lucas-dinnichert.fr/api}"

install_ios_pods() {
  BUNDLE_CMD=()

  if [ -x "/opt/homebrew/opt/ruby/bin/ruby" ] && /opt/homebrew/opt/ruby/bin/ruby -S bundle -v >/dev/null 2>&1; then
    BUNDLE_CMD=(/opt/homebrew/opt/ruby/bin/ruby -S bundle)
  elif command -v bundle >/dev/null 2>&1 && bundle -v >/dev/null 2>&1; then
    BUNDLE_CMD=(bundle)
  fi

  if [ "${#BUNDLE_CMD[@]}" -gt 0 ]; then
    "${BUNDLE_CMD[@]}" config set path vendor/bundle
    "${BUNDLE_CMD[@]}" install

    (
      cd ios
      "${BUNDLE_CMD[@]}" exec pod install
    )

    return
  fi

  if command -v pod >/dev/null 2>&1; then
    (
      cd ios
      pod install
    )

    return
  fi

  echo "CocoaPods introuvable. Installe Bundler ou CocoaPods, puis relance ./start.sh."
  echo "Exemples: gem install bundler ou brew install cocoapods"
  exit 1
}

cat > src/config.generated.ts <<CONFIG
export const API_BASE_URL = '${API_URL%/}';
CONFIG

npm install

# Renew the anonymous node_modules volume so Metro cannot keep stale dependencies.
docker compose up --build -d --force-recreate --renew-anon-volumes

printf "Waiting for Metro on port 8081..."
for i in {1..30}; do
  if nc -z localhost 8081 2>/dev/null; then
    echo " ok"
    break
  fi
  printf "."
  sleep 1
  if [ "$i" -eq 30 ]; then
    echo "\nMetro not reachable on 8081. Check docker compose logs."
    exit 1
  fi
done

case "$PLATFORM" in
  ios)
    install_ios_pods

    IOS_ARGS=(run-ios --port 8081 --no-packager)
    if [ -n "${IOS_UDID:-}" ]; then
      IOS_ARGS+=(--udid "$IOS_UDID")
    elif [ -n "${IOS_DEVICE:-}" ]; then
      IOS_ARGS+=(--device "$IOS_DEVICE")
    elif [ -n "${IOS_SIMULATOR:-}" ]; then
      IOS_ARGS+=(--simulator "$IOS_SIMULATOR")
    else
      IOS_ARGS+=(--list-devices)
    fi

    RCT_METRO_PORT=8081 npx react-native "${IOS_ARGS[@]}"
    ;;
  android)
    npx react-native run-android --port 8081 --no-packager
    ;;
  *)
    echo "Usage: ./start.sh [ios|android]"
    exit 1
    ;;
esac
