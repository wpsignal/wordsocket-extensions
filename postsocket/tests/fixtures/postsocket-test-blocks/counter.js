import { store } from "@wordpress/interactivity";

const { state } = store("postsocket-test", {
  actions: {
    add() {
      state.count++;
    },
  },
});
