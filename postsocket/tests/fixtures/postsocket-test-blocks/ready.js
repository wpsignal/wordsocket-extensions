document.addEventListener("DOMContentLoaded", function () {
  document.querySelectorAll(".postsocket-test-ready").forEach(function (el) {
    el.dataset.runs = String(Number(el.dataset.runs || 0) + 1);
    el.textContent = window.postsocketTestDep.label + ":" + window.postsocketTestDepLoaded;
  });
});
