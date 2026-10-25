/**
 * ShopSocket on WordSocket's settings page: the card in the Extensions tab
 * (whether the site is live and where the board is) and, on WordSocket 0.28
 * and later, a ShopSocket tab holding the storefront settings.
 */
import { registerPlugin } from "@wordpress/plugins";
import apiFetch from "@wordpress/api-fetch";
import { __ } from "@wordpress/i18n";
import "./settings.css";
import ShopSocketLogo from "./ShopSocketLogo";
import { SettingsTab } from "./SettingsTab";
import { registerConditions } from "../storefront/rules-vocabulary";

const SLUG = "shopsocket";

// The tab, registered at module evaluation: WordSocket's app mounts right after this script runs.
// It saves through WordSocket's useTask, so it needs 0.29 (registerTab alone came in 0.28).
if (window.wordsocket?.registerTab && window.wordsocket.useTask) {
  window.wordsocket.registerTab({ name: SLUG, title: __("ShopSocket", "shopsocket"), render: SettingsTab });
}

/*
 * On WordSocket 0.31 the Moments tab replays a rule over stored journeys: the
 * cart conditions must be known here too, and the journeys come from
 * ShopSocket's archive (history on), with `outcome` for the breakdown.
 */
if (window.WPS?.rules) {
  registerConditions(window.WPS.rules);
  const journeysUrl = window.shopSocketSettings?.journeysUrl;
  if (journeysUrl) {
    window.WPS.rules.history({
      outcomes: true,
      currency: window.shopSocketSettings?.currency,
      load: (options) =>
        apiFetch<{ journeys: WPSHistoryJourney[] }>({
          url: `${journeysUrl}?days=${options.days ?? 30}&limit=${options.limit ?? 500}`,
        }).then((res) => res.journeys),
    });
  }
}

function Panel() {
  const api = window.wordsocket;
  const config = window.shopSocketSettings;
  if (!api || !config) return null;
  const { ExtensionPanel, ConnectionStatusFill, useConnection, useClientState } = api;
  const connection = useConnection();
  const client = useClientState();
  const live = connection.isConnected && Boolean(client?.connected);

  return (
    <>
      <ExtensionPanel
        name={SLUG}
        title={__("ShopSocket", "shopsocket")}
        description={__("A live orders board for your team and live stock on product pages.", "shopsocket")}
        icon={<ShopSocketLogo />}
        docsUrl={config.docsUrl}
      >
        <p className="shopsocket-settings__status">
          <span className={`shopsocket-settings__dot${live ? " is-live" : ""}`} aria-hidden="true"></span>
          {live
            ? __("Live: this browser is receiving events.", "shopsocket")
            : connection.isConnected
              ? __("Site connected; this browser is not receiving events.", "shopsocket")
              : __("Connect the site on the Connect tab to go live.", "shopsocket")}
        </p>
        <p>
          <a className="button button-primary" href={config.boardUrl}>
            {__("View ShopSocket", "shopsocket")}
          </a>
        </p>
        <p className="shopsocket-settings__hint">
          {api.registerTab && api.useTask
            ? __("Storefront settings are on the ShopSocket tab.", "shopsocket")
            : __("Update WordSocket to 0.29 or later for the ShopSocket settings tab.", "shopsocket")}
        </p>
      </ExtensionPanel>
      <ConnectionStatusFill>
        <p className="shopsocket-settings__line">
          {live ? __("ShopSocket is live.", "shopsocket") : __("ShopSocket is waiting for the connection.", "shopsocket")}
        </p>
      </ConnectionStatusFill>
    </>
  );
}

registerPlugin(SLUG, { scope: "wordsocket", render: Panel });
