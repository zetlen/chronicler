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

  it("clears rewrite residue on deactivation by deleting the option (#164)", () => {
    // Deletion, not flush_rewrite_rules(): the hook runs after init with
    // chr_character still registered, and a flush regenerates immediately —
    // rebuilding the residue it exists to drop. Deleting regenerates clean
    // rules lazily on the next, plugin-less request (uninstall.php's
    // reasoning). Network-wide deactivation clears every site's option.
    expect(loader).toContain("register_deactivation_hook");
    expect(loader).toContain("delete_option('rewrite_rules');");
    expect(loader).not.toContain("flush_rewrite_rules();");
    expect(loader).toMatch(/\$network_wide && is_multisite\(\)/);
  });

  it("warns about uninstall data loss on the plugin row (#174)", () => {
    // Best-effort proximity to the Delete action; Settings\Screen::render()
    // and readme.txt's FAQ carry the durable copies.
    expect(loader).toContain("add_filter('plugin_row_meta'");
    expect(loader).toContain("removes its sessions and character sheets");
  });

  it("registers every transcript block at Block API v3 (#164)", () => {
    // Four registrations per side, each carrying the version key — a new
    // block added without one would default back to v1.
    expect(php.match(/register_block_type\('chronicler\//g)).toHaveLength(4);
    expect(php.match(/'api_version' => 3/g)).toHaveLength(4);
    expect(editorJs.match(/registerBlockType\('chronicler\//g)).toHaveLength(4);
    // Three literals cover four blocks: thread + replies share container()'s.
    expect(editorJs.match(/apiVersion: 3/g)).toHaveLength(3);
  });

  it("registers the editor-side block definitions", () => {
    for (const block of ["transcript", "thread", "replies", "message"]) {
      expect(editorJs).toContain(`registerBlockType('chronicler/${block}'`);
    }
    expect(php).toContain("enqueue_block_editor_assets");
    expect(php).toContain("plugins_url('editor.js'");
    // …scoped to transcript-hosting post types (#164), with a filter as the
    // escape hatch for sites keeping transcripts in a CPT.
    expect(php).toContain("chronicler_is_transcript_editor_screen()");
    expect(php).toContain("apply_filters('chronicler_editor_post_types'");
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
