/**
 * The Live Stock block's editor side: a static preview of what the storefront
 * renders live. The markup itself comes from PHP (`inc/blocks.php`).
 */
import { registerBlockType } from "@wordpress/blocks";
import { useBlockProps } from "@wordpress/block-editor";
import { __ } from "@wordpress/i18n";
import metadata from "./block.json";

registerBlockType(metadata.name, {
  title: metadata.title,
  category: metadata.category,
  attributes: metadata.attributes,
  edit: () => (
    <div {...useBlockProps({ className: "shopsocket-live-stock" })}>
      <p className="shopsocket-live-stock__availability">{__("12 in stock", "shopsocket")}</p>
      <p className="shopsocket-live-stock__left">{__("12 left", "shopsocket")}</p>
      <p className="shopsocket-in-carts">
        <span className="shopsocket-in-carts__dot" aria-hidden="true"></span> {__("3 shoppers have this in their cart right now", "shopsocket")}
      </p>
    </div>
  ),
  save: () => null,
});
