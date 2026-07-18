import { describe, it, expect } from "vitest";
import { pluginFiles } from "@/lib/wordpress/plugin";

/**
 * Source assertions for the sheets module's WordPress glue, in the same
 * style as plugin.test.ts: pure logic is behaviorally tested by the PHP
 * runner (npm run test:php); these pin the load-bearing WP constructs.
 */
const files = pluginFiles();

describe("sheets post types and permissions", () => {
  const php = files["sheets/post-types.php"];

  it("registers both CPTs with the character owned per-author", () => {
    expect(php).toContain("register_post_type('chr_character'");
    expect(php).toContain("register_post_type('chr_template'");
    // Own-posts-only editing is core capability mapping, not a hand-rolled ACL:
    expect(php).toContain("'capability_type' => ['chr_character', 'chr_characters']");
    expect(php).toContain("'map_meta_cap' => true");
  });

  it("creates a player role that cannot edit others' characters", () => {
    expect(php).toContain("add_role(");
    expect(php).toContain("'edit_chr_characters' => true");
    expect(php).not.toContain("'edit_others_chr_characters' => true");
    // ...while administrators are granted the full set:
    expect(php).toContain("edit_others_chr_characters");
  });

  it("keeps exactly one active character per player", () => {
    expect(php).toContain("chr_active");
    expect(php).toContain("save_post_chr_character");
  });

  it("resolves property details as character override over template default", () => {
    expect(php).toContain("function chronicler_sheets_get_detail(");
    expect(php).toContain("chr_detail_");
    const r = files["sheets/render.php"];
    expect(r).toContain("chr-prop__detail");
    expect(r).toContain("chr-prop__detail-item");
    const a = files["sheets/admin.php"];
    expect(a).toContain("chr_detail[");
    expect(a).toContain("delete_post_meta($post_id, 'chr_detail_'");
  });

  it("normalizes stored list values to arrays", () => {
    expect(php).toContain("case 'checklist':");
    expect(php).toContain("case 'list':");
  });

  it("resolves Slack users through user meta to the active character", () => {
    expect(php).toContain("chronicler_slack_user_id");
    expect(php).toContain("function chronicler_sheets_character_for_slack_id(");
  });

  it("is loaded by the plugin loader with an activation hook", () => {
    const loader = files["chronicler.php"];
    expect(loader).toContain("require_once __DIR__ . '/sheets/post-types.php'");
    expect(loader).toContain("register_activation_hook(__FILE__, 'chronicler_sheets_activate')");
  });
});

describe("sheets admin", () => {
  const php = files["sheets/admin.php"];

  it("gates the configurator and validates before storing", () => {
    expect(php).toContain("add_submenu_page");
    expect(php).toContain("manage_options");
    expect(php).toContain("check_admin_referer");
    // Nothing invalid is ever stored:
    expect(php).toContain("chronicler_sheets_parse_template");
    expect(php).toContain("is_wp_error");
  });

  it("stores the schema as a chr_template post and points the option at it", () => {
    expect(php).toContain("chronicler_active_template");
    expect(php).toContain("chr_template");
  });

  it("adds the Slack-id profile field, editable by admins only", () => {
    expect(php).toContain("chronicler_slack_user_id");
    expect(php).toContain("edit_user_profile");
    expect(php).toContain("manage_options");
  });

  it("gives characters an Active meta box", () => {
    expect(php).toContain("add_meta_box");
    expect(php).toContain("chr_active");
  });

  it("renders a schema-generated Stat Block meta box saved through the validator", () => {
    expect(php).toContain("'chronicler-stat-block'");
    expect(php).toContain("chr_stat[");
    expect(php).toContain("chr_stat_present[");
    expect(php).toContain("chronicler_sheets_apply_op(");
    expect(php).toContain("chronicler_stat_block_nonce");
    expect(php).toContain("stat-block.js");
  });

  it("adds a Goes-by character field stored as chr_goes_by", () => {
    const admin = files["sheets/admin.php"];
    expect(admin).toContain("chr_goes_by");
    expect(admin).toContain("Goes by");
    expect(admin).toContain("chronicler_goesby_nonce");
  });

  it("populates the profile Slack field from a cached users.list", () => {
    const admin = files["sheets/admin.php"];
    expect(admin).toContain("chronicler_sheets_slack_user_directory");
    expect(admin).toContain("users.list");
    expect(admin).toContain("chronicler_sheets_parse_slack_users");
    expect(admin).toContain("<select");
  });
});

