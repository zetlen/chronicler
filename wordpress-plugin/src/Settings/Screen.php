<?php

namespace Chronicler\Settings;

use Chronicler\Admin\Page;
use Chronicler\Capabilities;

/**
 * The Slack connection settings screen (#98): a classic-admin "Settings"
 * submenu under the Chronicler menu, holding the bot token the #99 Slack
 * client will use. Hand-rolled form + nonce in the sheets configurator's
 * house style (sheets/admin.php), not the Settings API — saves validate
 * against Slack's auth.test before anything is stored, which the Settings
 * API's sanitize-then-store flow makes awkward.
 *
 * Token handling rules:
 * - Stored in the OPTION; a defined CHRONICLER_SLACK_BOT_TOKEN constant
 *   overrides it entirely (the screen then shows a disabled field).
 * - Write-only redisplay: the stored token is never echoed back — the field
 *   shows a masked placeholder (mask()) and always submits fresh input.
 * - The token never reaches the browser or the REST layer (Ground rule 3);
 *   consumers read it server-side via bot_token().
 */
final class Screen
{
    public const SLUG = 'chronicler-settings';
    public const OPTION = 'chronicler_slack_bot_token';
    public const CONSTANT = 'CHRONICLER_SLACK_BOT_TOKEN';
    /** POST field + nonce action for the hand-rolled form. */
    public const FIELD = 'chronicler_slack_bot_token';
    public const NONCE_ACTION = 'chronicler_slack_settings';

    /** Inbound-surface signing secret (bot skeleton): same storage pattern
     * as the bot token — option, constant override, write-only redisplay. */
    public const SECRET_OPTION = 'chronicler_slack_signing_secret';
    public const SECRET_CONSTANT = 'CHRONICLER_SLACK_SIGNING_SECRET';
    /** POST field + nonce action for the signing secret's own form. */
    public const SECRET_FIELD = 'chronicler_slack_signing_secret';
    public const SECRET_NONCE_ACTION = 'chronicler_slack_secret_settings';
    public const SELF_CHECK_NONCE_ACTION = 'chronicler_slack_self_check';

    /**
     * The bot token the Slack client (#99) consumes: constant first, then
     * option; null when neither yields a non-empty string. A defined
     * constant always wins — even one defined empty disables the option
     * (that is what "overrides" means; wp-config.php is authoritative).
     */
    public static function bot_token(): ?string
    {
        if (defined(self::CONSTANT)) {
            $token = constant(self::CONSTANT);
            return is_string($token) && $token !== '' ? $token : null;
        }
        $token = get_option(self::OPTION, '');
        return is_string($token) && $token !== '' ? $token : null;
    }

    /**
     * The Slack signing secret the inbound surface verifies requests with
     * (Slack\Signature): constant first, then option; null when neither
     * yields a non-empty string. bot_token()'s exact precedence contract —
     * a defined constant always wins, even one defined empty.
     */
    public static function signing_secret(): ?string
    {
        if (defined(self::SECRET_CONSTANT)) {
            $secret = constant(self::SECRET_CONSTANT);
            return is_string($secret) && $secret !== '' ? $secret : null;
        }
        $secret = get_option(self::SECRET_OPTION, '');
        return is_string($secret) && $secret !== '' ? $secret : null;
    }

    /**
     * The universal Slack app manifest, read verbatim from the file that
     * ships in the plugin (wordpress-plugin/slack-app-manifest.yml). Surfaced
     * on the settings screen for copy-paste into Slack's "From a manifest"
     * flow. Returns '' if the file is somehow missing so render() degrades to
     * an empty field rather than a fatal. Pure read; unit-tested in run.php.
     */
    public static function manifest_yaml(): string
    {
        $path = dirname(__DIR__, 2) . '/slack-app-manifest.yml';
        $yaml = is_readable($path) ? file_get_contents($path) : false;
        return is_string($yaml) ? $yaml : '';
    }

