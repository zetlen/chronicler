import { describe, it, expect } from "vitest";
import { unzipSync, strFromU8 } from "fflate";
import { pluginFiles, pluginZip } from "@/lib/wordpress/plugin";
import { BLOCKS_VERSION } from "@/lib/wordpress/blockGrammar";

describe("the static plugin source", () => {
  const files = pluginFiles();
  const loader = files["chronicler.php"];
  const php = files["blocks.php"];
  const editorJs = files["editor.js"];

  it("is one omakase plugin whose loader requires every module", () => {
    expect(loader).toContain("Plugin Name: Chronicler");
    expect(loader).toContain("define('CHRONICLER_PLUGIN_FILE', __FILE__)");
    expect(loader).toContain("require_once __DIR__ . '/blocks.php'");
    // blocks.php is a module now, not a plugin:
    expect(php).not.toContain("Plugin Name:");
    expect(php).toContain("<?php");
  });

  it("holds no global style state — styles are per-post block data", () => {
    expect(php).not.toContain("get_option");
    expect(php).not.toContain("update_option");
    expect(php).not.toContain("wp_enqueue_scripts");
    expect(php).toContain("'baseCss'");
    expect(php).toMatch(/\$attributes\['baseCss'\]/);
  });

  it("prints each distinct stylesheet once per request", () => {
    expect(php).toContain("static $printed");
    expect(php).toContain("md5($css)");
  });

  it("declares the block schema version this app emits", () => {
    const match = /const CHRONICLER_BLOCKS_VERSION = (\d+);/.exec(php);
    expect(match).not.toBeNull();
    expect(Number(match![1])).toBe(BLOCKS_VERSION);
  });

  it("registers all four chronicler blocks server-side", () => {
    for (const block of ["transcript", "thread", "replies", "message"]) {
      expect(php).toContain(`register_block_type('chronicler/${block}'`);
    }
    // v3: the message block's render callback delegates to the pure
    // renderer in message-render.php, which itself falls back to the
    // opaque v2 `html` attribute — still registered here.
    expect(php).toContain("require_once __DIR__ . '/message-render.php'");
    expect(php).toContain("chronicler_render_message($attributes)");
    expect(php).toMatch(/'html' => \['type' => 'string', 'default' => ''\]/);
  });

  it("registers the editor-side block definitions", () => {
    for (const block of ["transcript", "thread", "replies", "message"]) {
      expect(editorJs).toContain(`registerBlockType('chronicler/${block}'`);
    }
    expect(php).toContain("enqueue_block_editor_assets");
    expect(php).toContain("plugins_url('editor.js'");
  });

  it("mirrors the front-end scheme/density/CSS derivation in the editor view", () => {
    // The PHP render_callback derives these on the front end; the editor
    // edit view must too, or dark transcripts render light in Gutenberg.
    expect(editorJs).toContain("slk-dark");
    expect(editorJs).toContain("slk-density-compact");
    expect(editorJs).toContain("baseCss");
    expect(editorJs).toContain("customCss");
  });

  it("exposes the transcript's styles for editing in the block inspector", () => {
    expect(editorJs).toContain("InspectorControls");
    expect(editorJs).toContain("TextareaControl");
    expect(editorJs).toContain("setAttributes({ customCss: v })");
    expect(editorJs).toContain("setAttributes({ baseCss: v })");
    expect(editorJs).toContain("setAttributes({ scheme: v })");
    // The components bundle must be a declared dependency or the sidebar
    // controls silently fail to render.
    expect(php).toContain("'wp-components'");
  });
});

describe("pluginZip", () => {
  it("packages every plugin file under the chronicler/ slug, tests excluded", () => {
    const files = pluginFiles();
    const entries = unzipSync(pluginZip());
    const names = Object.keys(entries).sort();
    expect(names).toContain("chronicler/chronicler.php");
    expect(names).toContain("chronicler/blocks.php");
    expect(names).toContain("chronicler/editor.js");
    expect(names.every((n) => n.startsWith("chronicler/"))).toBe(true);
    expect(names.some((n) => n.includes("/tests/"))).toBe(false);
    expect(names.some((n) => n.endsWith(".test.ts"))).toBe(false);
    expect(strFromU8(entries["chronicler/blocks.php"])).toBe(files["blocks.php"]);
  });
});
