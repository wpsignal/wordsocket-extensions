/**
 * Refresh a desk view when one of its channels carries an event, or when the tab comes back into view.
 *
 * @since 0.1.0
 */
import { useEffect } from "@wordpress/element";
import { onRelay, subscribe } from "../reader/relay";

const EVENTS = ["postsocket.entry.updated", "postsocket.entry.removed", "postsocket.liveblog.state", "postsocket.desk.changed"];

/**
 * Call `refresh` on every event of the given channels and whenever the tab is shown again.
 *
 * @since 0.1.0
 */
export function useLiveRefresh(channels: string[], refresh: () => void): void {
  const key = channels.join(",");
  useEffect(() => {
    const names = key ? key.split(",") : [];
    const offs: Array<() => void> = [];
    for (const channel of names) {
      void subscribe(channel);
      for (const event of EVENTS) offs.push(onRelay(event, channel, refresh));
    }
    const onVisible = (): void => {
      if (document.visibilityState === "visible") refresh();
    };
    document.addEventListener("visibilitychange", onVisible);
    return () => {
      offs.forEach((off) => off());
      document.removeEventListener("visibilitychange", onVisible);
      if (names.length) window.WPS?.unsubscribe(names);
    };
  }, [key, refresh]);
}
