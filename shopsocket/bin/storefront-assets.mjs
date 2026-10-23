#!/usr/bin/env node
/*
 * Asset manifests for the storefront, the way wp-scripts writes them for the
 * webpack entries: one `<name>.asset.php` beside each built file, carrying a
 * content hash as `version`. The storefront is a plain tsc emit, so webpack
 * never sees it; without this PHP had only the plugin version to enqueue
 * with, and a rebuild that did not bump it left browsers on the old module
 * (2026-09-26). The stylesheet is copied into build/ here too: `src/` is
 * excluded from the release zip, so enqueueing it from there never shipped.
 *
 * Usage: node bin/storefront-assets.mjs [--watch]
 *
 * - Nothing is rewritten when its bytes are unchanged, and the watcher ignores
 *   the css copy it makes itself; before 2026-10-19 the copy re-triggered the
 *   watcher and `npm run start` logged a write forever.
 */
import { createHash } from "node:crypto";
import { existsSync, readFileSync, watch, writeFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";

const root = join(dirname(fileURLToPath(import.meta.url)), "..");
const outDir = join(root, "build", "storefront");
const cssSrc = join(root, "src", "storefront", "storefront.css");
const files = [
  "storefront.js",
  "viewer.js",
  "woocommerce.js",
  "rules.js",
  "rules-vocabulary.js",
  "storefront.css",
];

const version = (path) =>
  createHash("sha256").update(readFileSync(path)).digest("hex").slice(0, 20);

/* Write only when the bytes differ: in watch mode our own writes come back as events, and an unchanged file must not start another round. */
function writeIfChanged(path, content) {
  if (existsSync(path) && readFileSync(path).equals(Buffer.from(content)))
    return false;
  writeFileSync(path, content);
  return true;
}

function write() {
  let changed = 0;
  if (
    existsSync(cssSrc) &&
    writeIfChanged(join(outDir, "storefront.css"), readFileSync(cssSrc))
  )
    changed += 1;
  for (const name of files) {
    const path = join(outDir, name);
    if (!existsSync(path)) continue;
    const php = `<?php return array('dependencies' => array(), 'version' => '${version(path)}');\n`;
    if (
      writeIfChanged(
        `${path.replace(/\.(js|css)$/, "")}${name.endsWith(".css") ? "-css" : ""}.asset.php`,
        php,
      )
    )
      changed += 1;
  }
  return changed;
}

const present = () => files.filter((f) => existsSync(join(outDir, f))).length;
const wrote = write();
console.log(
  `storefront assets: ${present()} manifests ${wrote ? "written" : "up to date"}`,
);

if (process.argv.includes("--watch")) {
  let timer;
  const rerun = () => {
    clearTimeout(timer);
    timer = setTimeout(() => {
      const n = write();
      if (n)
        console.log(
          `storefront assets: ${present()} manifests (${n} rewritten)`,
        );
    }, 150);
  };
  // The css copy in build/ is ours (the src watcher drives it); only tsc's emits should re-run this.
  watch(outDir, (_, name) => name && /\.js$/.test(name) && rerun());
  watch(dirname(cssSrc), (_, name) => name === "storefront.css" && rerun());
  console.log("storefront assets: watching");
}
