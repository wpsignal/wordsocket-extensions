#!/usr/bin/env bash
#
# dist.sh: build a WordPress.org-ready release of one extension.
#
# Usage:
#   bin/dist.sh <plugin>            e.g. bin/dist.sh shopsocket
#   npm run dist                    from inside the plugin directory
#
# Outputs (inside the plugin directory):
#   dist/<plugin>.zip     the plugin, staged per .distignore
#   dist/svn-assets/      icons, banners, screenshots for the SVN assets/ directory
#
# Assets come from <plugin>/wp-org-assets/ or <plugin>/wporg-assets/ (or
# $WPS_ASSETS_DIR), named the WordPress.org way (icon-128x128.png,
# banner-772x250.jpg, screenshot-1.png), with a `wporg-` prefix on those names
# (wporg-icon-128x128.png), or prefixed with the slug (shopsocket-128x128.png).
#
set -euo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
resolve_plugin "${1:-}"
cd "$PLUGIN_DIR"

ZIP_NAME="${PLUGIN_SLUG}.zip"
DIST_DIR="$PLUGIN_DIR/dist"
STAGE_DIR="$DIST_DIR/$PLUGIN_SLUG"

bold "$PLUGIN_NAME dist: v${VERSION}"
echo ""

info "Cleaning dist/"
rm -rf "$DIST_DIR"
mkdir -p "$DIST_DIR"

info "Typechecking (npm run typecheck)"
npm run typecheck --silent
ok "Types OK"

# The plugin's own build script, so anything beyond wp-scripts (ShopSocket's tsc emit) is included.
info "Building (npm run build)"
npm run build --silent
ok "Assets built"

info "Generating POT (npm run make-pot)"
npm run make-pot --silent 2>&1 | grep -v "^$" | grep -vi "xdebug" || true
ok "POT generated"

info "Staging plugin files → dist/${PLUGIN_SLUG}/"
mkdir -p "$STAGE_DIR"
RSYNC_EXCLUDES=()
while IFS= read -r line; do
  [[ -z "$line" || "$line" == \#* ]] && continue
  RSYNC_EXCLUDES+=(--exclude="$line")
done < "$PLUGIN_DIR/.distignore"
RSYNC_EXCLUDES+=(--exclude="/dist/" --exclude="/wp-org-assets/" --exclude="/wporg-assets/" --exclude="/.DS_Store" --exclude="**/.DS_Store")
rsync -a --no-owner --no-group "${RSYNC_EXCLUDES[@]}" "$PLUGIN_DIR/" "$STAGE_DIR/"
ok "Files staged"

info "Creating ${ZIP_NAME}"
(cd "$DIST_DIR" && zip -rq "$ZIP_NAME" "$PLUGIN_SLUG/")
rm -rf "$STAGE_DIR"
ok "Zip created: dist/${ZIP_NAME}"

# The zip must say what package.json says, in the header and the stable tag.
HEADER="$(zip_header_version "$DIST_DIR/$ZIP_NAME")"
STABLE="$(zip_stable_tag "$DIST_DIR/$ZIP_NAME")"
[[ "$HEADER" == "$VERSION" ]] || die "Header says $HEADER, package.json says $VERSION"
[[ "$STABLE" == "$VERSION" ]] || die "Stable tag says $STABLE, package.json says $VERSION"
ok "Version $VERSION in header and stable tag"

echo ""
bold "SVN assets"
ASSETS_SRC="${WPS_ASSETS_DIR:-}"
if [[ -z "$ASSETS_SRC" ]]; then
  for candidate in "$PLUGIN_DIR/wp-org-assets" "$PLUGIN_DIR/wporg-assets"; do
    [[ -d "$candidate" ]] && { ASSETS_SRC="$candidate"; break; }
  done
fi
if [[ -z "$ASSETS_SRC" || ! -d "$ASSETS_SRC" ]]; then
  warn "No $PLUGIN_SLUG/wp-org-assets/ (or wporg-assets/) directory: icons, banners, and screenshots are not staged."
else
  SVN_ASSETS_DIR="$DIST_DIR/svn-assets"
  mkdir -p "$SVN_ASSETS_DIR"
  COPIED=0
  for f in "$ASSETS_SRC"/*; do
    [[ -f "$f" ]] || continue
    name="$(basename "$f")"
    case "$name" in
      "${PLUGIN_SLUG}"-128x128.*|"${PLUGIN_SLUG}"-256x256.*)   dest="icon-${name#"${PLUGIN_SLUG}"-}" ;;
      "${PLUGIN_SLUG}"-772x250.*|"${PLUGIN_SLUG}"-1544x500.*)  dest="banner-${name#"${PLUGIN_SLUG}"-}" ;;
      wporg-icon-*|wporg-banner-*|wporg-screenshot-*) dest="${name#wporg-}" ;;
      icon-*|banner-*|screenshot-*) dest="$name" ;;
      *) continue ;;
    esac
    cp "$f" "$SVN_ASSETS_DIR/$dest"
    info "  $name → svn-assets/$dest"
    COPIED=$(( COPIED + 1 ))
  done
  if [[ $COPIED -gt 0 ]]; then ok "$COPIED asset(s) copied to dist/svn-assets/"; else warn "No matching asset files in $ASSETS_SRC"; fi
fi

echo ""
bold "Done"
echo ""
echo "  Plugin zip:   $PLUGIN_SLUG/dist/${ZIP_NAME}"
[[ -d "$DIST_DIR/svn-assets" ]] && echo "  SVN assets:   $PLUGIN_SLUG/dist/svn-assets/"
echo ""
