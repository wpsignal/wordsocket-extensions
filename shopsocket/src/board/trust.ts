/**
 * What the board takes from an event. Any browser with a token may publish on
 * the public channels, so an event counts only when it arrived on the channel
 * PHP or the relay uses for it, and a link is only followed into wp-admin.
 */

/** Whether `channel` is `expected`, allowing the `site:{id}:` spelling. */
export function onChannel(channel: string, expected: string): boolean {
  return channel === expected || channel.endsWith(`:${expected}`);
}

/** `url` when it points into this site's admin, else null. */
export function adminUrlOrNull(url: string, adminUrl: string): string | null {
  try {
    const target = new URL(url, window.location.origin);
    const admin = new URL(adminUrl, window.location.origin);
    return target.origin === admin.origin && target.pathname.startsWith(admin.pathname) ? target.href : null;
  } catch {
    return null;
  }
}
