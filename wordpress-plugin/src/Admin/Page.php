<?php

namespace Chronicler\Admin;

use Chronicler\Capabilities;
use Chronicler\Rest\Routes;

/**
 * The wp-admin home of the session editor (#97): a top-level "Chronicler"
 * menu page that mounts the #96 React bundle onto #chronicler-admin-root.
 *
 * The bundle (admin/dist/chronicler-admin.{js,css}) is build output — vendored
 * into the zip by `npm run build:plugin`, absent from the source tree — so
 * every enqueue is guarded by file_exists, and a missing bundle degrades to an
 * admin notice on the page instead of a broken screen.
 */
final class Page
{
    public const SLUG = 'chronicler';
    public const SCRIPT_HANDLE = 'chronicler-admin';

    /** Hook suffix add_menu_page returned; '' until admin_menu has run. */
    private string $hook = '';

    public function register(): void
    {
        // Priority 9: BEFORE core's _add_post_type_submenus() (priority 10)
        // hangs the chronicler_rule CPT under this menu (#109), and before
        // the Game System / Settings submenus register. addMenu pins the
        // parent slug as the first submenu, so the top-level click keeps
        // landing on the session editor; registered after, the Rules entry
        // would sit first and hijack the top-level link.
        add_action('admin_menu', [$this, 'addMenu'], 9);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
    }

    public function addMenu(): void
    {
        $this->hook = (string) add_menu_page(
            'Chronicler',
            'Chronicler',
            Capabilities::COMPOSE,
            self::SLUG,
            [$this, 'render'],
            'dashicons-book-alt'
        );
        // Registering the parent slug as its own first submenu names the
        // landing entry "Sessions" (#125); left implicit, core auto-inserts
        // it re-labeled "Chronicler", duplicating the menu name.
        add_submenu_page(
            self::SLUG,
            'Chronicler Sessions',
            'Sessions',
            Capabilities::COMPOSE,
            self::SLUG,
            [$this, 'render']
        );
    }

    /** Absolute path to a bundle asset under admin/dist/. */
    private function distPath(string $file): string
    {
        return plugin_dir_path(CHRONICLER_PLUGIN_FILE) . 'admin/dist/' . $file;
    }

    public function enqueue(string $hook_suffix): void
    {
        if ($this->hook === '' || $hook_suffix !== $this->hook) {
            return;
        }
        if (!file_exists($this->distPath('chronicler-admin.js'))) {
            return; // render() shows the not-built notice instead.
        }
        wp_enqueue_script(
            self::SCRIPT_HANDLE,
            plugins_url('admin/dist/chronicler-admin.js', CHRONICLER_PLUGIN_FILE),
            [],
            CHRONICLER_VERSION,
            true
        );
        // Boot contract shared with the #96 bundle:
        //  - apiBase/nonce: same-origin REST with cookie + nonce auth.
        //  - draftSessionUrlTemplate (#102): sprintf-style template — swap
        //    %d for a Session id to get the "draft this session" deep link,
        //    which opens post-new.php pre-seeded with that session's
        //    placeholder block (+ title). The embedded _wpnonce (action
        //    chronicler_draft_session) is what Editor\Generation's
        //    default_content/default_title filters verify, so the template
        //    is only valid for the signed-in user and the nonce's lifetime —
        //    build links from a fresh page load, don't persist them.
        wp_localize_script(self::SCRIPT_HANDLE, 'chroniclerBoot', [
            'apiBase' => esc_url_raw(rest_url(Routes::API_NAMESPACE)),
            'nonce' => wp_create_nonce('wp_rest'),
            'draftSessionUrlTemplate' => admin_url(
                'post-new.php?post_type=post&chronicler_session=%d&_wpnonce='
                . wp_create_nonce(\Chronicler\Editor\Generation::NONCE_ACTION)
            ),
        ]);
        if (file_exists($this->distPath('chronicler-admin.css'))) {
            wp_enqueue_style(
                self::SCRIPT_HANDLE,
                plugins_url('admin/dist/chronicler-admin.css', CHRONICLER_PLUGIN_FILE),
                [],
                CHRONICLER_VERSION
            );
        }
    }

    /**
     * The Sessions ↔ Transcription Rules tab strip (#134): rules exist to
     * shape session transcripts, so both screens carry one nav-tab header —
     * core's own tab idiom — bridging the React Sessions page and the
     * native rules list table. Rules\AdminPage prints the same strip on its
     * screen.
     */
    public static function navTabs(string $active): string
    {
        $tabs = [
            'sessions' => ['Sessions', admin_url('admin.php?page=' . self::SLUG)],
            'rules' => ['Transcription Rules', admin_url('edit.php?post_type=' . \Chronicler\Store\Rules::POST_TYPE)],
        ];
        $html = '<h2 class="nav-tab-wrapper" style="margin-bottom:1em">';
        foreach ($tabs as $key => [$label, $url]) {
            $html .= '<a href="' . esc_url($url) . '" class="nav-tab'
                . ($key === $active ? ' nav-tab-active' : '') . '">' . esc_html($label) . '</a>';
        }
        return $html . '</h2>';
    }

    public function render(): void
    {
        // add_menu_page already gates on the capability; this is the same
        // defense-in-depth backstop the sheets configurator keeps.
        if (!current_user_can(Capabilities::COMPOSE)) {
            wp_die('Insufficient permissions.');
        }
        echo '<div class="wrap"><h1>Chronicler</h1>';
        // The installed version under the wordmark (#77) — the quickest way
        // to confirm which zip a site is actually running.
        echo '<p class="description">Version ' . esc_html(CHRONICLER_VERSION) . '</p>';
        echo self::navTabs('sessions');
        if (!file_exists($this->distPath('chronicler-admin.js'))) {
            echo '<div class="notice notice-warning"><p>The session-editor bundle is not built.'
                . ' Run <code>npm run build:plugin</code> to produce'
                . ' <code>admin/dist/chronicler-admin.js</code>.</p></div>';
        }
        echo '<div id="chronicler-admin-root"></div>';
        echo '</div>';
    }
}
