/**
 * Add/remove rows for list properties in the Stat Block meta box. The PHP
 * renders a <template> row with __i__ placeholders; rows added here get
 * indexes that never collide with server-rendered ones, and the save handler
 * array_values()es the lot, so gaps and ordering quirks don't matter.
 */
(function () {
  let counter = 1000;
  document.addEventListener("click", function (event) {
    const add = event.target.closest(".chr-list-add");
    if (add) {
      const container = add.parentElement.querySelector(".chr-list-rows");
      const template = add.parentElement.querySelector(".chr-list-template");
      const html = template.innerHTML.replaceAll("__i__", String(counter++));
      container.insertAdjacentHTML("beforeend", html);
      return;
    }
    const remove = event.target.closest(".chr-list-remove");
    if (remove) {
      remove.closest(".chr-list-row").remove();
    }
  });

  // Fields with data-when follow their gating toggle within the same row.
  document.addEventListener("change", function (event) {
    const input = event.target;
    if (input.type !== "checkbox") {
      return;
    }
    const field = input.closest(".chr-field--toggle[data-field]");
    const row = input.closest(".chr-list-row");
    if (!field || !row) {
      return;
    }
    for (const gated of row.querySelectorAll(
      '[data-when="' + field.dataset.field + '"]',
    )) {
      gated.hidden = !input.checked;
    }
  });
})();
