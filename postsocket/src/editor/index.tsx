/**
 * The document panels: "Live coverage" on a live blog, "Live blog" on an entry.
 *
 * @since 0.1.0
 */
import { Button, ComboboxControl, Notice, ToggleControl } from "@wordpress/components";
import { useEntityProp, useEntityRecords } from "@wordpress/core-data";
import { useSelect } from "@wordpress/data";
import { PluginDocumentSettingPanel } from "@wordpress/editor";
import { useEffect } from "@wordpress/element";
import { __, _n, sprintf } from "@wordpress/i18n";
import { registerPlugin } from "@wordpress/plugins";

const cfg = window.postsocketEditor;

type Meta = Record<string, unknown>;

interface Liveblog {
  id: number;
  title: { rendered: string };
  status: string;
}

function decode(html: string): string {
  const el = document.createElement("textarea");
  el.innerHTML = html;
  return el.value;
}

function WordSocketNotice(): JSX.Element | null {
  if (!cfg || cfg.wordsocketOk) return null;
  return (
    <Notice status="warning" isDismissible={false}>
      {__("WordSocket needs an update before readers see changes live.", "postsocket")}
    </Notice>
  );
}

function LiveblogPanel(): JSX.Element | null {
  if (!cfg) return null;
  const postId = useSelect((select) => select("core/editor").getCurrentPostId() as number, []);
  const status = useSelect((select) => select("core/editor").getEditedPostAttribute("status") as string, []);
  const [meta, setMeta] = useEntityProp<Meta>("postType", cfg.liveblogType, "meta");
  const ended = meta?.[cfg.meta.state] === "ended";
  const saved = status !== "auto-draft";
  const { totalItems, hasResolved } = useEntityRecords("postType", cfg.entryType, { liveblog: postId, per_page: 1, status: "publish" }, { enabled: saved });
  const count = totalItems ?? 0;

  const setEnded = (value: boolean): void => {
    const now = Math.floor(Date.now() / 1000);
    setMeta({
      ...meta,
      [cfg.meta.state]: value ? "ended" : "live",
      [cfg.meta.ended]: value ? now : 0,
      [cfg.meta.started]: Number(meta?.[cfg.meta.started] ?? 0) || now,
    });
  };

  return (
    <PluginDocumentSettingPanel name="postsocket-liveblog" title={__("Live coverage", "postsocket")} icon="megaphone">
      <WordSocketNotice />
      <ToggleControl
        __nextHasNoMarginBottom
        label={__("Coverage has ended", "postsocket")}
        help={ended ? __("Readers see the feed marked as ended.", "postsocket") : __("Readers see a live badge and new entries as you publish them.", "postsocket")}
        checked={ended}
        onChange={setEnded}
      />
      <p>
        {!saved
          ? __("Save the live blog to start adding entries.", "postsocket")
          : hasResolved
            ? sprintf(
                /* translators: %d: number of published entries on this live blog */
                _n("%d published entry", "%d published entries", count, "postsocket"),
                count,
              )
            : __("Counting entries…", "postsocket")}
      </p>
      <div style={{ display: "flex", gap: "8px", flexWrap: "wrap" }}>
        <Button variant="primary" href={`${cfg.addEntryUrl}&liveblog=${postId}`} disabled={!saved}>
          {__("Add entry", "postsocket")}
        </Button>
        <Button variant="secondary" href={`${cfg.entriesUrl}&liveblog=${postId}`} disabled={!saved}>
          {__("All entries", "postsocket")}
        </Button>
      </div>
    </PluginDocumentSettingPanel>
  );
}

function EntryPanel(): JSX.Element | null {
  if (!cfg) return null;
  const [liveblog, setLiveblog] = useEntityProp<number>("postType", cfg.entryType, "liveblog");
  const [meta, setMeta] = useEntityProp<Meta>("postType", cfg.entryType, "meta");
  const { records, hasResolved } = useEntityRecords<Liveblog>("postType", cfg.liveblogType, {
    per_page: 50,
    status: ["publish", "draft", "future", "private"],
    orderby: "date",
    order: "desc",
    _fields: "id,title,status",
  });

  useEffect(() => {
    if (!liveblog && cfg.preselected) setLiveblog(cfg.preselected);
  }, [liveblog]);

  const options = (records ?? []).map((blog) => ({
    value: String(blog.id),
    label: blog.status === "publish" ? decode(blog.title.rendered) : `${decode(blog.title.rendered)} (${blog.status})`,
  }));
  const pinned = Boolean(meta?.[cfg.meta.pinned]);

  return (
    <PluginDocumentSettingPanel name="postsocket-entry" title={__("Live blog", "postsocket")} icon="megaphone">
      <WordSocketNotice />
      <ComboboxControl
        __nextHasNoMarginBottom
        label={__("Belongs to", "postsocket")}
        help={!liveblog ? __("Choose the live blog before publishing.", "postsocket") : undefined}
        value={liveblog ? String(liveblog) : ""}
        options={options}
        onChange={(value) => setLiveblog(value ? Number(value) : 0)}
        placeholder={hasResolved ? __("Choose a live blog", "postsocket") : __("Loading…", "postsocket")}
      />
      <ToggleControl
        __nextHasNoMarginBottom
        label={__("Pin to the top", "postsocket")}
        help={__("A pinned entry stays above the newest ones.", "postsocket")}
        checked={pinned}
        onChange={(value) => setMeta({ ...meta, [cfg.meta.pinned]: value })}
      />
    </PluginDocumentSettingPanel>
  );
}

function Panels(): JSX.Element | null {
  const postType = useSelect((select) => select("core/editor").getCurrentPostType() as string, []);
  if (!cfg) return null;
  if (postType === cfg.liveblogType) return <LiveblogPanel />;
  if (postType === cfg.entryType) return <EntryPanel />;
  return null;
}

registerPlugin("postsocket", { render: Panels, icon: "megaphone" });
