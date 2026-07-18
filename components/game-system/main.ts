// Entry point for the Game System editor bundle (#149). The starter example
// is the drift-guarded corpus fixture — PHP and the schema both prove it
// valid on every test run, so the inserted example can never rot.
import starter from "@/wordpress-plugin/tests/fixtures/templates/valid-starter-example.yaml";
import { buildExtensions } from "./editor";
import { mountGameSystemEditor } from "./mount";

function mount(): void {
  const view = mountGameSystemEditor(document, buildExtensions, starter);
  if (view !== null) {
    // Console handle for support/debugging; nothing in the page reads it.
    Object.assign(window, { chroniclerGameSystemEditor: view });
  }
}

if (document.readyState === "loading") {
  document.addEventListener("DOMContentLoaded", mount);
} else {
  mount();
}
