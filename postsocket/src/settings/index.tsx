/**
 * PostSocket on WordSocket's settings page: the card in the Extensions tab and the PostSocket tab.
 *
 * @since 0.1.0
 */
import { __, sprintf } from "@wordpress/i18n";
import { registerPlugin } from "@wordpress/plugins";
import { SettingsTab } from "./SettingsTab";

const SLUG = "postsocket";

// The tab is registered at module evaluation: WordSocket's app mounts right after this script runs.
if (window.wordsocket?.registerTab && window.wordsocket.useTask) {
  window.wordsocket.registerTab({ name: SLUG, title: __("PostSocket", "postsocket"), render: SettingsTab });
}

function Panel(): JSX.Element | null {
  const api = window.wordsocket;
  const config = window.postsocketSettings;
  if (!api || !config) return null;
  const { ExtensionPanel, useConnection, useClientState } = api;
  const connection = useConnection();
  const client = useClientState();
  const live = connection.isConnected && Boolean(client?.connected);

  return (
    <ExtensionPanel
      name={SLUG}
      title={__("PostSocket", "postsocket")}
      description={__("Live blogs and articles that update in every reader's browser as you publish.", "postsocket")}
      icon="megaphone"
      docsUrl="https://wpsignal.io/extensions/postsocket"
    >
      {!config.wordsocketOk && (
        <p>
          {sprintf(
            /* translators: %s: the oldest WordSocket version PostSocket runs with */
            __("PostSocket needs WordSocket %s or newer to publish live updates.", "postsocket"),
            config.minVersion,
          )}
        </p>
      )}
      <p>
        {live
          ? __("Live: this browser is receiving events.", "postsocket")
          : connection.isConnected
            ? __("Site connected; this browser is not receiving events.", "postsocket")
            : __("Connect the site on the Connect tab to go live.", "postsocket")}
      </p>
      <p>
        <a className="button button-primary" href={config.liveblogsUrl}>
          {__("Live Blogs", "postsocket")}
        </a>
      </p>
    </ExtensionPanel>
  );
}

registerPlugin(SLUG, { scope: "wordsocket", render: Panel });
