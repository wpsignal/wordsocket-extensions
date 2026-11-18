/**
 * Small read-outs the desk's views share.
 *
 * @since 0.1.0
 */
import type { Liveblog } from "./api";

const cfg = window.postsocketDesk!;

const TIME = new Intl.DateTimeFormat(undefined, { dateStyle: "medium", timeStyle: "short" });

/**
 * A GMT timestamp from the REST API, in the editor's local time.
 *
 * @since 0.1.0
 */
export function formatTime(gmt: string): string {
  const date = new Date(/[zZ]|[+-]\d\d:?\d\d$/.test(gmt) ? gmt : `${gmt}Z`);
  return Number.isNaN(date.getTime()) ? gmt : TIME.format(date);
}

/**
 * Whether a live blog's coverage has ended.
 *
 * @since 0.1.0
 */
export function isEnded(liveblog: Liveblog): boolean {
  return liveblog.meta?.[cfg.meta.state] === "ended";
}
