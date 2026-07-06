#!/usr/bin/env bash
set -euo pipefail

plugin_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$plugin_dir"

printf 'Local check:\n'
grep -n "class-tcm-chart" tcm-adherents.php || true
grep -n "TCM_Chart" includes/class-tcm-plugin.php || true

printf '\nLive check:\n'
url='https://dev.tcmimet.fr/tableau-de-bord/'
response=$(curl -sL "$url")
if printf '%s' "$response" | grep -q "\[tcm_chart"; then
  printf 'SHORTCODE_RAW\n'
fi
if printf '%s' "$response" | grep -q 'tcm-chart'; then
  printf 'RENDERED_HTML\n'
fi
printf '\nDone.\n'
