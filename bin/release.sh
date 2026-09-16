#!/usr/bin/env bash
#
# release.sh: bump one extension, commit, tag it <plugin>/vX.Y.Z, and push.
#
# Usage:
#   bin/release.sh <plugin> [--tested <wp-version>] <version> ["changelog entry"] ...
#   npm run release -- [--tested 7.1] 1.0.0 "First release"     from inside the plugin directory
#
# What it updates, all inside <plugin>/:
#   package.json         version
#   <plugin>.php         Version header and the VERSION constant (+ Tested up to, with --tested)
#   readme.txt           Stable tag, Changelog and Upgrade Notice sections (+ Tested up to)
#   CHANGELOG.md         one line per release (created if missing)
# and the shared version registry (config/versions.json, and the site's copy)
# through <project root>/scripts/versions.sh when it is there.
#
# Refuses to run with uncommitted changes under <plugin>/, so the release
# commit holds the bump and nothing else. Other plugins' changes never ride
# along: only <plugin>/ is staged.
#
# The tag `<plugin>/vX.Y.Z` triggers .github/workflows/release.yml, which
# builds the zip, verifies its version, creates the GitHub release, then
# publishes to WordPress.org. If it fails, fix the cause and move the tag:
#   git tag -fs <plugin>/vX.Y.Z -m "Release <plugin> vX.Y.Z" && git push --force origin <plugin>/vX.Y.Z
#
set -euo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
resolve_plugin "${1:-}"
shift || true
cd "$PLUGIN_DIR"

TESTED_UP_TO=""
if [[ "${1:-}" == "--tested" ]]; then
  TESTED_UP_TO="${2:-}"; shift 2 || true
  [[ "$TESTED_UP_TO" =~ ^[0-9]+\.[0-9]+(\.[0-9]+)?$ ]] || die "--tested needs a WordPress version like 7.1 (got: '$TESTED_UP_TO')"
fi