describe("sheets REST surface", () => {
  const php = files["sheets/rest.php"];

  it("registers both routes in the chronicler/v1 namespace", () => {
    expect(php).toContain("register_rest_route");
    expect(php).toContain("'chronicler/v1'");
    expect(php).toContain("/sheet");
    expect(php).toContain("/properties/");
  });

  it("guards writes with edit_post and leaves reads public", () => {
    expect(php).toContain("current_user_can('edit_post'");
    expect(php).toContain("'__return_true'");
  });

  it("sanitizes text at the write boundary and validates through apply_op", () => {
    expect(php).toContain("sanitize_text_field");
    expect(php).toContain("wp_kses_post");
    expect(php).toContain("chronicler_sheets_apply_op");
  });

  it("is loaded by the plugin loader", () => {
    const loader = files["chronicler.php"];
    expect(loader).toContain("require_once __DIR__ . '/sheets/rest.php'");
  });

  it("rejects writes to non-live properties at the surface, not the UI", () => {
    expect(php).toContain("chronicler_sheets_is_live(");
    expect(php).toContain("changes on level-up");
  });

  it("registers the character-names route gated to session editors", () => {
    expect(php).toContain("/characters/names");
    expect(php).toContain("chronicler_sheets_rest_character_names");
    expect(php).toContain("edit_others_chr_characters");
  });
});

describe("sheets front-end render", () => {
  const php = files["sheets/render.php"];

  it("renders through the_content (theme-agnostic), not template_include", () => {
    expect(php).toContain("add_filter('the_content'");
    expect(php).toContain("is_singular('chr_character')");
  });

  it("emits the markup contract sheet.js binds to", () => {
    for (const hook of [
      "data-chronicler-sheet",
      "chronicler-sheet-boot",
      "chr-track__box",
      "data-step",
      "chr-prop__display",
      "chr-sheet__error",
    ]) {
      expect(php).toContain(hook);
    }
    expect(php).toContain("wp_create_nonce('wp_rest')");
  });

  it("ships assets as a version-proof module tag plus enqueued style", () => {
    expect(php).toContain("wp_enqueue_style");
    expect(php).toContain('type="module"');
    expect(php).toContain("CHRONICLER_PLUGIN_FILE");
  });

  it("gives characters the masthead editor and excerpt-backed tagline", () => {
    expect(files["sheets/post-types.php"]).toContain(
      "'supports' => ['title', 'editor', 'author', 'thumbnail', 'excerpt', 'page-attributes']",
    );
    const a = files["sheets/admin.php"];
    expect(a).toContain("edit_form_after_title");
    expect(a).toContain('name="excerpt"');
    expect(a).toContain("wp_editor_settings");
  });

  it("feeds the subhead into the masthead and only activates controls on live properties", () => {
    const r = files["sheets/render.php"];
    expect(r).toContain("chronicler_sheets_render_sheet(get_the_ID(), $content)");
    expect(r).toContain("chr-masthead");
    expect(r).toContain("wp_get_attachment_image(");
    expect(r).toContain("post_thumbnail_html");
    // The masthead owns the H1; the theme's title block is suppressed.
    expect(r).toContain('<h1 class="chr-masthead__name">');
    expect(r).toContain("Played by ");
    // Tagline is the raw excerpt (plain text); the intro is the post content,
    // rendered between the header card and the stat block. Masthead-flagged
    // sections render as traits inside the card.
    expect(r).toContain("get_post_field('post_excerpt'");
    expect(r).toContain("chr-sheet__intro");
    expect(r).toContain("chr-masthead__trait--primary");
    expect(r).toContain("$section['masthead']");
    // Named styling hooks for site CSS, derived from schema names.
    expect(r).toContain("chr-section--");
    expect(r).toContain("chr-prop--");
    expect(r).toContain("render_block_core/post-title");
    // Characters carry their own headerless block template (WP 6.7+).
    expect(r).toContain("register_block_template");
    expect(r).toContain("single-chr_character");
    expect(r).toContain("chronicler_sheets_is_live(");
    expect(r).toContain("chr-prop__static");
    expect(r).toContain("chr-list");
  });

  it("renders rich-text longtext as sanitized markup, not escaped source", () => {
    const r = files["sheets/render.php"];
    expect(r).toContain("wpautop(wp_kses_post(");
    // Rich text makes the display badge a raw-markup echo; longtext skips it.
    expect(r).toContain("$property['type'] === 'longtext'");
  });
});

describe("stat block admin polish", () => {
  const php = files["sheets/admin.php"];

  it("uses the rich-text editor for top-level longtext, plain rows inside lists", () => {
    expect(php).toContain("wp_editor(");
    expect(php).toContain("'textarea_name' => $name");
    // Editor ids allow [a-z0-9_] only; the bracketed name travels separately.
    expect(php).toContain("preg_replace('/[^a-z0-9_]/', '_', $name)");
  });

  it("ships the meta box stylesheet alongside the row script", () => {
    expect(php).toContain("stat-block.css");
    expect(files["sheets/stat-block.css"]).toContain(".chr-list-row");
  });
});
