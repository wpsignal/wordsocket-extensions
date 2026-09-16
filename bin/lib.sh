#!/usr/bin/env bash
#
# lib.sh: shared pieces for the extension release scripts. Every script takes
# the plugin directory as its first argument (`shopsocket`), and this resolves
# it into the plugin's paths, slug, and version. Source it, then call
# `resolve_plugin "$1"`.
#
bold()  { printf '\033[1m%s\033[0m\n' "$*"; }
info()  { printf '  \033[34m→\033[0m %s\n' "$*"; }
ok()    { printf '  \033[32m✔\033[0m %s\n' "$*"; }
warn()  { printf '  \033[33m⚠\033[0m %s\n' "$*"; }
die()   { printf '\033[31mError:\033[0m %s\n' "$*" >&2; exit 1; }

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

# The plugins this repository releases: every directory with a package.json
# and a main file named after it.
list_plugins() {
  local dir slug
  for dir in "$REPO_ROOT"/*/; do
    dir="${dir%/}"
    slug="$(basename "$dir")"
    [[ -f "$dir/package.json" && -f "$dir/$slug.php" ]] && echo "$slug"
  done
  return 0
}

# Sets PLUGIN_SLUG, PLUGIN_DIR, MAIN_FILE, PLUGIN_NAME, VERSION for `$1`.
resolve_plugin() {
  local slug="${1:-}"
  [[ -n "$slug" ]] || die "Which plugin? One of: $(list_plugins | tr '\n' ' ')"
  slug="${slug%/}"
  PLUGIN_SLUG="$slug"
  PLUGIN_DIR="$REPO_ROOT/$slug"
  MAIN_FILE="$PLUGIN_DIR/$slug.php"
  [[ -f "$PLUGIN_DIR/package.json" ]] || die "No such plugin: $slug (no $slug/package.json)"
  [[ -f "$MAIN_FILE" ]] || die "No main file: $slug/$slug.php"
  VERSION="$(node -p "require('$PLUGIN_DIR/package.json').version")"
  PLUGIN_NAME="$(grep -m1 'Plugin Name:' "$MAIN_FILE" | sed 's/.*Plugin Name:[[:space:]]*//' | tr -d '\r')"
  export PLUGIN_SLUG PLUGIN_DIR MAIN_FILE PLUGIN_NAME VERSION
}

# The version a built zip declares, from its header and its readme stable tag.
zip_header_version() {
  unzip -p "$1" "$PLUGIN_SLUG/$PLUGIN_SLUG.php" | grep -m1 'Version:' | sed 's/.*Version:[[:space:]]*//' | tr -d '[:space:]'
}
zip_stable_tag() {
  unzip -p "$1" "$PLUGIN_SLUG/readme.txt" | grep -m1 '^Stable tag:' | sed 's/Stable tag:[[:space:]]*//' | tr -d '[:space:]'
}