if [[ $# -lt 1 ]]; then
  bold "Usage: bin/release.sh <plugin> [--tested <wp-version>] <version> [\"changelog entry\"] ..."
  exit 1
fi

NEW_VERSION="$1"; shift
[[ "$NEW_VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || die "Version must be X.Y.Z (got: $NEW_VERSION)"
CURRENT_VERSION="$VERSION"
[[ "$NEW_VERSION" != "$CURRENT_VERSION" ]] || die "Already at v$NEW_VERSION: nothing to bump."

BULLETS=()
while [[ $# -gt 0 ]]; do BULLETS+=("$1"); shift; done

TAG="${PLUGIN_SLUG}/v${NEW_VERSION}"

bold "Releasing $PLUGIN_NAME v$CURRENT_VERSION → v$NEW_VERSION"
echo ""

info "Checking the working tree"
[[ -z "$(git -C "$REPO_ROOT" status --porcelain -- "$PLUGIN_SLUG")" ]] \
  || die "$PLUGIN_SLUG/ has uncommitted changes. Commit them first; the release commit carries only the bump."
if git -C "$REPO_ROOT" rev-parse -q --verify "refs/tags/$TAG" >/dev/null; then
  die "Tag $TAG already exists."
fi
ok "Clean"

info "package.json"
node -e "
  const fs = require('fs');
  const p = JSON.parse(fs.readFileSync('package.json', 'utf8'));
  p.version = '$NEW_VERSION';
  fs.writeFileSync('package.json', JSON.stringify(p, null, 2) + '\n');
"
ok "package.json → $NEW_VERSION"

info "$PLUGIN_SLUG.php"
perl -i -pe "s/(Version:\s+)\Q$CURRENT_VERSION\E/\${1}$NEW_VERSION/" "$MAIN_FILE"
perl -i -pe "s/const VERSION = '\Q$CURRENT_VERSION\E'/const VERSION = '$NEW_VERSION'/" "$MAIN_FILE"
ok "$PLUGIN_SLUG.php → $NEW_VERSION"

if [[ -n "$TESTED_UP_TO" ]]; then
  perl -i -pe "s/(Tested up to:\s+)[0-9.]+/\${1}$TESTED_UP_TO/" "$MAIN_FILE"
  perl -i -pe "s/^(Tested up to: )[0-9.]+/\${1}$TESTED_UP_TO/" readme.txt
  ok "Tested up to → $TESTED_UP_TO"
fi

VERSIONS_SH="$REPO_ROOT/../scripts/versions.sh"
if [[ -x "$VERSIONS_SH" ]]; then
  info "config/versions.json"
  "$VERSIONS_SH" set "$PLUGIN_SLUG" "$NEW_VERSION" >/dev/null
  ok "versions.json → $NEW_VERSION (the site's copy lives in the site repo: commit it there)"
fi

info "readme.txt + CHANGELOG.md"
export _WPS_NEW="$NEW_VERSION" _WPS_OLD="$CURRENT_VERSION"
export _WPS_BULLETS
_WPS_BULLETS="$(printf '%s\n' ${BULLETS[@]+"${BULLETS[@]}"})"
python3 - <<'PY'
import os, re
new_ver = os.environ['_WPS_NEW']
old_ver = os.environ['_WPS_OLD']
bullets = [b for b in os.environ.get('_WPS_BULLETS', '').splitlines() if b.strip()]

txt = open('readme.txt').read()
txt = txt.replace(f'Stable tag: {old_ver}', f'Stable tag: {new_ver}')
chg = f'= {new_ver} =\n' + ''.join(f'* {b}\n' for b in bullets) + '\n'
section = r'= ' + re.escape(new_ver) + r' =\n(?:(?!= [0-9]).*\n)*'
existing = re.search(section, txt)
if existing and not bullets:
    pass  # a hand-written section stays
elif existing:
    txt = re.sub(section, chg, txt, count=1)
elif '== Changelog ==\n' in txt:
    txt = txt.replace('== Changelog ==\n', f'== Changelog ==\n\n{chg}', 1)
else:
    txt = txt.rstrip('\n') + f'\n\n== Changelog ==\n\n{chg}'
notice = bullets[0] if bullets else 'See the changelog for details.'
parts = txt.split('== Upgrade Notice ==', 1)
if len(parts) > 1 and f'= {new_ver} =' not in parts[1]:
    txt = txt.replace('== Upgrade Notice ==\n', f'== Upgrade Notice ==\n\n= {new_ver} =\n{notice}\n', 1)
open('readme.txt', 'w').write(txt)

changelog = open('CHANGELOG.md').read() if os.path.exists('CHANGELOG.md') else ''
if not re.search(r'^\*\*' + re.escape(new_ver) + r'\*\*', changelog, re.M):
    summary = bullets[0] if bullets else f'Release {new_ver}'
    changelog = f'**{new_ver}** - {summary.rstrip(".")}.\n\n' + changelog
open('CHANGELOG.md', 'w').write(changelog)
PY
unset _WPS_NEW _WPS_OLD _WPS_BULLETS
ok "readme.txt + CHANGELOG.md → $NEW_VERSION"

echo ""
bold "Git"
git -C "$REPO_ROOT" add -- "$PLUGIN_SLUG/package.json" "$PLUGIN_SLUG/$PLUGIN_SLUG.php" "$PLUGIN_SLUG/readme.txt" "$PLUGIN_SLUG/CHANGELOG.md"
git -C "$REPO_ROOT" commit -q -m "chore: release $PLUGIN_SLUG v${NEW_VERSION}"
ok "Committed"
git -C "$REPO_ROOT" tag -s -m "Release $PLUGIN_NAME v${NEW_VERSION}" "$TAG"
ok "Tagged $TAG"
git -C "$REPO_ROOT" push && git -C "$REPO_ROOT" push origin "$TAG"
ok "Pushed: the release workflow builds, verifies, creates the GitHub release, then publishes to WordPress.org"

echo ""
bold "Done: $PLUGIN_NAME v$NEW_VERSION"
echo ""
echo "  Workflow:        https://github.com/wpsignal/wordsocket-extensions/actions"
echo "  GitHub release:  https://github.com/wpsignal/wordsocket-extensions/releases/tag/$TAG"
echo "  WordPress.org:   https://wordpress.org/plugins/$PLUGIN_SLUG/"
echo ""
