/**
 * The desk's front page: every live blog, its state, entries and home, with create, rename and delete.
 *
 * @since 0.1.0
 */
import { DataViews, type Action, type Field, type View } from "@wordpress/dataviews";
import { useCallback, useMemo, useState } from "@wordpress/element";
import { decodeEntities } from "@wordpress/html-entities";
import { __, _n, sprintf } from "@wordpress/i18n";
import { AlertDialog, Badge, Button, ButtonLink, Card, EmptyState, Link, Stack, Tabs, Text } from "@wordpress/ui";
import { createLiveblog, deleteLiveblog, entriesPath, liveblogsPath, orphanedQuery, renameLiveblog, usePage, type Entry, type Liveblog } from "./api";
import { OrphanedTrash } from "./OrphanedTrash";
import { NameDialog } from "./NameDialog";
import { useLiveRefresh } from "./useLiveRefresh";
import { formatTime, isEnded } from "./format";

const cfg = window.postsocketDesk!;

const SORTABLE: Record<string, string> = { name: "name", count: "count" };

/**
 * The list of live blogs, with a Trash tab for the entries of deleted live blogs while there are any.
 *
 * @since 0.1.0
 */
export function LiveblogsView({ open }: { open: (id: number) => void }): JSX.Element {
  const [view, setView] = useState<View>({
    type: "table",
    page: 1,
    perPage: 20,
    search: "",
    sort: { field: "id", direction: "desc" },
    fields: ["state", "count", "home", "latest"],
    titleField: "name",
  });
  const [creating, setCreating] = useState(false);
  const [renaming, setRenaming] = useState<Liveblog | null>(null);
  const [deleting, setDeleting] = useState<Liveblog | null>(null);

  const query = {
    context: "edit",
    hide_empty: false,
    page: view.page,
    per_page: view.perPage,
    search: view.search || undefined,
    orderby: SORTABLE[view.sort?.field ?? ""] ?? "id",
    order: view.sort?.direction ?? "desc",
    _fields: "id,name,count,meta,postsocket",
  };
  const page = usePage<Liveblog>(liveblogsPath, query);
  const orphaned = usePage<Entry>(entriesPath, { ...orphanedQuery, per_page: 1, _fields: "id" });
  const [tab, setTab] = useState<"liveblogs" | "trash">("liveblogs");
  const refresh = useCallback(() => {
    page.reload();
    orphaned.reload();
  }, [page.reload, orphaned.reload]);
  useLiveRefresh([], refresh);

  const fields = useMemo<Field<Liveblog>[]>(
    () => [
      {
        id: "name",
        label: __("Live blog", "postsocket"),
        enableHiding: false,
        enableSorting: true,
        getValue: ({ item }) => decodeEntities(item.name),
        render: ({ item }) => (
          <Link href={`${cfg.deskUrl}&liveblog=${item.id}`} onClick={(event) => (event.preventDefault(), open(item.id))}>
            {decodeEntities(item.name)}
          </Link>
        ),
      },
      {
        id: "state",
        label: __("State", "postsocket"),
        enableSorting: false,
        render: ({ item }) =>
          isEnded(item) ? <Badge intent="low">{__("Ended", "postsocket")}</Badge> : <Badge intent="stable">{__("Live", "postsocket")}</Badge>,
      },
      {
        id: "count",
        label: __("Entries", "postsocket"),
        type: "integer",
        enableSorting: true,
        getValue: ({ item }) => item.count,
      },
      {
        id: "home",
        label: __("Shown on", "postsocket"),
        enableSorting: false,
        render: ({ item }) =>
          item.postsocket.home ? (
            <Link href={item.postsocket.home.link} openInNewTab>
              {decodeEntities(item.postsocket.home.title) || __("(no title)", "postsocket")}
            </Link>
          ) : (
            <ButtonLink variant="minimal" size="compact" href={`${cfg.createPageUrl}&postsocket_liveblog=${item.id}`}>
              {__("Create page", "postsocket")}
            </ButtonLink>
          ),
      },
      {
        id: "latest",
        label: __("Newest entry", "postsocket"),
        enableSorting: false,
        render: ({ item }) => <Text variant="body-sm">{item.postsocket.latest ? formatTime(item.postsocket.latest) : __("None yet", "postsocket")}</Text>,
      },
    ],
    [open],
  );

  const actions = useMemo<Action<Liveblog>[]>(
    () => [
      { id: "open", label: __("Open the desk", "postsocket"), isPrimary: true, callback: ([item]) => open(item.id) },
      ...(cfg.canManage
        ? [
            { id: "rename", label: __("Rename", "postsocket"), callback: ([item]: Liveblog[]) => setRenaming(item) },
            { id: "delete", label: __("Delete", "postsocket"), callback: ([item]: Liveblog[]) => setDeleting(item) },
          ]
        : []),
    ],
    [open],
  );

  return (
    <Stack direction="column" gap="lg">
      {cfg.canManage && (
        <Stack direction="row" justify="end" align="center">
          <Button onClick={() => setCreating(true)}>{__("New live blog", "postsocket")}</Button>
        </Stack>
      )}

      {(orphaned.total > 0 || tab === "trash") && (
        <Tabs.Root value={tab} onValueChange={(value) => setTab(value as "liveblogs" | "trash")}>
          <Tabs.List variant="minimal" aria-label={__("Live blogs and the trash", "postsocket")}>
            <Tabs.Tab value="liveblogs">{__("Live blogs", "postsocket")}</Tabs.Tab>
            <Tabs.Tab value="trash">
              {sprintf(
                /* translators: %d: number of trashed entries */
                __("Trash (%d)", "postsocket"),
                orphaned.total,
              )}
            </Tabs.Tab>
          </Tabs.List>
        </Tabs.Root>
      )}

<Card.Root>
  <Card.Content>
      {tab === "trash" ? (
        <OrphanedTrash onChange={refresh} />
      ) : (
      <DataViews<Liveblog>
        view={view}
        onChangeView={setView}
        fields={fields}
        actions={actions}
        data={page.items}
        isLoading={page.loading}
        paginationInfo={{ totalItems: page.total, totalPages: page.totalPages }}
        defaultLayouts={{ table: {}, list: {} }}
        getItemId={(item) => String(item.id)}
        onClickItem={(item) => open(item.id)}
        isItemClickable={() => true}
        empty={
          <EmptyState.Root>
            <EmptyState.Title>{__("No live blogs yet", "postsocket")}</EmptyState.Title>
            <EmptyState.Description>
              {__("A live blog is a stream of entries you publish as things happen. Create one, then place it on a post.", "postsocket")}
            </EmptyState.Description>
          </EmptyState.Root>
        }
      />
      )}
  </Card.Content>
</Card.Root>

      {creating && (
        <NameDialog
          title={__("New live blog", "postsocket")}
          action={__("Create", "postsocket")}
          onClose={() => setCreating(false)}
          onSubmit={async (name) => {
            const term = await createLiveblog(name);
            setCreating(false);
            open(term.id);
          }}
        />
      )}
      {renaming && (
        <NameDialog
          title={__("Rename live blog", "postsocket")}
          action={__("Rename", "postsocket")}
          initial={decodeEntities(renaming.name)}
          onClose={() => setRenaming(null)}
          onSubmit={async (name) => {
            await renameLiveblog(renaming.id, name);
            setRenaming(null);
            page.reload();
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
            await deleteLiveblog(deleting.id);
            setDeleting(null);
            refresh();
            return { close: true };
          }}
        >
          <AlertDialog.Popup
            intent="irreversible"
            title={sprintf(
              /* translators: %s: live blog name */
              __("Delete “%s”?", "postsocket"),
              decodeEntities(deleting.name),
            )}
            description={sprintf(
              /* translators: %d: number of published entries */
              _n(
                "Its %d published entry and any drafts move to the trash, where they can be restored into another live blog, and readers stop seeing the feed. Posts that show it keep their own content.",
                "Its %d published entries and any drafts move to the trash, where they can be restored into another live blog, and readers stop seeing the feed. Posts that show it keep their own content.",
                deleting.count,
                "postsocket",
              ),
              deleting.count,
            )}
            confirmButtonText={__("Delete", "postsocket")}
          />
        </AlertDialog.Root>
      )}
    </Stack>
  );
}
