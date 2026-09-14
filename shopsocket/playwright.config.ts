import "./tests/e2e/env";
import { defineConfig } from "@playwright/test";
import baseConfig from "@wordpress/scripts/config/playwright.config";

/**
 * WordPress' Playwright defaults, pointed at the local WooCommerce site.
 * The run owns the rehearsal server: started here (pinning the site and
 * seeding the dashboard user) and killed when the run ends.
 */
export default defineConfig({
  ...baseConfig,
  testDir: "tests/e2e",
  globalSetup: require.resolve("./tests/e2e/global-setup.ts"),
  webServer: {
    command: `${process.env.WPS_LOCAL_TLS} --e2e-site ${process.env.WP_ROOT} --seed-e2e`,
    env: {
      E2E_EMAIL: process.env.WPS_E2E_EMAIL!,
      E2E_PASSWORD: process.env.WPS_E2E_PASSWORD!,
    },
    url: `${process.env.WPS_API_URL}/healthz`,
    ignoreHTTPSErrors: true,
    reuseExistingServer: false,
    timeout: 300_000,
    stdout: "ignore",
    stderr: "pipe",
  },
  timeout: 30_000,
  expect: { timeout: 10_000 },
});
