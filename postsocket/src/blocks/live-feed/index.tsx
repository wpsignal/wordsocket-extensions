/**
 * The Live Feed block's editor side: a placeholder for the feed PHP renders on the page.
 *
 * @since 0.1.0
 */
import { useBlockProps } from "@wordpress/block-editor";
import { registerBlockType } from "@wordpress/blocks";
import { __ } from "@wordpress/i18n";
import metadata from "./block.json";

registerBlockType(metadata.name, {
  title: metadata.title,
  category: metadata.category,
  attributes: metadata.attributes,
  edit: () => (
    <div {...useBlockProps({ className: "postsocket-feed-placeholder" })}>
      <p>
        <strong>{__("Live feed", "postsocket")}</strong>
        <br />
        {__("Entries appear here on the live blog's page, newest first, and update as they are published.", "postsocket")}
      </p>
    </div>
  ),
  save: () => null,
});