    /**
     * The manifest with {{REST_BASE}} resolved to this site's chronicler/v1
     * REST base — what the Settings page actually surfaces. Site-specific
     * since the bot skeleton: the slash-command and interactivity Request
     * URLs must point at THIS install. Works under plain permalinks too:
     * rest_url() then yields the ?rest_route= form, and suffix
     * concatenation still produces a valid route URL. $base is injectable
     * for the WP-free harness; production callers pass nothing.
     */
    public static function manifest_rendered(?string $base = null): string
    {
        $base ??= untrailingslashit(rest_url('chronicler/v1'));
        return str_replace('{{REST_BASE}}', $base, self::manifest_yaml());
    }

    /**
     * The "Set up your Slack app" section: a numbered, non-technical
     * walkthrough of Slack's "From a manifest" flow, this site's rendered
     * manifest in a read-only textarea, and a vanilla-JS copy button.
     * Rendered above the bot-token section so the page reads in the order
     * the user works.
     * Pure string builder (no echo, no side effects) so run.php can assert
     * its markup; render() is the only caller.
     */
    public static function setup_section_html(): string
    {
        $manifest = self::manifest_rendered();

        $html  = '<h2>Set up your Slack app</h2>';
        $html .= '<p>Chronicler reads your Slack messages through a small Slack app that you create once. '
            . 'Follow these steps — no coding required.</p>';
        $html .= '<ol style="max-width:40em;line-height:1.7">';
        $html .= '<li>Open <a href="https://api.slack.com/apps" target="_blank" rel="noopener noreferrer">api.slack.com/apps</a> and click <strong>Create New App</strong>.</li>';
        $html .= '<li>Choose <strong>From a manifest</strong>, then pick the Slack workspace you want to chronicle.</li>';
        $html .= '<li>Select the <strong>YAML</strong> tab, delete anything already in the box, and paste the manifest below (use the <strong>Copy</strong> button). Click <strong>Next</strong>, then <strong>Create</strong>.</li>';
        $html .= '<li>On your new app\'s page, click <strong>Install to Workspace</strong>, then <strong>Allow</strong>.</li>';
        $html .= '<li>Open <strong>OAuth &amp; Permissions</strong> and copy the <strong>Bot User OAuth Token</strong> (it starts with <code>xoxb-</code>). Paste it into the <em>Bot token</em> field further down this page and save.</li>';
        $html .= '<li>Still in your app\'s settings, open <strong>Basic Information</strong> → <em>App Credentials</em> and copy the <strong>Signing Secret</strong> into the <em>Signing secret</em> field further down this page. Save it.</li>';
        $html .= '<li>In Slack, invite the bot to every channel you want chronicled by typing <code>/invite @Chronicler</code> in that channel.</li>';
        $html .= '<li>Click <strong>Test inbound endpoint</strong> (bottom of this page) to prove Slack can reach your site.</li>';
        $html .= '</ol>';

        $html .= '<p><strong>Upgrading from an earlier Chronicler?</strong> Open your existing app at api.slack.com → <strong>App Manifest</strong>, replace it with the manifest below, save, and reinstall when Slack prompts. One update covers everything the bot needs.</p>';

        $html .= '<p>'
            . '<button type="button" class="button" id="chronicler-copy-manifest" data-copied-label="Copied!">Copy manifest</button>'
            . ' <span class="description">Paste this into the Slack <strong>YAML</strong> tab in step 3.</span>'
            . '</p>';
        $html .= '<textarea id="chronicler-manifest" class="large-text code" readonly rows="24" '
            . 'onclick="this.select()" spellcheck="false" '
            . 'style="font-family:Menlo,Consolas,monospace;white-space:pre">'
            . esc_textarea($manifest) . '</textarea>';

        // Inline, admin-only copy button. Falls back to select-all when the
        // async clipboard API is unavailable (older browsers, non-HTTPS).
        $html .= "<script>\n"
            . "(function () {\n"
            . "  var b = document.getElementById('chronicler-copy-manifest');\n"
            . "  var t = document.getElementById('chronicler-manifest');\n"
            . "  if (!b || !t) { return; }\n"
            . "  var idle = b.textContent;\n"
            . "  b.addEventListener('click', function () {\n"
            . "    var done = function () {\n"
            . "      b.textContent = b.getAttribute('data-copied-label');\n"
            . "      setTimeout(function () { b.textContent = idle; }, 2000);\n"
            . "    };\n"
            . "    if (navigator.clipboard && navigator.clipboard.writeText) {\n"
            . "      navigator.clipboard.writeText(t.value).then(done, function () { t.focus(); t.select(); });\n"
            . "    } else {\n"
            . "      t.focus(); t.select();\n"
            . "      try { document.execCommand('copy'); done(); } catch (e) {}\n"
            . "    }\n"
            . "  });\n"
            . "})();\n"
            . "</script>";

        return $html;
    }

