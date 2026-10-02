import baseConfig from "./playwright.config";
import { defineConfig, devices } from "@playwright/test";

/**
 * Stages the WordPress.org listing screenshots (`npm run shots`): the same
 * rehearsal stack and guards as the E2E suite, pointed at `tests/shots/`,
 * which the suite itself never runs. One worker, no retries: the scenario is
 * a script, and a half-staged board is worse than a failure.
 *
 * The device settings sit on the project because the base config's project
 * (`Desktop Chrome`, scale 1) would override anything set at the top level.
 */
export default defineConfig({
  ...baseConfig,
  testDir: "tests/shots",
  testMatch: /.*\.shots\.ts$/,
  workers: 1,
  retries: 0,
  timeout: 240_000,
  projects: [
    {
      name: "chromium",
      use: {
        ...devices["Desktop Chrome"],
        viewport: { width: 1280, height: 1180 },
        deviceScaleFactor: 2,
        video: "off",
        trace: "off",
      },
    },
  ],
});
