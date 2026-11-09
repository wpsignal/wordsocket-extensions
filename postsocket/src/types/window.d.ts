declare global {
  interface Window {
    WPS?: WPSApi;
    /** Printed by inc/admin.php on the block editor of both post types. */
    postsocketEditor?: PostSocketEditorConfig;
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
  liveblogType: string;
  entryType: string;
  preselected: number;
  addEntryUrl: string;
  entriesUrl: string;
  meta: { state: string; started: string; ended: string; pinned: string };
  wordsocketOk: boolean;
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
