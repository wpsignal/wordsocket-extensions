import { createRoot } from "@wordpress/element";
import { Dashboard } from "./Dashboard";
// Bundled with the board: core does not ship DataViews, and DataViews 19 needs
// a newer @wordpress/components than core's, so both come with their styles.
import "@wordpress/components/build-style/style.css";
import "@wordpress/dataviews/build-style/style.css";
import "./board.css";

const root = document.getElementById("shopsocket-board");
if (root) {
  createRoot(root).render(<Dashboard />);
}
