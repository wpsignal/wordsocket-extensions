/**
 * The Live Blog Entry block's editor side: the layout an entry is printed in, built from inner blocks and previewed with the newest entry.
 *
 * @since 0.1.0
 */
import {
  BlockContextProvider,
  InnerBlocks,
  useBlockProps,
  useInnerBlocksProps,
} from "@wordpress/block-editor";
import { getBlockType, registerBlockType } from "@wordpress/blocks";
import { useEntityRecords } from "@wordpress/core-data";
import { select } from "@wordpress/data";
import { useMemo } from "@wordpress/element";
import { addFilter } from "@wordpress/hooks";
import metadata from "./block.json";
import "./style.css";
import PostSocketLogo from "../../PostSocketLogo";

const ENTRY_TYPE = "postsocket_entry";

const TEMPLATE = [
  [
    "core/group",
    {
      layout: {
        type: "flex",
        flexWrap: "wrap",
        justifyContent: "space-between",
      },
    },
    [
      [
        "core/group",
        { layout: { type: "flex", flexWrap: "nowrap" } },
        [
          [
            "core/post-date",
            {
              metadata: {
                bindings: {
                  datetime: {
                    source: "core/post-data",
                    args: { field: "date" },
                  },
                },
              },
            },
          ],
          ["core/avatar", { size: 24 }],
          ["core/post-author-name", { fontSize: "small" }],
        ],
      ],
      [
        "core/group",
        { layout: { type: "flex", flexWrap: "nowrap" } },
        [
          ["core/post-terms", { term: "post_tag" }],
          ["core/post-terms", { term: "category" }],
        ],
      ],
    ],
  ],
  ["core/post-title", { level: 3, fontSize: "large" }],
  ["postsocket/entry-content", { fontSize: "medium" }],
];

function Edit(): JSX.Element {
  const blockProps = useBlockProps({ className: "postsocket-entry" });
  const innerBlocksProps = useInnerBlocksProps(blockProps, {
    template: TEMPLATE,
  });
  const { records } = useEntityRecords<{ id: number }>("postType", ENTRY_TYPE, {
    per_page: 1,
    _fields: "id",
  });
  const newest = records?.[0]?.id ?? 0;
  // The inner post blocks read the newest entry as a loop's would, so they show it without editing it.
  const context = useMemo(
    () => (newest ? { postId: newest, postType: ENTRY_TYPE, queryId: 0 } : {}),
    [newest],
  );
  return (
    <BlockContextProvider value={context}>
      <article {...innerBlocksProps} />
    </BlockContextProvider>
  );
}

type BlockSettings = Record<string, unknown>;

const config = (window.postsocketEntryBlock ??= {});
const templateBlocks = new Set(
  (config.blocks ?? []).filter((name) => !name.startsWith("postsocket/")),
);
const registered = new Map<string, BlockSettings>();
const restored = new Set<string>();

// Keep the settings each template block is first registered with.
addFilter(
  "blocks.registerBlockType",
  "postsocket/entry-keep",
  (settings: BlockSettings, name: string) => {
    if (templateBlocks.has(name) && !registered.has(name))
      registered.set(name, settings);
    return settings;
  },
  1,
);

// A block the site allows only for entry templates, or one put back after being removed, is offered only inside this block.
addFilter(
  "blocks.registerBlockType",
  "postsocket/entry-only",
  (settings: BlockSettings, name: string) =>
    restored.has(name) || config.restricted?.includes(name)
      ? { ...settings, ancestor: [metadata.name] }
      : settings,
);

/**
 * Register again, once, each template block that is no longer registered.
 *
 * @since 0.1.0
 */
config.restore = (): void => {
  for (const [name, settings] of registered) {
    if (restored.has(name) || getBlockType(name)) continue;
    restored.add(name);
    registerBlockType(name, settings);
  }
};

const BORDER = new Set([
  "border.color",
  "border.radius",
  "border.style",
  "border.width",
]);

// The border controls are offered on this block and on every block inside it, whatever the theme's settings.
addFilter(
  "blockEditor.useSetting.before",
  "postsocket/entry-border",
  (value: unknown, path: string, clientId: string, name: string) => {
    if (value !== undefined || !BORDER.has(path)) return value;
    const inside =
      name === metadata.name ||
      select("core/block-editor").getBlockParentsByBlockName(
        clientId,
        metadata.name,
      ).length > 0;
    return inside ? true : value;
  },
);

registerBlockType(metadata.name, {
  title: metadata.title,
  category: metadata.category,
  icon: <PostSocketLogo />,
  edit: Edit,
  save: () => <InnerBlocks.Content />,
});
