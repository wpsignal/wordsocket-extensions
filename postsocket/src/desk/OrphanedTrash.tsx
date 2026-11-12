/**
 * The trash of entries whose live blog was deleted: restore them into a live blog as drafts, or delete them for good.
 *
 * @since 0.1.0
 */
import { DataViews, type Action, type Field, type View } from "@wordpress/dataviews";
import { useEffect, useMemo, useState } from "@wordpress/element";
import { decodeEntities } from "@wordpress/html-entities";
import { __, _n, sprintf } from "@wordpress/i18n";
import { AlertDialog, Button, Dialog, EmptyState, Notice, SearchableSelectControl, Stack, Text } from "@wordpress/ui";
import { deleteEntry, entriesPath, fetchPage, liveblogsPath, orphanedQuery, restoreEntry, usePage, type Entry, type Liveblog } from "./api";
import { formatTime } from "./format";

interface Choice {
  value: string;
  label: string;
}

const summaryOf = (entry: Entry): string => decodeEntities(entry.postsocket.summary) || __("(empty)", "postsocket");

/** Ask which live blog the entries go back into, then restore them there. */
function RestoreDialog({ items, onDone, onClose }: { items: Entry[]; onDone: () => void; onClose: () => void }): JSX.Element {
  const [liveblogs, setLiveblogs] = useState<Choice[] | null>(null);
  const [choice, setChoice] = useState<Choice | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    void fetchPage<Liveblog>(liveblogsPath, { hide_empty: false, per_page: 100, orderby: "id", order: "desc", _fields: "id,name" })
      .then((page) => setLiveblogs(page.items.map((term) => ({ value: String(term.id), label: decodeEntities(term.name) }))))
      .catch(() => setLiveblogs([]));
  }, []);

  const restore = async (): Promise<void> => {
    if (!choice) return;
    setBusy(true);
    setError(null);
    try {
      await Promise.all(items.map((item) => restoreEntry(item.id, Number(choice.value))));
      onDone();
    } catch (err) {
      setError((err as { message?: string })?.message ?? __("That did not work.", "postsocket"));
      setBusy(false);
    }
  };

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
            {sprintf(
              /* translators: %d: number of entries */
              _n("Restore %d entry", "Restore %d entries", items.length, "postsocket"),
              items.length,
            )}
          </Dialog.Title>
          <Dialog.CloseIconButton />
        </Dialog.Header>
        <Dialog.Content>
          <Stack direction="column" gap="md">
            {error && (
              <Notice.Root intent="error">
                <Notice.Description>{error}</Notice.Description>
              </Notice.Root>
            )}
            {liveblogs && !liveblogs.length ? (
              <Text variant="body-md">{__("There is no live blog to restore into. Create one first.", "postsocket")}</Text>
            ) : (
              <SearchableSelectControl
                label={__("Restore into", "postsocket")}
                description={__("The live blog these entries belonged to was deleted. They come back as drafts in the one you choose.", "postsocket")}
                items={liveblogs ?? []}
                value={choice}
                onValueChange={(item) => setChoice(item)}
                placeholder={liveblogs ? __("Choose a live blog", "postsocket") : __("Loading…", "postsocket")}
              />
            )}
          </Stack>
        </Dialog.Content>
        <Dialog.Footer>
          <Dialog.Action variant="outline">{__("Cancel", "postsocket")}</Dialog.Action>
          <Button disabled={!choice} loading={busy} onClick={() => void restore()}>
            {__("Restore", "postsocket")}
          </Button>
        </Dialog.Footer>
      </Dialog.Popup>
    </Dialog.Root>
  );
}

/**
 * The trashed entries that belong to no live blog.
 *
 * @since 0.1.0
 */
export function OrphanedTrash({ onChange }: { onChange: () => void }): JSX.Element {
  const [view, setView] = useState<View>({ type: "table", page: 1, perPage: 20, search: "", fields: ["author", "date"], titleField: "summary" });
  const [restoring, setRestoring] = useState<Entry[] | null>(null);
  const [deleting, setDeleting] = useState<Entry[] | null>(null);
  const entries = usePage<Entry>(entriesPath, {
    ...orphanedQuery,
    page: view.page,
    per_page: view.perPage,
    search: view.search || undefined,
    orderby: "date",
    order: "desc",
    _fields: "id,status,date_gmt,link,meta,postsocket",
  });
  const changed = (): void => {
    entries.reload();
    onChange();
  };

  const fields = useMemo<Field<Entry>[]>(
    () => [
      { id: "summary", label: __("Entry", "postsocket"), enableHiding: false, enableSorting: false, render: ({ item }) => <Text>{summaryOf(item)}</Text> },
      { id: "author", label: __("Author", "postsocket"), enableSorting: false, render: ({ item }) => <Text variant="body-sm">{item.postsocket.author}</Text> },
      { id: "date", label: __("Date", "postsocket"), enableSorting: false, render: ({ item }) => <Text variant="body-sm">{formatTime(item.date_gmt)}</Text> },
    ],
    [],
  );
  const actions = useMemo<Action<Entry>[]>(
    () => [
      { id: "restore", label: __("Restore", "postsocket"), isPrimary: true, supportsBulk: true, callback: (items) => setRestoring(items) },
      { id: "delete", label: __("Delete permanently", "postsocket"), supportsBulk: true, callback: (items) => setDeleting(items) },
    ],
    [],
  );

  return (
    <>
      <DataViews<Entry>
        view={view}
        onChangeView={setView}
        fields={fields}
        actions={actions}
        data={entries.items}
        isLoading={entries.loading}
        paginationInfo={{ totalItems: entries.total, totalPages: entries.totalPages }}
        defaultLayouts={{ table: {}, list: {} }}
        getItemId={(item) => String(item.id)}
        empty={
          <EmptyState.Root>
            <EmptyState.Title>{__("The trash is empty", "postsocket")}</EmptyState.Title>
            <EmptyState.Description>{__("Entries of a deleted live blog are kept here until they are restored or deleted.", "postsocket")}</EmptyState.Description>
          </EmptyState.Root>
        }
      />
      {restoring && (
        <RestoreDialog
          items={restoring}
          onClose={() => setRestoring(null)}
          onDone={() => {
            setRestoring(null);
            changed();
          }}
        />
      )}
      {deleting && (
        <AlertDialog.Root
          open
          onOpenChange={(isOpen) => {
            if (!isOpen) setDeleting(null);
          }}
          onConfirm={async () => {
            await Promise.all(deleting.map((item) => deleteEntry(item.id)));
            setDeleting(null);
            changed();
            return { close: true };
          }}
        >
          <AlertDialog.Popup
            intent="irreversible"
            title={sprintf(
              /* translators: %d: number of entries */
              _n("Delete %d entry permanently?", "Delete %d entries permanently?", deleting.length, "postsocket"),
              deleting.length,
            )}
            description={__("This cannot be undone.", "postsocket")}
            confirmButtonText={__("Delete permanently", "postsocket")}
          />
        </AlertDialog.Root>
      )}
    </>
  );
}
