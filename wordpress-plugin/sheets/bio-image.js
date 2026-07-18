/**
 * "Insert Image" button for top-level longtext fields (Bio, Details, etc.)
 * in the Stat Block meta box (issue #68). Deliberately bypasses wp.media's
 * default "Add Media" insert flow — that flow bakes width, height, and
 * alignment into the inserted <img>, which is exactly the hand-set-width
 * problem the issue reports. This inserts a bare <img src alt class>
 * instead; sheet.css (.chr-prop[data-type="longtext"] img) sizes it
 * automatically on the public sheet.
 */
(function () {
  // One wp.media frame per editor, reused across clicks instead of
  // rebuilding (and leaking) a new frame every time the button is pressed.
  const frames = {};

  document.addEventListener("click", function (event) {
    const button = event.target.closest(".chr-insert-image");
    if (!button || typeof wp === "undefined" || !wp.media) {
      return;
    }
    event.preventDefault();
    const editorId = button.dataset.editorId;
    let frame = frames[editorId];
    if (!frame) {
      frame = wp.media({
        title: "Insert Image",
        library: { type: "image" },
        multiple: false,
        button: { text: "Insert" },
      });
      frame.on("select", function () {
        const attachment = frame.state().get("selection").first().toJSON();
        const img = document.createElement("img");
        // Prefer a registered size so a multi-MB phone upload isn't shipped to
        // every sheet viewer; sheet.css constrains display width regardless (#81).
        const sizes = attachment.sizes || {};
        img.src = (sizes.large && sizes.large.url) || attachment.url;
        img.alt = attachment.alt || attachment.title || "";
        img.className = "chr-bio-img";
        insertIntoEditor(editorId, img.outerHTML);
      });
      frames[editorId] = frame;
    }
    frame.open();
  });

  /** Inserts into whichever mode (TinyMCE visual, or the quicktags textarea)
   * the field is currently in. */
  function insertIntoEditor(editorId, html) {
    const editor = window.tinymce && window.tinymce.get(editorId);
    if (editor && !editor.isHidden()) {
      editor.execCommand("mceInsertContent", false, html);
      return;
    }
    const textarea = document.getElementById(editorId);
    if (!textarea) {
      return;
    }
    const start = textarea.selectionStart ?? textarea.value.length;
    const end = textarea.selectionEnd ?? textarea.value.length;
    textarea.value = textarea.value.slice(0, start) + html + textarea.value.slice(end);
    textarea.focus();
    const caret = start + html.length;
    textarea.selectionStart = caret;
    textarea.selectionEnd = caret;
  }
})();
