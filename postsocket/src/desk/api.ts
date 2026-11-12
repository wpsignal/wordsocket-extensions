/**
 * The desk's REST calls: paged lists with their totals, and the writes the desk makes.
 *
 * @since 0.1.0
 */
import apiFetch from "@wordpress/api-fetch";
import { useCallback, useEffect, useMemo, useRef, useState } from "@wordpress/element";
import { addQueryArgs } from "@wordpress/url";

const cfg = window.postsocketDesk!;

/**
 * A live blog as the desk lists it.
 *
 * @since 0.1.0
 */
export interface Liveblog {
  id: number;
  name: string;
  count: number;
  meta: Record<string, unknown>;
  postsocket: {
    home: { id: number; title: string; link: string } | null;
    latest: string | null;
  };
}

/**
 * An entry as the desk lists it.
 *
 * @since 0.1.0
 */
export interface Entry {
  id: number;
  status: string;
  date_gmt: string;
  link: string;
  meta: Record<string, unknown>;
  postsocket: { author: string; summary: string; editing: { name: string; expires_in: number } | null };
}

/**
 * A page of a collection.
 *
 * @since 0.1.0
 */
export interface Page<T> {
  items: T[];
  total: number;
  totalPages: number;
}

/**
 * The live blogs collection path.
 *
 * @since 0.1.0
 */
export const liveblogsPath = `/wp/v2/${cfg.restBase}`;

/**
 * The entries collection path.
 *
 * @since 0.1.0
 */
export const entriesPath = `/wp/v2/${cfg.entryType}`;

/**
 * Fetch one page of a collection with its totals.
 *
 * @since 0.1.0
 */
export async function fetchPage<T>(path: string, query: Record<string, unknown>): Promise<Page<T>> {
  const response = (await apiFetch({ path: addQueryArgs(path, query), parse: false })) as Response;
  return {
    items: (await response.json()) as T[],
    total: Number(response.headers.get("X-WP-Total") ?? 0),
    totalPages: Number(response.headers.get("X-WP-TotalPages") ?? 0),
  };
}

/**
 * Wrap an async task so overlapping calls run it at most once more after the one in flight.
 *
 * @since 0.1.0
 */
export function coalesce(task: () => Promise<unknown>): () => void {
  let running = false;
  let again = false;
  const run = (): void => {
    if (running) {
      again = true;
      return;
    }
    running = true;
    void task().finally(() => {
      running = false;
      if (again) {
        again = false;
        run();
      }
    });
  };
  return run;
}

/**
 * A collection page that refetches when its query changes and on `reload()`.
 *
 * - Reloads that overlap run once more after the one in flight; a stale answer never replaces a newer one.
 * - `loading` is true only until the first answer for a query, so a refresh keeps the rows on screen.
 * - `patch()` and `remove()` change rows in place, for actions shown before the server answers.
 *
 * @since 0.1.0
 */
export function usePage<T extends { id: number }>(
  path: string,
  query: Record<string, unknown>,
): Page<T> & { loading: boolean; error: string | null; reload: () => void; patch: (id: number, change: Partial<T>) => void; remove: (id: number) => void } {
  const [page, setPage] = useState<Page<T>>({ items: [], total: 0, totalPages: 0 });
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const key = JSON.stringify(query);
  const current = useRef({ path, query, key });
  current.current = { path, query, key };
  const latest = useRef(0);

  const fetchNow = useCallback(async (): Promise<void> => {
    const { path: p, query: q, key: k } = current.current;
    const ticket = ++latest.current;
    try {
      const next = await fetchPage<T>(p, q);
      if (ticket !== latest.current || k !== current.current.key) return;
      setPage(next);
      setError(null);
    } catch (err) {
      if (ticket === latest.current) setError((err as { message?: string })?.message ?? "error");
    } finally {
      if (ticket === latest.current) setLoading(false);
    }
  }, []);

  const reload = useMemo(() => coalesce(fetchNow), [fetchNow]);

  useEffect(() => {
    setLoading(true);
    reload();
  }, [path, key, reload]);

  const patch = useCallback((id: number, change: Partial<T>) => {
    setPage((prev) => ({ ...prev, items: prev.items.map((item) => (item.id === id ? { ...item, ...change } : item)) }));
  }, []);
  const remove = useCallback((id: number) => {
    setPage((prev) => ({ ...prev, items: prev.items.filter((item) => item.id !== id), total: Math.max(0, prev.total - 1) }));
  }, []);

  return { ...page, loading, error, reload, patch, remove };
}

/**
 * Create a live blog and answer it.
 *
 * @since 0.1.0
 */
export function createLiveblog(name: string): Promise<Liveblog> {
  return apiFetch<Liveblog>({ path: liveblogsPath, method: "POST", data: { name } });
}

/**
 * Rename a live blog.
 *
 * @since 0.1.0
 */
export function renameLiveblog(id: number, name: string): Promise<Liveblog> {
  return apiFetch<Liveblog>({ path: `${liveblogsPath}/${id}`, method: "POST", data: { name } });
}

/**
 * Delete a live blog; its entries are trashed first.
 *
 * @since 0.1.0
 */
export function deleteLiveblog(id: number): Promise<unknown> {
  return apiFetch({ path: addQueryArgs(`${liveblogsPath}/${id}`, { force: true }), method: "DELETE" });
}

/**
 * Mark a live blog live or ended, stamping when it started and ended.
 *
 * @since 0.1.0
 */
export function setEnded(liveblog: Liveblog, ended: boolean): Promise<Liveblog> {
  const now = Math.floor(Date.now() / 1000);
  return apiFetch<Liveblog>({
    path: `${liveblogsPath}/${liveblog.id}`,
    method: "POST",
    data: {
      meta: {
        [cfg.meta.state]: ended ? "ended" : "live",
        [cfg.meta.ended]: ended ? now : 0,
        [cfg.meta.started]: Number(liveblog.meta[cfg.meta.started] ?? 0) || now,
      },
    },
  });
}

/**
 * Update an entry's fields.
 *
 * @since 0.1.0
 */
export function updateEntry(id: number, data: Record<string, unknown>): Promise<Entry> {
  return apiFetch<Entry>({ path: `${entriesPath}/${id}`, method: "POST", data });
}

/**
 * Move an entry to the trash.
 *
 * @since 0.1.0
 */
export function trashEntry(id: number): Promise<unknown> {
  return apiFetch({ path: `${entriesPath}/${id}`, method: "DELETE" });
}

/**
 * Restore a trashed entry to the status it was trashed in; one with no live blog comes back as a draft in `liveblog`.
 *
 * @since 0.1.0
 */
export function restoreEntry(id: number, liveblog = 0): Promise<unknown> {
  return apiFetch({ path: `/${cfg.restNamespace}/entry/${id}/restore`, method: "POST", data: liveblog ? { liveblog } : undefined });
}

/**
 * The query of the trashed entries whose live blog was deleted.
 *
 * @since 0.1.0
 */
export const orphanedQuery = { context: "edit", status: "trash", postsocket_orphaned: true };

/**
 * Delete a trashed entry for good.
 *
 * @since 0.1.0
 */
export function deleteEntry(id: number): Promise<unknown> {
  return apiFetch({ path: addQueryArgs(`${entriesPath}/${id}`, { force: true }), method: "DELETE" });
}
