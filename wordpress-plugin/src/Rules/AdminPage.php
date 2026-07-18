<?php

namespace Chronicler\Rules;

use Chronicler\Rest\Schemas;
use Chronicler\Store\Rules;
use Chronicler\Store\Sessions;
use WP_Post;

/**
 * The standalone wp-admin CRUD screen for Rules (#109), layered on the
 * native CPT list table Store\Rules::registerPostType() turns on: a classic
 * no-build metabox editor (the sheets/admin.php house style), a save_post
 * handler enforcing the SAME validation the REST layer does (both interpret
 * Rest\Schemas::ruleFieldSchemas()), and custom list columns.
 *
 * The chronicler/v1 REST routes stay the canonical write path for the
 * session editor's add-rule menu; this screen is the standalone CRUD. The
 * two surfaces agree on validity by construction, and on visibility by
 * status: only PUBLISHED rules are served (Store\Rules), so draft = parked
 * and trash = removed, with no extra bookkeeping.
 *
 * Rejected saves store NOTHING (the Settings screen's "never store what
 * validation refused" rule). A rule that has never had a valid config is
 * demoted to draft so a config-less shell never sits published; a bad edit
 * to a valid rule leaves the stored config and status untouched and shows
 * an error notice instead.
 */
final class AdminPage
{
    public const NONCE_ACTION = 'chronicler_rule_editor';
    public const NONCE_FIELD = 'chronicler_rule_nonce';
    /** $_POST key carrying the config fields (chronicler_rule[pattern], …). */
    public const FIELD = 'chronicler_rule';
    /** Redirect query arg listing the fields a rejected save flagged. */
    public const INVALID_ARG = 'chronicler_rule_invalid';

    /** Session attach-counts per rule id; computed once per request. */
    private ?array $sessionCounts = null;

    public function register(): void
    {
        add_action('add_meta_boxes_' . Rules::POST_TYPE, [$this, 'addMetaBoxes']);
        add_action('save_post_' . Rules::POST_TYPE, [$this, 'save'], 10, 2);
        add_filter('manage_' . Rules::POST_TYPE . '_posts_columns', [$this, 'columns']);
        add_action('manage_' . Rules::POST_TYPE . '_posts_custom_column', [$this, 'renderColumn'], 10, 2);
        add_filter('list_table_primary_column', [$this, 'primaryColumn'], 10, 2);
        add_filter('post_row_actions', [$this, 'rowActions'], 10, 2);
        // Untrash would otherwise restore to DRAFT (core default since 5.6),
        // silently keeping a restored rule out of the session editor.
        add_filter('wp_untrash_post_status', [$this, 'untrashStatus'], 10, 3);
        add_action('admin_notices', [$this, 'rejectedNotice']);
        // The Sessions ↔ Transcription Rules tab strip (#134), shared with
        // Admin\Page, printed above the list table.
        add_action('all_admin_notices', [$this, 'navTabs']);
        add_filter('removable_query_args', [$this, 'removableArgs']);
    }

    /* ------------------------------------------------------------------ *
     * Vocabulary (pure, exercised by tests/rules-admin.test.php)
     * ------------------------------------------------------------------ */

    /**
     * Short labels for the mode <select> and the Mode list column — keys are
     * exactly Schemas::RULE_MODES (pinned by a test), wording follows the
     * semantics documented in lib/transform/rules.ts. Pure.
     */
    public static function modeOptions(): array
    {
        return [
            'start' => 'Start marker',
            'end' => 'End marker',
            'hide' => 'Hide',
            'addclass' => 'Add CSS class',
            'wp-tag' => 'WordPress tag',
        ];
    }

    /** What each mode does, appended to its <select> label. Pure. */
    public static function modeDetails(): array
    {
        return [
            'start' => 'the transcript begins at the first matching message',
            'end' => 'the transcript ends at the first matching message',
            'hide' => 'matching messages are hidden',
            'addclass' => 'matching messages get the CSS class(es) below',
            'wp-tag' => 'a surviving match proposes the WordPress tag(s) below',
        ];
    }

    /** Notice wording per rejected field; Schemas::ruleErrors() decides. Pure. */
    public static function fieldProblem(string $field, string $problem): string
    {
        return ucfirst($field) . ' ' . $problem . '.';
    }

