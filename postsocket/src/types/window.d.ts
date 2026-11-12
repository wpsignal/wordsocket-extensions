declare global {
  interface Window {
    WPS?: WPSApi;
    /** Printed by inc/admin.php on the editor of an entry and of the enabled post types. */
    postsocketEditor?: PostSocketEditorConfig;
    /** Printed by inc/blocks.php with the Live Feed block's editor script. */
    postsocketBlock?: PostSocketBlockConfig;
    /** Printed by inc/desk.php on the Live Desk. */
    postsocketDesk?: PostSocketDeskConfig;
    /** Printed by inc/admin.php on WordSocket's settings screen. */
    postsocketSettings?: PostSocketSettingsConfig;
    /** WordSocket's extension API, published by its settings bundle. */
    wordsocket?: WordSocketExtensionsApi;
  }
}

/**
 * What `inc/admin.php` prints for the editor bundle.
 *
 * @since 0.1.0
 */
interface PostSocketEditorConfig {
  taxonomy: string;
  restBase: string;
  entryType: string;
  livingTypes: string[];
  preselected: number;
  deskUrl: string;
  meta: { state: string; pinned: string };
  wordsocketOk: boolean;
}

/**
 * What `inc/blocks.php` prints for the Live Feed block's editor script.
 *
 * @since 0.1.0
 */
interface PostSocketBlockConfig {
  taxonomy: string;
  deskUrl: string;
  canManage: boolean;
  meta: { state: string };
}

/**
 * What `inc/desk.php` prints for the Live Desk.
 *
 * @since 0.1.0
 */
interface PostSocketDeskConfig {
  taxonomy: string;
  restBase: string;
  entryType: string;
  deskUrl: string;
  addEntryUrl: string;
  createPageUrl: string;
  editUrl: string;
  meta: { state: string; started: string; ended: string; home: string; pinned: string };
  channel: string;
  deskChannel: string;
  restNamespace: string;
  canManage: boolean;
  wordsocketOk: boolean;
  minVersion: string;
}

/**
 * What `inc/admin.php` prints for the settings bundle.
 *
 * @since 0.1.0
 */
interface PostSocketSettingsConfig {
  version: string;
  settingsUrl: string;
  liveblogsUrl: string;
  wordsocketOk: boolean;
  minVersion: string;
}

export {};
