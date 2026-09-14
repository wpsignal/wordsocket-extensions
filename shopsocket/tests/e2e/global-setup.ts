import { WPS_API_URL, WPS_E2E_EMAIL, WP_PASSWORD, WP_USERNAME, dashboardLogin, storedSiteKey, wp } from "./env";
import { request, type FullConfig } from "@playwright/test";
import { RequestUtils } from "@wordpress/e2e-test-utils-playwright";

/**
 * Guards, the E2E administrator, a WordPress login stored for every test,
 * and a connection baseline with the seeded dashboard account.
 */
export default async function globalSetup(config: FullConfig): Promise<void> {
  const env = wp("eval", "echo wp_get_environment_type();");
  if (!["local", "development"].includes(env)) {
    throw new Error(`Refusing to run against a '${env}' site; expected local or development.`);
  }
  for (const plugin of ["woocommerce", "wordsocket", "shopsocket"]) {
    if (wp("plugin", "list", `--name=${plugin}`, "--status=active", "--field=name") !== plugin) {
      throw new Error(`${plugin} is not active on the target site.`);
    }
  }
  const serverUrl = wp("eval", "echo \\WPSignal\\WPS::instance()->config()->base_url();");
  if (serverUrl.replace(/\/$/, "") !== WPS_API_URL.replace(/\/$/, "")) {
    throw new Error(`The target site talks to ${serverUrl}, not the rehearsal server ${WPS_API_URL}.`);
  }

  if (wp("user", "list", `--login=${WP_USERNAME}`, "--field=user_login") !== WP_USERNAME) {
    wp("user", "create", WP_USERNAME, `${WP_USERNAME}@example.com`, "--role=administrator", `--user_pass=${WP_PASSWORD}`);
  } else {
    wp("user", "update", WP_USERNAME, `--user_pass=${WP_PASSWORD}`);
  }

  // Storefront checkouts need a payment method; cash on delivery is the simplest.
  wp("option", "update", "woocommerce_cod_settings", '{"enabled":"yes","title":"Cash on delivery"}', "--format=json");
  wp("option", "update", "woocommerce_coming_soon", "no");

  const { storageState, baseURL } = config.projects[0].use;
  const requestContext = await request.newContext({ baseURL, ignoreHTTPSErrors: true });
  const requestUtils = new RequestUtils(requestContext, {
    storageStatePath: typeof storageState === "string" ? storageState : undefined,
  });
  await requestUtils.setupRest();

  // Baseline: connected with the seeded account's key.
  const session = await dashboardLogin();
  const settings = await requestUtils.rest({ path: "/wpsignal/v1/settings" });
  if (!settings?.is_connected) {
    if (storedSiteKey() !== "") {
      await requestUtils.rest({ method: "POST", path: "/wpsignal/v1/disconnect" });
    }
    const response = await requestUtils.rest({ method: "POST", path: "/wpsignal/v1/connect", data: { api_key: session.api_key } });
    if (!response?.site_key) {
      throw new Error(`Could not connect the WooCommerce site as ${WPS_E2E_EMAIL}: ${JSON.stringify(response)}`);
    }
  }
  await requestContext.dispose();
}
