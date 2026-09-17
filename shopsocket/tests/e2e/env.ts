/**
 * Environment for the E2E suite, imported first so the values are in place
 * before `@wordpress/e2e-test-utils-playwright` reads them at load time.
 *
 * Machine-specific values live in `tests/local.json` (gitignored; copy
 * `tests/local.example.json`), and every one can be overridden by the
 * environment variable named next to it. Nothing here has a built-in default:
 * a missing value stops the run with the key that is needed.
 *
 * The suite targets the WooCommerce site that
 * `api/scripts/local-tls.sh --e2e-site <root>` pins at the rehearsal server
 * (WooCommerce, WordSocket and this plugin active). It creates and deletes
 * products and orders there; global-setup refuses production and any site
 * whose WordSocket server is not the rehearsal server.
 *
 * `SHOPSOCKET_HTTP=1` (`npm run test:e2e:http`) runs the same suite against a
 * second, plain-HTTP site (`wpRootHttp`, `wpBaseUrlHttp`): developers try the
 * plugin on `http://something.local` first, where the page is not a secure
 * context, payloads are not encrypted, and `crypto.randomUUID` does not exist.
 */
import { execFileSync } from "node:child_process";
import { existsSync, readFileSync } from "node:fs";
import { join, resolve } from "node:path";
import { request } from "@playwright/test";

const LOCAL_CONFIG = resolve(__dirname, "../local.json");

/** JSON key -> environment variable. */
const KEYS = {
  wpRoot: "WP_ROOT",
  wpBaseUrl: "WP_BASE_URL",
  wpUsername: "WP_USERNAME",
  wpPassword: "WP_PASSWORD",
  apiUrl: "WPS_API_URL",
  dashboardEmail: "WPS_E2E_EMAIL",
  dashboardPassword: "WPS_E2E_PASSWORD",
  localTls: "WPS_LOCAL_TLS",
} as const;

const local: Partial<Record<keyof typeof KEYS | "wpRootHttp" | "wpBaseUrlHttp", string>> = existsSync(LOCAL_CONFIG)
  ? JSON.parse(readFileSync(LOCAL_CONFIG, "utf8"))
  : {};

// The plain-HTTP run swaps in the second site; explicit WP_ROOT / WP_BASE_URL still win.
if (process.env.SHOPSOCKET_HTTP) {
  if (!local.wpRootHttp || !local.wpBaseUrlHttp) {
    throw new Error("SHOPSOCKET_HTTP needs wpRootHttp and wpBaseUrlHttp in tests/local.json (a WooCommerce site served over plain http).");
  }
  local.wpRoot = local.wpRootHttp;
  local.wpBaseUrl = local.wpBaseUrlHttp;
}

const missing: string[] = [];
for (const [key, envName] of Object.entries(KEYS)) {
  const value = process.env[envName] ?? local[key as keyof typeof KEYS];
  if (typeof value === "string" && value !== "") {
    process.env[envName] = value;
  } else {
    missing.push(`${key} (or ${envName})`);
  }
}
if (missing.length > 0) {
  throw new Error(
    `E2E configuration missing: ${missing.join(", ")}. Copy tests/local.example.json to tests/local.json and fill it in.`,
  );
}
// The rehearsal script path is relative to the plugin directory.
process.env.WPS_LOCAL_TLS = resolve(__dirname, "../..", process.env.WPS_LOCAL_TLS!);

/*
 * The WordPress fixtures build their REST request context without
 * ignoreHTTPSErrors, so Node must trust the mkcert root CA that signs the
 * local certificates. Playwright's driver inherits this environment.
 */
if (!process.env.NODE_EXTRA_CA_CERTS) {
  try {
    const caRoot = execFileSync("mkcert", ["-CAROOT"], { encoding: "utf8", stdio: ["ignore", "pipe", "ignore"] }).trim();
    const pem = join(caRoot, "rootCA.pem");
    if (existsSync(pem)) {
      process.env.NODE_EXTRA_CA_CERTS = pem;
    }
  } catch {
    // mkcert not installed: only matters for HTTPS targets with private CAs.
  }
}

export const WP_ROOT = process.env.WP_ROOT!;
export const WP_USERNAME = process.env.WP_USERNAME!;
export const WP_PASSWORD = process.env.WP_PASSWORD!;
export const WPS_API_URL = process.env.WPS_API_URL!;
export const WPS_E2E_EMAIL = process.env.WPS_E2E_EMAIL!;
export const WPS_E2E_PASSWORD = process.env.WPS_E2E_PASSWORD!;

/** Run a wp-cli command against the target site and return trimmed stdout. */
export function wp(...args: string[]): string {
  return execFileSync(
    "php",
    // WooCommerce's first-run routines need more than PHP CLI's default 128M.
    ["-d", "error_reporting=0", "-d", "memory_limit=512M", "/opt/homebrew/bin/wp", `--path=${WP_ROOT}`, ...args],
    { encoding: "utf8", stdio: ["ignore", "pipe", "ignore"] },
  ).trim();
}

/** The WordSocket site key stored on the target site ('' when disconnected). */
export function storedSiteKey(): string {
  try {
    return wp("option", "get", "wpsignal_site_key");
  } catch {
    return "";
  }
}

export type DashboardSession = { token: string; api_key: string; role?: string };

/**
 * Log in to the rehearsal dashboard as the seeded E2E user. Uses Playwright's
 * request API rather than fetch: NODE_EXTRA_CA_CERTS set above only reaches
 * processes started afterwards, and the Playwright driver is one of them.
 */
export async function dashboardLogin(): Promise<DashboardSession> {
  const context = await request.newContext({ ignoreHTTPSErrors: true });
  try {
    const response = await context.post(`${WPS_API_URL}/auth/login`, {
      data: { email: WPS_E2E_EMAIL, password: WPS_E2E_PASSWORD },
    });
    if (!response.ok()) {
      throw new Error(`dashboard login failed (${response.status()}); run local-tls.sh --seed-e2e`);
    }
    return (await response.json()) as DashboardSession;
  } finally {
    await context.dispose();
  }
}
