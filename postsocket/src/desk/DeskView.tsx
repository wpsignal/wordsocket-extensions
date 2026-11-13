/**
 * One live blog's desk: its state and home, its entries with pin, publish, unpublish and trash, and the trash with restore.
 *
 * @since 0.1.0
 */
import apiFetch from "@wordpress/api-fetch";
import {
  DataViews,
  type Action,
  type Field,
  type View,
} from "@wordpress/dataviews";
import {
  Fragment,
  useCallback,
  useEffect,
  useMemo,
  useState,
} from "@wordpress/element";
import { decodeEntities } from "@wordpress/html-entities";
import { __, _n, sprintf } from "@wordpress/i18n";
import {
  AlertDialog,
  Badge,
  ButtonLink,
  Card,
  EmptyState,
  Link,
  Notice,
  Stack,
  SwitchControl,
  Tabs,
  Text,
} from "@wordpress/ui";
import {
  coalesce,
  deleteEntry,
  entriesPath,
  liveblogsPath,
  restoreEntry,
  setEnded,
  trashEntry,
  updateEntry,
  usePage,
  type Entry,
  type Liveblog,
} from "./api";
import { formatTime, isEnded } from "./format";
import { useLiveRefresh } from "./useLiveRefresh";

const cfg = window.postsocketDesk!;

const STATUSES: Record<
  string,
  {
    label: string;
    intent: "stable" | "draft" | "informational" | "medium" | "low";
  }
> = {
  publish: { label: __("Published", "postsocket"), intent: "stable" },
  future: { label: __("Scheduled", "postsocket"), intent: "informational" },
  pending: { label: __("Pending", "postsocket"), intent: "medium" },
  draft: { label: __("Draft", "postsocket"), intent: "draft" },
  "auto-draft": { label: __("Not saved yet", "postsocket"), intent: "draft" },
  trash: { label: __("Trashed", "postsocket"), intent: "low" },
};

type Tab = "entries" | "trash";

/** An action waiting for the editor to confirm it. */
interface Pending {
  kind: "unpublish" | "trash" | "delete";
  items: Entry[];
}

const isPinned = (entry: Entry): boolean =>
  Boolean(entry.meta?.[cfg.meta.pinned]);
const editLink = (entry: Entry): string => `${cfg.editUrl}${entry.id}`;
const isSaved = (entry: Entry): boolean =>
  entry.status !== "trash" && entry.status !== "auto-draft";
const summaryOf = (entry: Entry): string =>
  decodeEntities(entry.postsocket.summary) ||
  (entry.status === "auto-draft"
    ? __("New entry", "postsocket")
    : __("(empty)", "postsocket"));

/** The words of a confirmation, by what is being confirmed and for how many entries. */
function confirmation({ kind, items }: Pending): {
  title: string;
  description: string;
  confirm: string;
} {
  const one = items.length === 1 ? summaryOf(items[0]) : "";
  if (kind === "unpublish") {
    return {
      /* translators: %s: entry summary */
      title: sprintf(__("Unpublish “%s”?", "postsocket"), one),
      description: __(
        "Readers stop seeing it at once. It stays on the desk as a draft.",
        "postsocket",
      ),
      confirm: __("Unpublish", "postsocket"),
    };
  }
  if (kind === "trash") {
    return {
      title: one
        ? /* translators: %s: entry summary */
          sprintf(__("Move “%s” to the trash?", "postsocket"), one)
        : /* translators: %d: number of entries */
          sprintf(
            _n(
              "Move %d entry to the trash?",
              "Move %d entries to the trash?",
              items.length,
              "postsocket",
            ),
            items.length,
          ),
      description: __(
        "Readers stop seeing a published entry at once. Trashed entries can be restored from the Trash tab.",
        "postsocket",
      ),
      confirm: __("Move to trash", "postsocket"),
    };
  }
  return {
    title: one
      ? /* translators: %s: entry summary */
        sprintf(__("Delete “%s” permanently?", "postsocket"), one)
      : /* translators: %d: number of entries */
        sprintf(
          _n(
            "Delete %d entry permanently?",
            "Delete %d entries permanently?",
            items.length,
            "postsocket",
          ),
          items.length,
        ),
    description: __("This cannot be undone.", "postsocket"),
    confirm: __("Delete permanently", "postsocket"),
  };
}

/**
 * The desk of one live blog.
 *
 * @since 0.1.0
 */