    /**
     * Sessions attaching each rule, from the raw rule_ids JSON column of
     * every session row: rule id => count. Corrupt JSON counts nothing;
     * duplicate ids within one session count once. Pure.
     */
    public static function countByRule(array $ruleIdJsonLists): array
    {
        $counts = [];
        foreach ($ruleIdJsonLists as $json) {
            $decoded = is_string($json) ? json_decode($json, true) : null;
            $ids = Sessions::normalizeRuleIds(is_array($decoded) ? $decoded : []);
            foreach (array_unique($ids) as $id) {
                $counts[$id] = ($counts[$id] ?? 0) + 1;
            }
        }
        return $counts;
    }

    /* ------------------------------------------------------------------ *
     * Metabox editor
     * ------------------------------------------------------------------ */

    public function addMetaBoxes(): void
    {
        add_meta_box(
            'chronicler-rule',
            'Transcription Rule',
            [$this, 'renderMetaBox'],
            Rules::POST_TYPE,
            'normal',
            'high'
        );
    }

    /**
     * The stored config for any status (the served Rules::get() is
     * publish-only, and the editor must show drafts too), or null when the
     * post has no readable config yet.
     */
    private function storedConfig(int $postId): ?array
    {
        $json = get_post_meta($postId, Rules::META_RULE, true);
        $config = is_string($json) ? json_decode($json, true) : null;
        return is_array($config) ? Rules::normalize($config) : null;
    }

    public function renderMetaBox(WP_Post $post): void
    {
        wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD);
        $config = $this->storedConfig($post->ID) ?? Rules::DEFAULTS;
        $libraryId = get_post_meta($post->ID, Rules::META_LIBRARY_ID, true);

        echo '<table class="form-table" role="presentation">';

        echo '<tr><th scope="row"><label for="chronicler-rule-pattern">Pattern</label></th><td>';
        echo '<input type="text" id="chronicler-rule-pattern" name="' . esc_attr(self::FIELD)
            . '[pattern]" class="large-text code" value="' . esc_attr($config['pattern'])
            . '" spellcheck="false" autocomplete="off" placeholder="^#session-start">';
        echo '<p class="description">Required. A JavaScript regular expression (no surrounding slashes),'
            . ' matched against each message&#8217;s text.</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="chronicler-rule-flags">Flags</label></th><td>';
        echo '<input type="text" id="chronicler-rule-flags" name="' . esc_attr(self::FIELD)
            . '[flags]" class="regular-text code" value="' . esc_attr($config['flags'])
            . '" spellcheck="false" autocomplete="off">';
        echo '<p class="description">JavaScript regex flags, e.g. <code>i</code> or <code>im</code>.'
            . ' <code>g</code> and <code>y</code> are ignored.</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="chronicler-rule-mode">Mode</label></th><td>';
        echo '<select id="chronicler-rule-mode" name="' . esc_attr(self::FIELD) . '[mode]">';
        $details = self::modeDetails();
        foreach (self::modeOptions() as $mode => $label) {
            echo '<option value="' . esc_attr($mode) . '"' . selected($config['mode'], $mode, false) . '>'
                . esc_html($label . ' — ' . $details[$mode]) . '</option>';
        }
        echo '</select>';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="chronicler-rule-classname">CSS class(es)</label></th><td>';
        echo '<input type="text" id="chronicler-rule-classname" name="' . esc_attr(self::FIELD)
            . '[className]" class="regular-text code" value="' . esc_attr($config['className'])
            . '" spellcheck="false" autocomplete="off">';
        echo '<p class="description">Space-separated; applied by the <em>Add CSS class</em> mode'
            . ' so custom CSS can style matching messages.</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="chronicler-rule-tagnames">Tag name(s)</label></th><td>';
        echo '<input type="text" id="chronicler-rule-tagnames" name="' . esc_attr(self::FIELD)
            . '[tagNames]" class="regular-text" value="' . esc_attr($config['tagNames'])
            . '" spellcheck="false" autocomplete="off">';
        echo '<p class="description">Comma-separated WordPress tag names; proposed for the'
            . ' transcript post by the <em>WordPress tag</em> mode.</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="chronicler-rule-description">Description</label></th><td>';
        echo '<textarea id="chronicler-rule-description" name="' . esc_attr(self::FIELD)
            . '[description]" class="large-text" rows="3">' . esc_textarea($config['description']) . '</textarea>';
        echo '<p class="description">Optional note; it also becomes the rule&#8217;s label in lists.</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="chronicler-rule-test">Try it</label></th><td>';
        echo '<input type="text" id="chronicler-rule-test" class="large-text" value=""'
            . ' spellcheck="false" autocomplete="off" placeholder="Paste a sample message to test against">';
        echo '<p class="description" id="chronicler-rule-test-result">Checks the pattern as you type.</p>';
        echo '</td></tr>';

