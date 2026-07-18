<?php
// Classic-theme /characters template (#66): theme header and footer frame
// the plugin-rendered index. Selected by chronicler_sheets_archive_template()
// only when the theme has no archive-chr_character.php of its own.

if (!defined('ABSPATH')) {
    exit;
}

get_header();
echo '<main class="chr-index-main">';
echo '<h1 class="chr-index-title">' . esc_html(post_type_archive_title('', false)) . '</h1>';
echo chronicler_sheets_render_index();
echo '</main>';
get_footer();
