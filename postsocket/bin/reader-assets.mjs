#!/usr/bin/env node
/*
 * Asset manifests for the reader modules, the way wp-scripts writes them for the webpack entries:
 * one `<name>.asset.php` beside each built file with a content hash as `version`, and the stylesheet copied into build/.
 *
 * Usage: node bin/reader-assets.mjs [--watch]
 */
import { createHash } from "node:crypto";
import { existsSync, mkdirSync, readFileSync, watch, writeFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";

const root = join(dirname(fileURLToPath(import.meta.url)), "..");
const outDir = join(root, "build", "reader");
const cssSrc = join(root, "src", "reader", "reader.css");
const files = ["feed.js", "living.js", "morph.js", "relay.js", "assets.js", "reader.css"];

const version = (path) => createHash("sha256").update(readFileSync(path)).digest("hex").slice(0, 20);

function writeIfChanged(path, content) {
  if (existsSync(path) && readFileSync(path).equals(Buffer.from(content))) return false;
  writeFileSync(path, content);
  return true;
}

function write() {
  mkdirSync(outDir, { recursive: true });
  let changed = 0;
  if (existsSync(cssSrc) && writeIfChanged(join(outDir, "reader.css"), readFileSync(cssSrc))) changed += 1;
  for (const name of files) {
    const path = join(outDir, name);
    if (!existsSync(path)) continue;
    const php = `<?php return array('dependencies' => array(), 'version' => '${version(path)}');\n`;
    const manifest = `${path.replace(/\.(js|css)$/, "")}${name.endsWith(".css") ? "-css" : ""}.asset.php`;
    if (writeIfChanged(manifest, php)) changed += 1;
  }
  if (changed) console.log(`[reader-assets] ${changed} manifest(s) written`);
}

write();

if (process.argv.includes("--watch")) {
  let timer = null;
  const schedule = () => {
    clearTimeout(timer);
    timer = setTimeout(write, 150);
  };
  // Our own writes (the manifests, the stylesheet copy) come back as events and must not start another round.
  watch(outDir, (_event, file) => {
    if (String(file).endsWith(".asset.php") || file === "reader.css") return;
    schedule();
  });
  watch(dirname(cssSrc), (_event, file) => {
    if (file === "reader.css") schedule();
  });
  console.log("[reader-assets] watching build/reader and src/reader/reader.css");
}
