import { createRoot } from "@wordpress/element";
import { Board } from "./Board";
import "./board.css";

const root = document.getElementById("wordsocket-woo-board");
if (root) {
  createRoot(root).render(<Board />);
}
