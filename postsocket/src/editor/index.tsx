/**
 * The editor panels.
 *
 * - An entry's "Live blog" panel: which live blog the entry belongs to, the pin, and the way back to the desk.
 * - A new entry opened without a live blog asks for one in a dialog first.
 * - The "Live editing" panel of a post of an enabled type: the switch that lets readers see its saved edits live, off until set.
 * - Leaving the editor gives the entry's edit lock back, which is what the desk reads "is editing" from.
 *
 * @since 0.1.0
 */
import { useEntityProp, useEntityRecords } from "@wordpress/core-data";
import { select, useSelect } from "@wordpress/data";
import { PluginDocumentSettingPanel } from "@wordpress/editor";
import { useEffect, useState } from "@wordpress/element";
import { decodeEntities } from "@wordpress/html-entities";
import { __ } from "@wordpress/i18n";
import { registerPlugin } from "@wordpress/plugins";
import {
  Button,
  ButtonLink,
  Dialog,
  Notice,
  SearchableSelectControl,
  Stack,
  SwitchControl,
  Text,
} from "@wordpress/ui";
import LiveBlogIcon from "../LiveBlogIcon";

const cfg = window.postsocketEditor;

type Meta = Record<string, unknown>;

interface LiveblogTerm {
  id: number;
  name: string;
}

function WordSocketNotice(): JSX.Element | null {
  if (!cfg || cfg.wordsocketOk) return null;
  return (
    <Notice.Root intent="warning">
      <Notice.Description>
        {__(
          "WordSocket needs an update before readers see changes live.",
          "postsocket",
        )}
      </Notice.Description>
    </Notice.Root>
  );
}

/** Give the entry's edit lock back through core's own release request as the page goes away. */
function useLockRelease(): void {
  useEffect(() => {
    const release = (): void => {
      const editor = select("core/editor");
      const lock = editor.getActivePostLock?.() as string | undefined;
      const utils = editor.getEditorSettings?.()?.postLockUtils as
        { ajaxUrl?: string; unlockNonce?: string } | undefined;
      if (
        editor.isPostLocked?.() ||
        !lock ||
        !utils?.ajaxUrl ||
        !utils.unlockNonce
      )
        return;
      const data = new FormData();
      data.append("action", "wp-remove-post-lock");
      data.append("_wpnonce", utils.unlockNonce);
      data.append("post_ID", String(editor.getCurrentPostId()));
      data.append("active_post_lock", lock);
      navigator.sendBeacon(utils.ajaxUrl, data);
    };
    window.addEventListener("pagehide", release);
    return () => window.removeEventListener("pagehide", release);
  }, []);
}

interface Choice {
  value: string;
  label: string;
}

/** Ask which live blog a new entry belongs to; closing it leaves the choice to the panel. */
function ChooseLiveblog({
  items,
  onChoose,
  onClose,
}: {
  items: Choice[];
  onChoose: (id: number) => void;
  onClose: () => void;
}): JSX.Element {
  const [choice, setChoice] = useState<Choice | null>(null);
  return (
    <Dialog.Root
      open
      onOpenChange={(open: boolean) => {
        if (!open) onClose();
      }}
    >
      <Dialog.Popup size="small">
        <Dialog.Header>
          <Dialog.Title>
            {__("Which live blog is this entry for?", "postsocket")}
          </Dialog.Title>
          <Dialog.CloseIconButton />
        </Dialog.Header>
        <Dialog.Content>
          {items.length ? (
            <SearchableSelectControl
              label={__("Live blog", "postsocket")}
              description={__(
                "An entry belongs to one live blog, and cannot be published without it.",
                "postsocket",
              )}
              items={items}
              value={choice}
              onValueChange={(item) => setChoice(item)}
              placeholder={__("Choose a live blog", "postsocket")}
            />
          ) : (
            <Text variant="body-md">
              {__(
                "There are no live blogs yet. Create one on the desk, then add entries to it.",
                "postsocket",
              )}
            </Text>
          )}
        </Dialog.Content>
        <Dialog.Footer>
          <Dialog.Action variant="outline">
            {__("Not now", "postsocket")}
          </Dialog.Action>
          {items.length ? (
            <Button
              disabled={!choice}
              onClick={() => choice && onChoose(Number(choice.value))}
            >
              {__("Continue", "postsocket")}
            </Button>
          ) : (
            <ButtonLink href={cfg!.deskUrl}>
              {__("Open the desk", "postsocket")}
            </ButtonLink>
          )}
        </Dialog.Footer>
      </Dialog.Popup>
    </Dialog.Root>
  );
}