export function DeskView({
  id,
  back,
}: {
  id: number;
  back: () => void;
}): JSX.Element {
  const [liveblog, setLiveblog] = useState<Liveblog | null>(null);
  const [missing, setMissing] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [tab, setTab] = useState<Tab>("entries");
  const [pending, setPending] = useState<Pending | null>(null);
  const [view, setView] = useState<View>({
    type: "table",
    page: 1,
    perPage: 20,
    search: "",
    sort: { field: "date", direction: "desc" },
    fields: ["status", "pinned", "author", "date"],
    titleField: "summary",
  });

  const loadLiveblog = useMemo(
    () =>
      coalesce(() =>
        apiFetch<Liveblog>({
          path: `${liveblogsPath}/${id}?context=edit&_fields=id,name,count,meta,postsocket`,
        })
          .then(setLiveblog)
          .catch(() => setMissing(true)),
      ),
    [id],
  );
  useEffect(loadLiveblog, [loadLiveblog]);

  const query = {
    context: "edit",
    [cfg.restBase]: id,
    status: tab === "trash" ? "trash" : "publish,future,pending,draft",
    postsocket_open: tab === "trash" ? undefined : true,
    page: view.page,
    per_page: view.perPage,
    search: view.search || undefined,
    orderby: "date",
    order: view.sort?.direction ?? "desc",
    _fields: "id,status,date_gmt,link,meta,postsocket",
  };
  const entries = usePage<Entry>(entriesPath, query);
  const trashed = usePage<Entry>(entriesPath, {
    context: "edit",
    [cfg.restBase]: id,
    status: "trash",
    per_page: 1,
    _fields: "id",
  });
  const refresh = useCallback(() => {
    entries.reload();
    trashed.reload();
    loadLiveblog();
  }, [entries.reload, trashed.reload, loadLiveblog]);
  useLiveRefresh([`${cfg.channel}${id}`, `${cfg.deskChannel}${id}`], refresh);

  /* Look again once the soonest edit lock on screen has lapsed, since a lapse is not announced. */
  useEffect(() => {
    const waits = entries.items.flatMap((item) =>
      item.postsocket.editing ? [item.postsocket.editing.expires_in] : [],
    );
    if (!waits.length) return;
    const timer = setTimeout(refresh, (Math.min(...waits) + 1) * 1000);
    return () => clearTimeout(timer);
  }, [entries.items, refresh]);

  /* Show the change on the row at once, send it, and refresh; a failure puts the server's rows back. */
  const run = useCallback(
    async (show: () => void, work: () => Promise<unknown>) => {
      setError(null);
      show();
      try {
        await work();
      } catch (err) {
        setError(
          (err as { message?: string })?.message ??
            __("That did not work.", "postsocket"),
        );
      }
      refresh();
    },
    [refresh],
  );
  const { patch, remove } = entries;

  const fields = useMemo<Field<Entry>[]>(
    () => [
      {
        id: "summary",
        label: __("Entry", "postsocket"),
        enableHiding: false,
        enableSorting: false,
        getValue: ({ item }) => decodeEntities(item.postsocket.summary),
        render: ({ item }) => (
          <Stack direction="row" gap="sm" align="center" wrap="wrap">
            {item.status === "trash" ? (
              <Text>{summaryOf(item)}</Text>
            ) : (
              <Link href={editLink(item)}>{summaryOf(item)}</Link>
            )}
            {item.postsocket.editing && (
              <Badge intent="informational">
                {sprintf(
                  /* translators: %s: a user's display name */
                  __("%s is editing", "postsocket"),
                  item.postsocket.editing.name,
                )}
              </Badge>
            )}
          </Stack>
        ),
      },
      {
        id: "status",
        label: __("Status", "postsocket"),
        enableSorting: false,
        render: ({ item }) => {
          const status = STATUSES[item.status] ?? {
            label: item.status,
            intent: "draft" as const,
          };
          return <Badge intent={status.intent}>{status.label}</Badge>;
        },
      },
      {
        id: "pinned",
        label: __("Pinned", "postsocket"),
        enableSorting: false,
        render: ({ item }) =>
          isPinned(item) ? (
            <Badge intent="informational">{__("Pinned", "postsocket")}</Badge>
          ) : null,
      },
      {
        id: "author",
        label: __("Author", "postsocket"),
        enableSorting: false,
        getValue: ({ item }) => item.postsocket.author,
      },
      {
        id: "date",
        label: __("Date", "postsocket"),
        enableSorting: true,
        render: ({ item }) => (
          <Text variant="body-sm">{formatTime(item.date_gmt)}</Text>
        ),
      },
    ],
    [],
  );

  const actions = useMemo<Action<Entry>[]>(
    () => [
      {
        id: "edit",
        label: __("Edit", "postsocket"),
        isPrimary: true,
        isEligible: (item) => item.status !== "trash",
        callback: ([item]) => {
          window.location.href = editLink(item);
        },
      },
      {
        id: "pin",
        label: __("Pin to the top", "postsocket"),
        isEligible: (item) => isSaved(item) && !isPinned(item),
        callback: ([item]) =>
          void run(
            () =>
              patch(item.id, {
                meta: { ...item.meta, [cfg.meta.pinned]: true },
              }),
            () => updateEntry(item.id, { meta: { [cfg.meta.pinned]: true } }),
          ),
      },
      {
        id: "unpin",
        label: __("Unpin", "postsocket"),
        isEligible: (item) => isSaved(item) && isPinned(item),
        callback: ([item]) =>
          void run(
            () =>
              patch(item.id, {
                meta: { ...item.meta, [cfg.meta.pinned]: false },
              }),
            () => updateEntry(item.id, { meta: { [cfg.meta.pinned]: false } }),
          ),
      },
      {
        id: "publish",
        label: __("Publish", "postsocket"),
        isEligible: (item) =>
          item.status === "draft" || item.status === "pending",
        callback: ([item]) =>
          void run(
            () => patch(item.id, { status: "publish" }),
            () => updateEntry(item.id, { status: "publish" }),
          ),
      },
      {
        id: "unpublish",
        label: __("Unpublish", "postsocket"),
        isEligible: (item) => item.status === "publish",
        callback: (items) => setPending({ kind: "unpublish", items }),
      },
      {
        id: "trash",
        label: __("Move to trash", "postsocket"),
        supportsBulk: true,
        isEligible: isSaved,
        callback: (items) => setPending({ kind: "trash", items }),
      },
      {
        id: "restore",
        label: __("Restore", "postsocket"),
        isPrimary: true,
        supportsBulk: true,
        isEligible: (item) => item.status === "trash",
        callback: (items) =>
          void run(
            () => items.forEach((item) => remove(item.id)),
            () => Promise.all(items.map((item) => restoreEntry(item.id))),
          ),
      },
      {
        id: "delete",
        label: __("Delete permanently", "postsocket"),
        supportsBulk: true,
        isEligible: (item) => item.status === "trash",
        callback: (items) => setPending({ kind: "delete", items }),
      },
    ],
    [run, patch, remove],
  );

  /* Carry out the action the editor confirmed. */
  const confirm = ({ kind, items }: Pending): void => {
    if (kind === "unpublish") {
      void run(
        () => items.forEach((item) => patch(item.id, { status: "draft" })),
        () =>
          Promise.all(
            items.map((item) => updateEntry(item.id, { status: "draft" })),
          ),
      );
      return;
    }
    void run(
      () => items.forEach((item) => remove(item.id)),
      () =>
        Promise.all(
          items.map((item) =>
            kind === "trash" ? trashEntry(item.id) : deleteEntry(item.id),
          ),
        ),
    );
  };
  const words = pending ? confirmation(pending) : null;

  if (missing) {
    return (
      <EmptyState.Root>
        <EmptyState.Title>
          {__("This live blog no longer exists", "postsocket")}
        </EmptyState.Title>
        <EmptyState.Actions>
          <ButtonLink href={cfg.deskUrl}>
            {__("All live blogs", "postsocket")}
          </ButtonLink>
        </EmptyState.Actions>
      </EmptyState.Root>
    );
  }

  const ended = liveblog ? isEnded(liveblog) : false;
  const home = liveblog?.postsocket.home ?? null;

  return (
    <Stack direction="column" gap="lg">
      <Stack direction="column" gap="xs">
        <Link
          href={cfg.deskUrl}
          onClick={(event) => (event.preventDefault(), back())}
          style={{ width: "fit-content" }}
        >
          {__("Back", "postsocket")}
        </Link>
        <Stack
          direction="row"
          justify="space-between"
          align="center"
          wrap="wrap"
        >
          <Stack direction="row" gap="sm" align="center">
            <Text variant="heading-xl" render={<h2 />}>
              {liveblog
                ? decodeEntities(liveblog.name)
                : __("Loading…", "postsocket")}
            </Text>
            {liveblog &&
              (ended ? (
                <Badge intent="low">{__("Ended", "postsocket")}</Badge>
              ) : (
                <Badge intent="stable">{__("Live", "postsocket")}</Badge>
              ))}
          </Stack>
          <ButtonLink href={`${cfg.addEntryUrl}&liveblog=${id}`}>
            {__("Add entry", "postsocket")}
          </ButtonLink>
        </Stack>
      </Stack>

      {!cfg.wordsocketOk && (
        <Notice.Root intent="warning">
          <Notice.Description>
            {sprintf(
              /* translators: %s: the oldest WordSocket version PostSocket runs with */
              __(
                "PostSocket needs WordSocket %s or newer before readers see entries live.",
                "postsocket",
              ),
              cfg.minVersion,
            )}
          </Notice.Description>
        </Notice.Root>
      )}
      {error && (
        <Notice.Root intent="error">
          <Notice.Description>{error}</Notice.Description>
        </Notice.Root>
      )}

      {liveblog && (
        <Card.Root>
          <Card.Content>
            <Stack
              direction="row"
              gap="xl"
              align="center"
              wrap="wrap"
              justify="space-between"
            >
              <Stack direction="column" gap="xs">
                <Text variant="heading-sm">{__("Shown on", "postsocket")}</Text>
                {home ? (
                  <Stack direction="row" gap="sm" align="center">
                    <Text variant="body-md">
                      {decodeEntities(home.title) ||
                        __("(no title)", "postsocket")}
                      :
                    </Text>
                    <Link href={home.link}>{__("View", "postsocket")}</Link> /
                    <Link href={`${cfg.editUrl}${home.id}`}>
                      {__("Edit", "postsocket")}
                    </Link>
                  </Stack>
                ) : (
                  <Stack direction="row" gap="sm" align="center">
                    <Text variant="body-sm">
                      {__(
                        "No published post shows this live blog yet.",
                        "postsocket",
                      )}
                    </Text>
                    <ButtonLink
                      variant="outline"
                      size="compact"
                      href={`${cfg.createPageUrl}&postsocket_liveblog=${id}`}
                    >
                      {__("Create page", "postsocket")}
                    </ButtonLink>
                  </Stack>
                )}
              </Stack>
              {cfg.canManage && (
                <SwitchControl
                  label={__("End Live Blog", "postsocket")}
                  checked={ended}
                  onCheckedChange={(on) =>
                    void run(
                      () =>
                        setLiveblog({
                          ...liveblog,
                          meta: {
                            ...liveblog.meta,
                            [cfg.meta.state]: on ? "ended" : "live",
                          },
                        }),
                      () => setEnded(liveblog, on).then(setLiveblog),
                    )
                  }
                />
              )}
            </Stack>
          </Card.Content>
        </Card.Root>
      )}

      <Tabs.Root
        value={tab}
        onValueChange={(value) => {
          setTab(value as Tab);
          setView((current) => ({ ...current, page: 1 }));
        }}
      >
        <Tabs.List
          variant="minimal"
          aria-label={__("Entries and the trash", "postsocket")}
        >
          <Tabs.Tab value="entries">{__("Entries", "postsocket")}</Tabs.Tab>
          <Tabs.Tab value="trash">
            {sprintf(
              /* translators: %d: number of trashed entries */
              __("Trash (%d)", "postsocket"),
              trashed.total,
            )}
          </Tabs.Tab>
        </Tabs.List>
      </Tabs.Root>

      <Card.Root>
        <Card.Content>
          <DataViews<Entry>
            view={view}
            onChangeView={setView}
            fields={fields}
            actions={actions}
            data={entries.items}
            isLoading={entries.loading}
            paginationInfo={{
              totalItems: entries.total,
              totalPages: entries.totalPages,
            }}
            defaultLayouts={{ table: {}, list: {} }}
            getItemId={(item) => String(item.id)}
            empty={
              tab === "trash" ? (
                <EmptyState.Root>
                  <EmptyState.Title>
                    {__("The trash is empty", "postsocket")}
                  </EmptyState.Title>
                  <EmptyState.Description>
                    {__(
                      "Entries moved to the trash are kept here until they are restored or deleted.",
                      "postsocket",
                    )}
                  </EmptyState.Description>
                </EmptyState.Root>
              ) : (
                <EmptyState.Root>
                  <EmptyState.Title>
                    {__("No entries yet", "postsocket")}
                  </EmptyState.Title>
                  <EmptyState.Description>
                    {__(
                      "Add the first entry; readers see it the moment you publish.",
                      "postsocket",
                    )}
                  </EmptyState.Description>
                  <EmptyState.Actions>
                    <ButtonLink href={`${cfg.addEntryUrl}&liveblog=${id}`}>
                      {__("Add entry", "postsocket")}
                    </ButtonLink>
                  </EmptyState.Actions>
                </EmptyState.Root>
              )
            }
          />
        </Card.Content>
      </Card.Root>

      {pending && words && (
        <AlertDialog.Root
          open
          onOpenChange={(isOpen) => {
            if (!isOpen) setPending(null);
          }}
          onConfirm={() => {
            confirm(pending);
            setPending(null);
            return { close: true };
          }}
        >
          <AlertDialog.Popup
            intent={pending.kind === "delete" ? "irreversible" : "default"}
            title={words.title}
            description={words.description}
            confirmButtonText={words.confirm}
          />
        </AlertDialog.Root>
      )}
    </Stack>
  );
}
