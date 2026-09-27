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
 */
import { createHash } from "node:crypto";
import { copyFileSync, existsSync, readFileSync, watch, writeFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";

const root = join(dirname(fileURLToPath(import.meta.url)), "..");
const outDir = join(root, "build", "storefront");
const cssSrc = join(root, "src", "storefront", "storefront.css");
const files = ["storefront.js", "viewer.js", "woocommerce.js", "storefront.css"];

const version = (path) => createHash("sha256").update(readFileSync(path)).digest("hex").slice(0, 20);

function write() {
  if (existsSync(cssSrc)) copyFileSync(cssSrc, join(outDir, "storefront.css"));
  for (const name of files) {
    const path = join(outDir, name);
    if (!existsSync(path)) continue;
    const php = `<?php return array('dependencies' => array(), 'version' => '${version(path)}');\n`;
    writeFileSync(`${path.replace(/\.(js|css)$/, "")}${name.endsWith(".css") ? "-css" : ""}.asset.php`, php);
  }
  console.log(`storefront assets: ${files.filter((f) => existsSync(join(outDir, f))).length} manifests written`);
}

write();

if (process.argv.includes("--watch")) {
  let timer;
  const rerun = () => {
    clearTimeout(timer);
    timer = setTimeout(write, 150);
  };
  watch(outDir, (_, name) => name && /\.(js|css)$/.test(name) && rerun());
  watch(dirname(cssSrc), (_, name) => name === "storefront.css" && rerun());
  console.log("storefront assets: watching");
}