    /**
     * Masked display form of a token: keep a short leading type prefix
     * ("xoxb-") when present, bullet out the middle, and reveal the last 4
     * characters only when the token is long enough that they identify
     * nothing (e.g. "xoxb-••••w7Fq"). Pure, unit-tested in tests/run.php.
     */
    public static function mask(string $token): string
    {
        $dash = strpos($token, '-');
        $prefix = ($dash !== false && $dash < 8) ? substr($token, 0, $dash + 1) : '';
        $body = substr($token, strlen($prefix));
        $tail = strlen($body) > 8 ? substr($body, -4) : '';
        return $prefix . "\u{2022}\u{2022}\u{2022}\u{2022}" . $tail;
    }

    /**
     * Pure interpretation of a JSON-decoded auth.test response body.
     * Returns ['ok' => true, 'team' => ..., 'user' => ...] on success,
     * ['ok' => false, 'error' => <slack error code>] otherwise.
     * Unit-tested in tests/run.php; the HTTP transport stays in authTest().
     */
    public static function interpret_auth_test(mixed $body): array
    {
        if (!is_array($body)) {
            return ['ok' => false, 'error' => 'invalid_response'];
        }
        if (empty($body['ok'])) {
            $error = $body['error'] ?? null;
            return [
                'ok' => false,
                'error' => is_string($error) && $error !== '' ? $error : 'unknown_error',
            ];
        }
        return [
            'ok' => true,
            'team' => is_string($body['team'] ?? null) ? $body['team'] : '',
            'user' => is_string($body['user'] ?? null) ? $body['user'] : '',
        ];
    }

    /**
     * Pure verdict for a self-check outcome (status, decoded JSON body,
     * elapsed ms). ok:true only for a 200 carrying a real command reply —
     * the dispatcher's ephemeral message — so a WAF serving a friendly
     * 200 HTML page cannot masquerade as success. The time verdict is the
     * spec's ack-budget measurement: Slack allows 3000ms end to end.
     */
    public static function interpret_self_check(int $status, mixed $body, int $ms): array
    {
        if ($status === 200) {
            if (is_array($body) && ($body['response_type'] ?? null) === 'ephemeral') {
                $verdict = $ms <= 1000
                    ? "responded in {$ms}ms — comfortably inside Slack's 3-second window."
                    : ($ms <= 3000
                        ? "responded in {$ms}ms — inside Slack's 3-second window, but watch this number."
                        : "responded in {$ms}ms — SLOWER than Slack's 3-second window; commands will time out. See Deferred in the bot spec.");
                return ['ok' => true, 'summary' => 'Inbound endpoint works: ' . $verdict];
            }
            return ['ok' => false, 'summary' => 'The endpoint answered 200 but not with a command reply — something else (a cache? a placeholder page?) is serving this URL.'];
        }
        return ['ok' => false, 'summary' => match ($status) {
            401 => 'The endpoint rejected the signature (401). The saved signing secret differs from the one this check signed with — re-save the secret and retry.',
            503 => 'The endpoint reports no signing secret configured (503). Save the signing secret above, then retry.',
            403 => 'Blocked with 403 before reaching the plugin — typically a security plugin or the host\'s web application firewall (on DreamHost: "Extra Web Security"). Allow /wp-json/chronicler/v1/slack/inbound/* and retry.',
            404 => 'The REST route was not found (404): re-save permalinks (Settings → Permalinks), and check that no security plugin disables the REST API.',
            default => "Unexpected response (HTTP $status, {$ms}ms).",
        }];
    }