        echo '</table>';
        if (is_string($libraryId) && $libraryId !== '') {
            echo '<p class="description">Imported from an earlier version of Chronicler;'
                . ' re-importing updates this rule in place.</p>';
        }
        $this->testerScript();
    }

    /**
     * The client-side "test this regex" nicety: compiles the pattern with
     * the browser's own RegExp — the engine that will actually run it in
     * the session editor — mirroring compileRule() in lib/transform/rules.ts
     * (trimmed source, g/y stripped). Display-only; the server never trusts
     * it. Inline on purpose: the no-build classic-metabox screen has no
     * bundle to hang a file off.
     */
    private function testerScript(): void
    {
        ?>
        <script>
        (function () {
            var pattern = document.getElementById('chronicler-rule-pattern');
            var flags = document.getElementById('chronicler-rule-flags');
            var sample = document.getElementById('chronicler-rule-test');
            var out = document.getElementById('chronicler-rule-test-result');
            if (!pattern || !flags || !sample || !out) {
                return;
            }
            function update() {
                var source = pattern.value.trim();
                if (!source) {
                    out.textContent = 'Enter a pattern above; it is checked as you type.';
                    out.style.color = '';
                    return;
                }
                var re;
                try {
                    re = new RegExp(source, flags.value.replace(/[gy]/g, ''));
                } catch (err) {
                    out.textContent = 'Invalid pattern: ' + err.message;
                    out.style.color = '#b32d2e';
                    return;
                }
                out.style.color = '';
                if (!sample.value) {
                    out.textContent = 'Pattern compiles. Add sample text to test a match.';
                } else {
                    out.textContent = re.test(sample.value)
                        ? 'Matches the sample text.'
                        : 'No match in the sample text.';
                }
            }
            [pattern, flags, sample].forEach(function (el) {
                el.addEventListener('input', update);
            });
            update();
        })();
        </script>
        <?php
    }

    /* ------------------------------------------------------------------ *
     * Save path
     * ------------------------------------------------------------------ */

    public function save(int $postId, WP_Post $post): void
    {
        if (wp_is_post_revision($postId) || wp_is_post_autosave($postId)) {
            return;
        }
        $nonce = $_POST[self::NONCE_FIELD] ?? null;
        if (!is_string($nonce) || !wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            return; // Not the metabox form (REST/import writes land here too).
        }
        if (!current_user_can('edit_post', $postId)) {
            return;
        }
        $input = isset($_POST[self::FIELD]) && is_array($_POST[self::FIELD])
            ? wp_unslash($_POST[self::FIELD])
            : [];

        $errors = Schemas::ruleErrors($input);
        if ($errors !== []) {
            $this->reject($postId, $post, $errors);
            return;
        }

        $base = $this->storedConfig($postId);
        $config = Rules::normalize($input, $base ?? Rules::DEFAULTS);
        update_post_meta($postId, Rules::META_RULE, wp_slash(wp_json_encode($config)));
        $this->updatePostGuarded([
            'ID' => $postId,
            'post_title' => Rules::adminTitle($config),
        ]);
    }

    /**
     * A rejected save stores nothing. A rule that never had a valid config
     * is demoted to draft (a config-less shell must not sit "published",
     * even though Rules::all() would skip it); the rejected fields ride the
     * redirect for rejectedNotice().
     */
    private function reject(int $postId, WP_Post $post, array $errors): void
    {
        if ($this->storedConfig($postId) === null && !in_array($post->post_status, ['draft', 'trash'], true)) {
            $this->updatePostGuarded([
                'ID' => $postId,
                'post_status' => 'draft',
                'post_title' => '(incomplete rule)',
            ]);
        }
        add_filter('redirect_post_location', static function (string $location) use ($errors): string {
            // Drop core's "published/updated" message; the error notice rules.
            return add_query_arg(
                AdminPage::INVALID_ARG,
                rawurlencode(implode(',', array_keys($errors))),
                remove_query_arg('message', $location)
            );
        });
    }

    /** wp_update_post() without re-entering this save handler. */
    private function updatePostGuarded(array $data): void
    {
        remove_action('save_post_' . Rules::POST_TYPE, [$this, 'save'], 10);
        wp_update_post($data);
        add_action('save_post_' . Rules::POST_TYPE, [$this, 'save'], 10, 2);
    }

    /** The error notice after a rejected save (fields named by reject()). */
    public function rejectedNotice(): void
    {
        if (!isset($_GET[self::INVALID_ARG])) {
            return;
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen === null || $screen->post_type !== Rules::POST_TYPE) {
            return;
        }
        $fields = array_intersect(
            explode(',', sanitize_text_field(wp_unslash($_GET[self::INVALID_ARG]))),
            array_keys(Schemas::ruleFieldSchemas())
        );
        if ($fields === []) {
            return;
        }
        // Re-derive the wording from the shared validator so the notice
        // can never disagree with what save() enforced.
        $problems = Schemas::ruleErrors(array_fill_keys($fields, null) + Rules::DEFAULTS);
        $sentences = [];
        foreach ($fields as $field) {
            $sentences[] = self::fieldProblem($field, $problems[$field] ?? 'is invalid');
        }
        echo '<div class="notice notice-error"><p><strong>The rule was not saved.</strong> '
            . esc_html(implode(' ', $sentences)) . '</p></div>';
    }

    public function removableArgs(array $args): array
    {
        $args[] = self::INVALID_ARG;
        return $args;
    }

    /** The shared Sessions ↔ Transcription Rules tabs, on the list screen only. */
    public function navTabs(): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen === null || $screen->id !== 'edit-' . Rules::POST_TYPE) {
            return;
        }
        echo \Chronicler\Admin\Page::navTabs('rules');
    }

    /* ------------------------------------------------------------------ *
     * List table
     * ------------------------------------------------------------------ */

    public function columns(array $columns): array
    {
        return [
            'cb' => $columns['cb'] ?? '<input type="checkbox" />',
            'pattern' => 'Pattern',
            'mode' => 'Mode',
            'description' => 'Description',
            'sessions' => 'Sessions',
        ];
    }

    /** Row actions hang off Pattern (there is no title column to host them). */
    public function primaryColumn(string $default, string $screenId): string
    {
        return $screenId === 'edit-' . Rules::POST_TYPE ? 'pattern' : $default;
    }

    /**
     * Quick edit would only offer status/date here — and a quick "draft"
     * silently pulls the rule from the session editor — so it goes.
     */
    public function rowActions(array $actions, WP_Post $post): array
    {
        if ($post->post_type === Rules::POST_TYPE) {
            unset($actions['inline hide-if-no-js']);
        }
        return $actions;
    }

    public function renderColumn(string $column, int $postId): void
    {
        $config = $this->storedConfig($postId);
        switch ($column) {
            case 'pattern':
                if ($config === null) {
                    echo '<strong><em>(no rule config yet — edit to complete)</em></strong>';
                    break;
                }
                $text = '/' . $config['pattern'] . '/' . $config['flags'];
                if (current_user_can('edit_post', $postId)) {
                    echo '<strong><a class="row-title" href="' . esc_url((string) get_edit_post_link($postId))
                        . '"><code>' . esc_html($text) . '</code></a></strong>';
                } else {
                    echo '<code>' . esc_html($text) . '</code>';
                }
                break;
            case 'mode':
                $label = $config !== null ? (self::modeOptions()[$config['mode']] ?? $config['mode']) : null;
                echo $label !== null ? esc_html($label) : '&#8212;';
                break;
            case 'description':
                echo $config !== null && $config['description'] !== ''
                    ? esc_html($config['description'])
                    : '&#8212;';
                break;
            case 'sessions':
                echo (int) ($this->sessionCounts()[$postId] ?? 0);
                break;
        }
    }

    /**
     * Attach counts for every rule at once: one SELECT of the sessions
     * table's small rule_ids column (never the message payloads — see
     * Sessions::LIGHT_COLUMNS for the same discipline). Errors (e.g. the
     * table not created yet) just count zero.
     */
    private function sessionCounts(): array
    {
        if ($this->sessionCounts === null) {
            global $wpdb;
            $suppress = $wpdb->suppress_errors();
            $lists = $wpdb->get_col('SELECT rule_ids FROM ' . Sessions::tableName());
            $wpdb->suppress_errors($suppress);
            $this->sessionCounts = self::countByRule(is_array($lists) ? $lists : []);
        }
        return $this->sessionCounts;
    }

    /** Restore trashed rules to their previous status, not core's draft. */
    public function untrashStatus(string $newStatus, int $postId, string $previousStatus): string
    {
        return get_post_type($postId) === Rules::POST_TYPE ? $previousStatus : $newStatus;
    }
}