function EntryPanel(): JSX.Element | null {
  if (!cfg) return null;
  useLockRelease();
  const [liveblogs, setLiveblogs] = useEntityProp<number[] | undefined>(
    "postType",
    cfg.entryType,
    cfg.restBase,
  );
  const [meta, setMeta] = useEntityProp<Meta>(
    "postType",
    cfg.entryType,
    "meta",
  );
  const { records, hasResolved } = useEntityRecords<LiveblogTerm>(
    "taxonomy",
    cfg.taxonomy,
    {
      per_page: 100,
      orderby: "id",
      order: "desc",
      hide_empty: false,
      _fields: "id,name",
    },
  );

  const isNew = useSelect(
    (select) => Boolean(select("core/editor").isEditedPostNew?.()),
    [],
  );
  const [asked, setAsked] = useState(false);

  const liveblog = liveblogs?.[0] ?? 0;
  useEffect(() => {
    if (!liveblog && cfg.preselected) setLiveblogs([cfg.preselected]);
  }, [liveblog]);

  const items = (records ?? []).map((term) => ({
    value: String(term.id),
    label: decodeEntities(term.name),
  }));
  const selected =
    items.find((item) => item.value === String(liveblog)) ?? null;
  const pinned = Boolean(meta?.[cfg.meta.pinned]);

  return (
    <>
      {isNew && hasResolved && !liveblog && !cfg.preselected && !asked && (
        <ChooseLiveblog
          items={items}
          onChoose={(id) => {
            setLiveblogs([id]);
            setAsked(true);
          }}
          onClose={() => setAsked(true)}
        />
      )}
      <PluginDocumentSettingPanel
        name="postsocket-entry"
        title={__("Live blog", "postsocket")}
      >
        <Stack direction="column" gap="md">
          <WordSocketNotice />
          <SearchableSelectControl
            label={__("Belongs to", "postsocket")}
            description={
              !liveblog
                ? __("Choose the live blog before publishing.", "postsocket")
                : undefined
            }
            items={items}
            value={selected}
            onValueChange={(item) =>
              setLiveblogs(item ? [Number(item.value)] : [])
            }
            placeholder={
              hasResolved
                ? __("Choose a live blog", "postsocket")
                : __("Loading…", "postsocket")
            }
            emptyContent={__(
              "No live blogs yet. Create one on the desk.",
              "postsocket",
            )}
          />
          <SwitchControl
            label={__("Pin to the top", "postsocket")}
            description={__(
              "A pinned entry stays above the newest ones.",
              "postsocket",
            )}
            checked={pinned}
            onCheckedChange={(on) =>
              setMeta({ ...meta, [cfg.meta.pinned]: on })
            }
          />
          <ButtonLink
            variant="outline"
            href={
              liveblog ? `${cfg.deskUrl}&liveblog=${liveblog}` : cfg.deskUrl
            }
          >
            {__("Back to the desk", "postsocket")}
          </ButtonLink>
        </Stack>
      </PluginDocumentSettingPanel>
    </>
  );
}

function LivePanel({ postType }: { postType: string }): JSX.Element {
  const [live, setLive] = useEntityProp<boolean | undefined>(
    "postType",
    postType,
    "postsocket_live",
  );
  return (
    <PluginDocumentSettingPanel
      name="postsocket-live"
      title={__("Live editing", "postsocket")}
    >
      <Stack direction="column" gap="md">
        <WordSocketNotice />
        <SwitchControl
          label={__("Update this post live", "postsocket")}
          description={__(
            "Readers see your saved edits land on the page without a reload.",
            "postsocket",
          )}
          checked={Boolean(live)}
          onCheckedChange={(on) => setLive(on)}
        />
      </Stack>
    </PluginDocumentSettingPanel>
  );
}

function Panels(): JSX.Element | null {
  const postType = useSelect(
    (select) => select("core/editor").getCurrentPostType() as string,
    [],
  );
  if (!cfg) return null;
  if (postType === cfg.entryType) return <EntryPanel />;
  return cfg.livingTypes.includes(postType) ? (
    <LivePanel postType={postType} />
  ) : null;
}

registerPlugin("postsocket", { render: Panels, icon: <LiveBlogIcon /> });