    /**
     * POST a correctly signed synthetic help command to this site's own
     * PUBLIC inbound endpoint. Proves the exact path Slack
     * uses — DNS, TLS, WAF, permalinks, route, signature, dispatch — with
     * no workspace involved, and measures the ack budget. Read-only from
     * the handler's perspective: help writes nothing.
     */
    private function selfCheck(string $secret): array
    {
        $body = http_build_query([
            'command' => \Chronicler\Slack\Bot\Commands::COMMAND,
            'text' => 'help',
            'user_id' => 'USELFCHECK',
            'team_id' => 'TSELFCHECK',
            'channel_id' => 'CSELFCHECK',
        ]);
        $timestamp = (string) time();
        $started = microtime(true);
        $response = wp_remote_post(rest_url('chronicler/v1/slack/inbound/commands'), [
            'timeout' => 10,
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded',
                'X-Slack-Request-Timestamp' => $timestamp,
                'X-Slack-Signature' => \Chronicler\Slack\Signature::compute($secret, $timestamp, $body),
            ],
            'body' => $body,
        ]);
        $ms = (int) round((microtime(true) - $started) * 1000);
        if (is_wp_error($response)) {
            return ['ok' => false, 'summary' =>
                'Could not reach the endpoint at all: ' . $response->get_error_message()
                . ' (Loopback requests can be blocked in dev environments; on real hosting this means Slack cannot reach the site either.)'];
        }
        return self::interpret_self_check(
            (int) wp_remote_retrieve_response_code($response),
            json_decode(wp_remote_retrieve_body($response), true),
            $ms
        );
    }

    public function register(): void
    {
        // Same admin_menu pass as Page::addMenu; chronicler.php registers
        // this screen after admin/page.php, so the parent entry exists by
        // the time this callback runs.
        add_action('admin_menu', [$this, 'addMenu']);
    }

    public function addMenu(): void
    {
        add_submenu_page(
            Page::SLUG,
            'Chronicler Settings',
            'Settings',
            // The bot token lives here — configuration, not drafting, so the
            // manage tier (#159).
            Capabilities::MANAGE,
            self::SLUG,
            [$this, 'render']
        );
    }

    /**
     * One minimal outbound call to Slack's auth.test with the candidate
     * token. Deliberately local and throwaway: the real Slack client (#99)
     * doesn't exist yet and will subsume this.
     */
    private function authTest(string $token): array
    {
        $response = wp_remote_post('https://slack.com/api/auth.test', [
            'timeout' => 10,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
        ]);
        if (is_wp_error($response)) {
            return ['ok' => false, 'error' => 'http_error: ' . $response->get_error_message()];
        }
        return self::interpret_auth_test(json_decode(wp_remote_retrieve_body($response), true));
    }

    public function render(): void
    {
        // add_submenu_page already gates on the capability; same
        // defense-in-depth backstop as Admin\Page and the sheets screens.
        if (!current_user_can(Capabilities::MANAGE)) {
            wp_die('Insufficient permissions.');
        }

        $constant_active = defined(self::CONSTANT);
        $secret_constant_active = defined(self::SECRET_CONSTANT);
        $notice = '';
        $error = '';

        // The disabled field never submits, so a FIELD arriving while the
        // constant is active is a hand-crafted request — ignore it.
        if (isset($_POST[self::FIELD]) && !$constant_active) {
            check_admin_referer(self::NONCE_ACTION);
            $token = trim(sanitize_text_field(wp_unslash($_POST[self::FIELD])));
            if ($token === '') {
                delete_option(self::OPTION);
                $notice = 'Slack bot token cleared. Chronicler cannot reach Slack until a new token is saved.';
            } else {
                $result = $this->authTest($token);
                if ($result['ok']) {
                    // Not autoloaded: read only when a Slack call is made.
                    update_option(self::OPTION, $token, false);
                    $notice = sprintf(
                        'Connected to the %s workspace as %s.',
                        $result['team'] !== '' ? $result['team'] : '(unnamed)',
                        $result['user'] !== '' ? $result['user'] : '(unknown bot)'
                    );
                } else {
                    // Do NOT store a token Slack rejected.
                    $error = sprintf(
                        'Slack rejected the token (%s). The previous setting is unchanged.',
                        $result['error']
                    );
                }
            }
        }

        // The signing secret's own form (separate from the token's — that
        // form's "empty clears" contract would otherwise fire on every
        // secret save). No remote validation exists for a signing secret;
        // the inbound self-check below is how you prove it.
        if (isset($_POST[self::SECRET_FIELD]) && !$secret_constant_active) {
            check_admin_referer(self::SECRET_NONCE_ACTION);
            $secret = trim(sanitize_text_field(wp_unslash($_POST[self::SECRET_FIELD])));
            if ($secret === '') {
                delete_option(self::SECRET_OPTION);
                $notice = 'Slack signing secret cleared. The inbound bot endpoints will refuse all requests until a new secret is saved.';
            } else {
                update_option(self::SECRET_OPTION, $secret, false);
                $notice = 'Slack signing secret saved. Use "Test inbound endpoint" below to prove Slack can reach this site.';
            }
        }

        if (isset($_POST['chronicler_self_check'])) {
            check_admin_referer(self::SELF_CHECK_NONCE_ACTION);
            $secret_now = self::signing_secret();
            if ($secret_now === null) {
                $error = 'Save a signing secret before testing the inbound endpoint.';
            } else {
                $result = $this->selfCheck($secret_now);
                if ($result['ok']) {
                    $notice = $result['summary'];
                } else {
                    $error = $result['summary'];
                }
            }
        }

        $stored = get_option(self::OPTION, '');
        $stored = is_string($stored) ? $stored : '';
        $effective = self::bot_token();

        echo '<div class="wrap"><h1>Chronicler Settings</h1>';
        if ($notice !== '') {
            echo '<div class="notice notice-success"><p>' . esc_html($notice) . '</p></div>';
        }
        if ($error !== '') {
            echo '<div class="notice notice-error"><p>' . esc_html($error) . '</p></div>';
        }

        // Bot features configured in Slack but dead at the endpoint: the
        // one misconfiguration users actually hit mid-upgrade.
        if (self::bot_token() !== null && self::signing_secret() === null) {
            echo '<div class="notice notice-warning"><p>A bot token is saved but no <strong>signing secret</strong> is. '
                . 'Slack slash commands and shortcuts will not work until the signing secret from your app\'s '
                . '<em>Basic Information</em> page is saved below.</p></div>';
        }

        echo self::setup_section_html();

        echo '<hr style="margin:2em 0">';
        echo '<h2>Slack connection</h2>';
        echo '<p>Connect Chronicler to your Slack workspace with your Slack app\'s bot token. The token is stored privately on your site.</p>';
        echo '<form method="post">';
        wp_nonce_field(self::NONCE_ACTION);
        echo '<table class="form-table" role="presentation">';

        echo '<tr><th scope="row"><label for="chronicler-slack-bot-token">Bot token</label></th><td>';
        if ($constant_active) {
            // Constant override: show where it comes from, disable the field.
            // A disabled input never submits, so nothing here can be saved.
            echo '<input type="text" id="chronicler-slack-bot-token" class="regular-text" disabled value="'
                . esc_attr($effective !== null ? self::mask($effective) : '(empty)') . '">';
            echo '<p class="description">Configured in <code>wp-config.php</code> via the <code>'
                . esc_html(self::CONSTANT) . '</code> constant, which overrides anything saved on this screen. Remove the constant to manage the token here.</p>';
        } else {
            // Write-only: value is always empty; the mask is a placeholder.
            echo '<input type="password" id="chronicler-slack-bot-token" name="' . esc_attr(self::FIELD)
                . '" class="regular-text" value="" autocomplete="new-password" spellcheck="false" placeholder="'
                . esc_attr($stored !== '' ? self::mask($stored) : 'xoxb-...') . '">';
            if ($stored !== '') {
                echo '<p class="description">A token ending in <code>' . esc_html(self::mask($stored))
                    . '</code> is saved. Enter a new token to replace it, or save with the field empty to clear it.</p>';
            } else {
                echo '<p class="description">The bot token from your Slack app\'s <em>OAuth &amp; Permissions</em> page (<code>xoxb-…</code>). It\'s checked with Slack when you save.</p>';
            }
        }
        echo '</td></tr>';

        // Deliberately absent: no user-token row until user tokens ship (a
        // settings screen must not advertise roadmap). The signing secret —
        // absent while #104's no-inbound policy held — now lives in its own
        // section below, since the bot skeleton reversed that policy
        // (docs/superpowers/specs/2026-07-24-slack-bot-in-plugin-design.md).
        echo '</table>';
        if (!$constant_active) {
            echo '<p><button class="button button-primary">Save &amp; Test Connection</button></p>';
        }
        echo '</form>';

        $stored_secret = get_option(self::SECRET_OPTION, '');
        $stored_secret = is_string($stored_secret) ? $stored_secret : '';
        $effective_secret = self::signing_secret();

        echo '<hr style="margin:2em 0">';
        echo '<h2>Slack bot (inbound)</h2>';
        echo '<p>The <code>' . esc_html(\Chronicler\Slack\Bot\Commands::COMMAND) . '</code> slash command and message shortcuts POST from Slack to this site. '
            . 'Slack signs every request with your app\'s <strong>Signing Secret</strong> '
            . '(the app\'s <em>Basic Information</em> page); Chronicler refuses anything unsigned.</p>';

        echo '<form method="post">';
        wp_nonce_field(self::SECRET_NONCE_ACTION);
        echo '<table class="form-table" role="presentation">';
        echo '<tr><th scope="row"><label for="chronicler-slack-signing-secret">Signing secret</label></th><td>';
        if ($secret_constant_active) {
            echo '<input type="text" id="chronicler-slack-signing-secret" class="regular-text" disabled value="'
                . esc_attr($effective_secret !== null ? self::mask($effective_secret) : '(empty)') . '">';
            echo '<p class="description">Configured in <code>wp-config.php</code> via the <code>'
                . esc_html(self::SECRET_CONSTANT) . '</code> constant, which overrides anything saved on this screen.</p>';
        } else {
            echo '<input type="password" id="chronicler-slack-signing-secret" name="' . esc_attr(self::SECRET_FIELD)
                . '" class="regular-text" value="" autocomplete="new-password" spellcheck="false" placeholder="'
                . esc_attr($stored_secret !== '' ? self::mask($stored_secret) : '') . '">';
            if ($stored_secret !== '') {
                echo '<p class="description">A secret ending in <code>' . esc_html(self::mask($stored_secret))
                    . '</code> is saved. Enter a new one to replace it, or save with the field empty to clear it.</p>';
            } else {
                echo '<p class="description">From your Slack app\'s <em>Basic Information</em> page → <em>App Credentials</em> → <em>Signing Secret</em>.</p>';
            }
        }
        echo '</td></tr></table>';
        if (!$secret_constant_active) {
            echo '<p><button class="button button-primary">Save signing secret</button></p>';
        }
        echo '</form>';

        if (self::signing_secret() !== null) {
            echo '<form method="post">';
            wp_nonce_field(self::SELF_CHECK_NONCE_ACTION);
            echo '<p><button class="button" name="chronicler_self_check" value="1">Test inbound endpoint</button> '
                . '<span class="description">Posts a signed test command to this site\'s own public URL — '
                . 'proves Slack can reach it and measures response time against Slack\'s 3-second limit.</span></p>';
            echo '</form>';
        }

        // Uninstall warning (#174): the Plugins screen's Delete flow can't be
        // annotated at click time (a deactivated plugin's code never runs),
        // so the admins' own settings surface carries the durable warning.
        // Wording mirrors uninstall.php's keep/delete inventory.
        echo '<hr style="margin:2em 0">';
        echo '<h2>Uninstalling</h2>';
        echo '<p><strong>Deactivating</strong> Chronicler keeps all of its data. '
            . '<strong>Deleting</strong> it from the Plugins screen permanently removes its data: '
            . 'saved sessions, character sheets, the game-system template, and transcription rules. '
            . 'Published transcripts are ordinary posts and are kept — along with media you uploaded '
            . 'and images your published transcripts use.</p>';

        echo '</div>';
    }
}
