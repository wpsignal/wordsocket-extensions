import { Component, createRoot } from "@wordpress/element";
import { __ } from "@wordpress/i18n";
import { Dashboard } from "./Dashboard";
/*
 * Bundled with the board: core does not ship DataViews, and DataViews 19 needs
 * a newer @wordpress/components than core's, so both come with their styles;
 * the theme's tokens style the @wordpress/ui cards.
 */
import "@wordpress/theme/design-tokens.css";
import "@wordpress/components/build-style/style.css";
import "@wordpress/dataviews/build-style/style.css";
import "./board.css";

/**
 * Keeps a render error to one notice with a reload link instead of an empty screen.
 *
 * @since 0.8.0
 */
class Boundary extends Component<
  { children: React.ReactNode },
  { failed: boolean }
> {
  state = { failed: false };

  static getDerivedStateFromError(): { failed: boolean } {
    return { failed: true };
  }

  render() {
    if (!this.state.failed) return this.props.children;
    return (
      <div className="notice notice-error">
        <p>
          {__("The board could not draw.", "shopsocket")}{" "}
          <a href={window.location.href}>{__("Reload", "shopsocket")}</a>
        </p>
      </div>
    );
  }
}

const root = document.getElementById("shopsocket-board");
if (root) {
  createRoot(root).render(
    <Boundary>
      <Dashboard />
    </Boundary>,
  );
}
