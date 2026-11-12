/**
 * The Live Feed block's editor side: choose or create the live blog it shows.
 *
 * @since 0.1.0
 */
import { useBlockProps } from "@wordpress/block-editor";
import { registerBlockType } from "@wordpress/blocks";
import { useRefEffect } from "@wordpress/compose";
import { store as coreStore, useEntityRecord, useEntityRecords } from "@wordpress/core-data";
import { useDispatch } from "@wordpress/data";
import { useState } from "@wordpress/element";
import { decodeEntities } from "@wordpress/html-entities";
import { __, _n, sprintf } from "@wordpress/i18n";
import { registerDocument } from "@wordpress/style-runtime";
import { Badge, Button, Card, InputControl, Link, SearchableSelectControl, Stack, Text } from "@wordpress/ui";
import LiveBlogIcon from "../../LiveBlogIcon";
import metadata from "./block.json";
import PostSocketLogo from "../../PostSocketLogo";

const cfg = window.postsocketBlock;

interface Attributes {
  liveblog: number;
}

interface LiveblogTerm {
  id: number;
  name: string;
  count: number;
  meta?: Record<string, unknown>;
}

function NewLiveblog({ onCreated }: { onCreated: (id: number) => void }): JSX.Element {
  const [name, setName] = useState("");
  const [busy, setBusy] = useState(false);
  const { saveEntityRecord } = useDispatch(coreStore);

  const create = async (): Promise<void> => {
    if (!cfg || !name.trim()) return;
    setBusy(true);
    const term = (await saveEntityRecord("taxonomy", cfg.taxonomy, { name: name.trim() })) as LiveblogTerm | undefined;
    setBusy(false);
    if (term?.id) {
      setName("");
      onCreated(term.id);
    }
  };

  return (
    <Stack direction="row" gap="sm" align="end">
      <InputControl
        label={__("New live blog", "postsocket")}
        placeholder={__("Match day: City v United", "postsocket")}
        value={name}
        onValueChange={setName}
      />
      <Button variant="outline" loading={busy} disabled={!name.trim()} onClick={() => void create()}>
        {__("Create", "postsocket")}
      </Button>
    </Stack>
  );
}

function Edit({ attributes, setAttributes }: { attributes: Attributes; setAttributes: (next: Partial<Attributes>) => void }): JSX.Element {
  // The canvas is an iframe the style runtime does not know, so the components' styles are sent there too.
  const styled = useRefEffect((node: HTMLElement) => (node.ownerDocument !== document ? registerDocument(node.ownerDocument) : undefined), []);
  const blockProps = useBlockProps({ ref: styled, className: "postsocket-feed-placeholder" });
  const { records, hasResolved } = useEntityRecords<LiveblogTerm>("taxonomy", cfg?.taxonomy ?? "", {
    per_page: 100,
    orderby: "id",
    order: "desc",
    hide_empty: false,
    _fields: "id,name",
  });
  const { record } = useEntityRecord<LiveblogTerm>("taxonomy", cfg?.taxonomy ?? "", attributes.liveblog, {
    enabled: attributes.liveblog > 0,
  });

  const items = (records ?? []).map((term) => ({ value: String(term.id), label: decodeEntities(term.name) }));
  const selected = items.find((item) => item.value === String(attributes.liveblog)) ?? null;
  const ended = record?.meta?.[cfg?.meta.state ?? ""] === "ended";

  return (
    <div {...blockProps}>
      <Card.Root>
        <Card.Header>
          <Card.Title>{__("Live feed", "postsocket")}</Card.Title>
        </Card.Header>
        <Card.Content>
          <Stack direction="column" gap="md">
            <Text variant="body-md">
              {__("Readers see this live blog's entries here, newest first, as they are published.", "postsocket")}
            </Text>
            <SearchableSelectControl
              label={__("Live blog", "postsocket")}
              items={items}
              value={selected}
              onValueChange={(item) => setAttributes({ liveblog: item ? Number(item.value) : 0 })}
              placeholder={hasResolved ? __("Choose a live blog", "postsocket") : __("Loading…", "postsocket")}
              emptyContent={__("No live blogs yet.", "postsocket")}
            />
            {record && (
              <Stack direction="row" gap="sm" align="center">
                <Badge intent={ended ? "low" : "stable"}>{ended ? __("Ended", "postsocket") : __("Live", "postsocket")}</Badge>
                <Text variant="body-sm">
                  {sprintf(
                    /* translators: %d: number of published entries */
                    _n("%d published entry", "%d published entries", record.count, "postsocket"),
                    record.count,
                  )}
                </Text>
                {cfg && (
                  <Text variant="body-sm" render={<Link href={`${cfg.deskUrl}&liveblog=${record.id}`} openInNewTab />}>
                    {__("Open the desk", "postsocket")}
                  </Text>
                )}
              </Stack>
            )}
            {cfg?.canManage && <NewLiveblog onCreated={(id) => setAttributes({ liveblog: id })} />}
          </Stack>
        </Card.Content>
      </Card.Root>
    </div>
  );
}

registerBlockType(metadata.name, {
  title: metadata.title,
  category: metadata.category,
  icon: <PostSocketLogo />,
  attributes: metadata.attributes as never,
  edit: Edit,
  save: () => null,
});
