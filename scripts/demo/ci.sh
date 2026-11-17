#!/usr/bin/env bash
set -euo pipefail
export DEMO_PRINT_CREDENTIALS=false

# Unique disposable CI project; no host .env passwords are passed into the stack.
project="mediqueue-ci-${GITHUB_RUN_ID:-local}"
dev_project="${project}-dev"
compose=(docker compose --project-name "$project")
dev_compose=(docker compose --project-name "$dev_project" -f compose.yaml -f .devcontainer/compose.yaml)
cleanup() {
    "${compose[@]}" down --volumes --remove-orphans
    "${dev_compose[@]}" down --volumes --remove-orphans
}
trap cleanup EXIT

"${compose[@]}" config --quiet
"${dev_compose[@]}" config --quiet
"${compose[@]}" up --build --detach --wait --wait-timeout 240
"${compose[@]}" exec -T app php -r 'exit(is_file("/run/mediqueue-secrets/database.json") || getenv("DB_ROUTINE_ADMIN_PASSWORD") ? 1 : 0);'
"${compose[@]}" exec -T app php scripts/demo/proxy-smoke.php
"${compose[@]}" run --rm smoke

# Recreate containers without deleting volumes, then prove initial accounts are unchanged.
"${compose[@]}" down
"${compose[@]}" up --detach --wait --wait-timeout 240
"${compose[@]}" run --rm smoke --verify-restart
"${compose[@]}" --profile test run --build --rm tests --compact
"${compose[@]}" down

# The actual development override must install tools/dependencies and serve its mounted source.
"${dev_compose[@]}" up --build --detach --wait --wait-timeout 300
"${dev_compose[@]}" exec -T app php scripts/demo/health.php
"${dev_compose[@]}" exec -T app php scripts/demo/proxy-smoke.php --trusted
"${dev_compose[@]}" exec -T app composer analyse
"${dev_compose[@]}" exec -T app npm run build
"${dev_compose[@]}" exec -T app php -r 'exit(is_file("/run/mediqueue-secrets/database.json") ? 1 : 0);'
printf 'Compose first boot, restart, privilege boundary, three-role HTTP smoke and development startup passed.\n'
