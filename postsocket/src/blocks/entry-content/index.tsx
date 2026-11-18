/**
 * The Entry Content block's editor side: a stand-in for the entry's content.
 *
 * @since 0.1.0
 */
import { useBlockProps } from "@wordpress/block-editor";
import { registerBlockType } from "@wordpress/blocks";
import { __ } from "@wordpress/i18n";
import metadata from "./block.json";
import PostSocketLogo from "../../PostSocketLogo";

function Edit(): JSX.Element {
  const blockProps = useBlockProps({ className: "postsocket-entry__content" });
  return (
    <div {...blockProps}>
      <p>{__("This is where the entry's content is shown.", "postsocket")}</p>
    </div>
  );
}

registerBlockType(metadata.name, {
  title: metadata.title,
  category: metadata.category,
  icon: <PostSocketLogo />,
  edit: Edit,
  save: () => null,
});
