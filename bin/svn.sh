#!/usr/bin/env bash
#
# svn.sh: commit a built extension release to WordPress.org SVN.
#
# Usage:
#   bin/svn.sh <plugin>                    full release (trunk + assets + tag)
#   bin/svn.sh <plugin> --assets-only      icons, banners, screenshots only
#   bin/svn.sh <plugin> --readme-only      readme.txt only (trunk and the stable tag)
#   npm run svn -- --readme-only           from inside the plugin directory
#
# Needs dist/<plugin>.zip from bin/dist.sh and an SVN checkout at $SVN_DIR
# (default: <project root>/<plugin>-svn, beside the extensions repo).
# SVN_USERNAME alone lets svn prompt; with SVN_PASSWORD the run is unattended
# and nothing is cached (how the release workflow calls it).
#
set -euo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
resolve_plugin "${1:-}"
shift || true

ASSETS_ONLY=false
README_ONLY=false
for arg in "$@"; do
  [[ "$arg" == "--assets-only" ]] && ASSETS_ONLY=true
  [[ "$arg" == "--readme-only" ]] && README_ONLY=true
done

SVN_DIR="${SVN_DIR:-$REPO_ROOT/../${PLUGIN_SLUG}-svn}"
SVN_URL="https://plugins.svn.wordpress.org/${PLUGIN_SLUG}"
ZIP="$PLUGIN_DIR/dist/${PLUGIN_SLUG}.zip"
SVN_ASSETS_SRC="$PLUGIN_DIR/dist/svn-assets"

SVN_USER_FLAG=()
if [[ -n "${SVN_USERNAME:-}" ]]; then
  SVN_USER_FLAG=(--username "$SVN_USERNAME")
fi
if [[ -n "${SVN_PASSWORD:-}" ]]; then
  [[ -n "${SVN_USERNAME:-}" ]] || die "SVN_PASSWORD needs SVN_USERNAME too."
  SVN_USER_FLAG+=(--password "$SVN_PASSWORD" --non-interactive --no-auth-cache)
fi

MODE=""
[[ "$ASSETS_ONLY" == true ]] && MODE=" (assets only)"
[[ "$README_ONLY" == true ]] && MODE=" (readme only)"
bold "$PLUGIN_NAME SVN: v${VERSION}${MODE}"
echo ""

info "Checking prerequisites"
[[ -d "$SVN_DIR" ]] || die "SVN directory not found: $SVN_DIR (svn checkout $SVN_URL $SVN_DIR)"
SVN_DIR="$(cd "$SVN_DIR" && pwd)"
command -v svn &>/dev/null || die "svn is not installed. Run: brew install subversion"

if [[ "$ASSETS_ONLY" == false && "$README_ONLY" == false ]]; then
  [[ -f "$ZIP" ]] || die "$PLUGIN_SLUG/dist/${PLUGIN_SLUG}.zip not found. Run bin/dist.sh $PLUGIN_SLUG first."
  ZIP_VERSION="$(zip_header_version "$ZIP")"
  [[ "$ZIP_VERSION" == "$VERSION" ]] || die "package.json says $VERSION but the zip says $ZIP_VERSION. Rebuild it."
fi

info "Updating SVN checkout"
(cd "$SVN_DIR" && svn update --quiet)

if [[ "$ASSETS_ONLY" == false && "$README_ONLY" == false ]]; then
  if svn list "$SVN_URL/tags/$VERSION" &>/dev/null; then
    die "Tag $VERSION already exists in SVN. Bump the version before releasing."
  fi
fi
ok "Prerequisites met"

if [[ "$README_ONLY" == true ]]; then
  echo ""
  bold "Updating readme.txt"
  cp "$PLUGIN_DIR/readme.txt" "$SVN_DIR/trunk/readme.txt"
  ok "readme.txt copied to trunk/"
  # wp.org serves the listing from the stable tag, not trunk.
  if [[ -d "$SVN_DIR/tags/$VERSION" ]]; then
    cp "$PLUGIN_DIR/readme.txt" "$SVN_DIR/tags/$VERSION/readme.txt"
    ok "readme.txt copied to tags/$VERSION/"
  else
    warn "tags/$VERSION/ not found: only trunk updated."
  fi
  echo ""
  bold "Committing to WordPress.org SVN"
  (cd "$SVN_DIR" && svn commit ${SVN_USER_FLAG[@]+"${SVN_USER_FLAG[@]}"} -m "Update readme.txt")
  echo ""
  bold "Done"
  exit 0
fi

if [[ "$ASSETS_ONLY" == false ]]; then
  echo ""
  bold "Syncing trunk"
  find "$SVN_DIR/trunk" -mindepth 1 -delete
  mkdir -p "$SVN_DIR/trunk"
  STAGE="$(mktemp -d)"
  unzip -q "$ZIP" -d "$STAGE"
  cp -r "$STAGE/$PLUGIN_SLUG"/. "$SVN_DIR/trunk/"
  rm -rf "$STAGE" "$SVN_DIR/trunk/vendor"
  ok "trunk/ updated from the zip"
fi

echo ""
bold "Syncing assets"
if [[ -d "$SVN_ASSETS_SRC" ]]; then
  mkdir -p "$SVN_DIR/assets"
  find "$SVN_DIR/assets" -maxdepth 1 -type f -delete
  cp -r "$SVN_ASSETS_SRC"/. "$SVN_DIR/assets/"
  ok "assets/ updated from dist/svn-assets/"
else
  warn "No dist/svn-assets/: assets/ left as it is."
fi

echo ""
bold "Staging SVN changes"
cd "$SVN_DIR"
if [[ "$ASSETS_ONLY" == false ]]; then
  svn add --force trunk/ assets/ --no-ignore 2>/dev/null || true
else
  svn add --force assets/ --no-ignore 2>/dev/null || true
fi
while IFS= read -r f; do
  [[ -n "$f" ]] && svn delete "$f"
done < <(svn status | grep '^!' | awk '{print $2}' || true)
ok "Adds and deletes staged"

if [[ "$ASSETS_ONLY" == false ]]; then
  echo ""
  bold "Tagging release"
  svn cp trunk "tags/$VERSION"
  ok "tags/$VERSION"
fi

echo ""
bold "Committing to WordPress.org SVN"
if [[ "$ASSETS_ONLY" == true ]]; then
  svn commit ${SVN_USER_FLAG[@]+"${SVN_USER_FLAG[@]}"} -m "Update assets"
else
  svn commit ${SVN_USER_FLAG[@]+"${SVN_USER_FLAG[@]}"} -m "Release version $VERSION"
fi

echo ""
bold "Done"
[[ "$ASSETS_ONLY" == false ]] && echo "  https://wordpress.org/plugins/$PLUGIN_SLUG/ (a few minutes from now)"
echo ""
