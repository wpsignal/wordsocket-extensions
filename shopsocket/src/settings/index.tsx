/**
 * ShopSocket on WordSocket's settings page: the card in the Extensions tab
 * (whether the site is live and where the board is) and, on WordSocket 0.28
 * and later, a ShopSocket tab holding the storefront settings.
 */
import { registerPlugin } from "@wordpress/plugins";
import { __ } from "@wordpress/i18n";
import "./settings.css";
import ShopSocketLogo from "./ShopSocketLogo";
import { SettingsTab } from "./SettingsTab";

const SLUG = "shopsocket";

// The tab, registered at module evaluation: WordSocket's app mounts right after this script runs.
window.wordsocket?.registerTab?.({ name: SLUG, title: __("ShopSocket", "shopsocket"), render: SettingsTab });

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
          {api.registerTab
            ? __("Storefront settings are on the ShopSocket tab.", "shopsocket")
            : __("Update WordSocket to 0.28 or later for the ShopSocket settings tab.", "shopsocket")}
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
