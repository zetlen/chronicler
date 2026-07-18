<?php
// wp-env verification driver for #102 editor-native generation — NOT part of
// tests/run.php (it needs a live WordPress). Run inside wp-env:
//
//   npx @wordpress/env run cli wp eval-file \
//     wp-content/plugins/wordpress-plugin/tests/fixtures/verify-102.php <case>
//
// Cases:
//   seed       — create the fixture Session (message-render parity messages
//                + an image-bearing message), a wp-tag Rule attached to it,
//                and a pre-mirrored attachment for the image URL (so the
//                image route needs no Slack network). Prints ids.
//   registry   — block type, pattern, script registrations, transcript
//                provenance attributes.
//   seeding    — default_content/default_title through the REAL
//                get_default_post_to_edit() path: authorized seed, wrong
//                nonce, missing capability, unknown session, wrong post type.
//   image-json — GET chronicler/v1/image in both formats via rest_do_request,
//                then the sidebar's attachment-parenting call.

use Chronicler\Editor\Generation;
use Chronicler\Media\Mirror;
use Chronicler\Store\Rules;
use Chronicler\Store\Sessions;

$case = $args[0] ?? 'registry';
wp_set_current_user(1); // admin

// wp eval-file runs this file inside a function, so the counters live in
// $GLOBALS explicitly.
$GLOBALS['v102_pass'] = 0;
$GLOBALS['v102_fail'] = 0;
function v102(string $desc, bool $ok, string $detail = ''): void
{
    if ($ok) {
        $GLOBALS['v102_pass']++;
        echo "ok   - $desc\n";
    } else {
        $GLOBALS['v102_fail']++;
        echo "FAIL - $desc" . ($detail !== '' ? " — $detail" : '') . "\n";
    }
}

const V102_IMAGE_URL = 'https://files.slack.com/files-pri/T1-F1/tide.png';
// 1x1 transparent PNG.
const V102_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

/** The fixture messages: the parity attribute sets + one image bearer. */
function v102_messages(): array
{
    $fixtures = json_decode(
        file_get_contents(__DIR__ . '/message-render.json'),
        true
    );
    $messages = array_map(
        static fn (array $case): array => $case['attributes'],
        $fixtures['parity']
    );
    $messages[] = [
        'rootClass' => 'slk-msg slk-msg--text',
        'authorName' => 'Daisy',
        'authorColor' => '#3d6bbf',
        'authorColorDark' => '#6a93d8',
        'bodyHtml' => 'the tide takes <strong>harm</strong> tonight',
        'images' => [[
            'src' => V102_IMAGE_URL,
            'alt' => 'the tide',
            'caption' => 'The tide rises',
        ]],
    ];
    return $messages;
}

switch ($case) {
    case 'seed':
        $rule = Rules::create([
            'pattern' => 'harm',
            'flags' => 'i',
            'mode' => 'wp-tag',
            'className' => '',
            'tagNames' => 'combat, injuries',
            'description' => 'Tag combat sessions',
        ]);
        v102('wp-tag rule created', is_array($rule));
        $session = Sessions::create([
            'integration' => 'slack',
            'channel' => ['id' => 'C0700', 'name' => 'dargon-kween'],
            'start' => '2026-07-10T19:00',
            'end' => '2026-07-11T02:00',
            'rule_ids' => [$rule['id']],
            'editorState' => ['scheme' => 'light'],
            'messages' => v102_messages(),
        ]);
        v102('fixture session created', is_array($session));
        v102('session stores all messages', ($session['messageCount'] ?? 0) === count(v102_messages()));

        // Pre-mirror the image so GET /image is dedupe-only (no Slack call).
        $upload = wp_upload_bits('tide.png', null, base64_decode(V102_PNG));
        v102('fixture upload written', empty($upload['error']));
        $attachment = wp_insert_attachment([
            'post_mime_type' => 'image/png',
            'post_title' => 'tide',
            'post_status' => 'inherit',
        ], $upload['file']);
        v102('fixture attachment inserted', !is_wp_error($attachment) && $attachment > 0);
        update_post_meta($attachment, Mirror::META_KEY, Mirror::mirrorKey(V102_IMAGE_URL));
        update_post_meta($attachment, Mirror::META_SOURCE, V102_IMAGE_URL);

        echo 'SESSION_ID=' . $session['id'] . "\n";
        echo 'RULE_ID=' . $rule['id'] . "\n";
        echo 'ATTACHMENT_ID=' . $attachment . "\n";
        break;

    case 'registry':
        $block = WP_Block_Type_Registry::get_instance()->get_registered(Generation::BLOCK);
        v102('placeholder block type registered', $block !== null);
        v102('placeholder is apiVersion 3', $block !== null && $block->api_version === 3);
        v102(
            'placeholder editorScript is the registered handle',
            $block !== null && in_array(Generation::PLACEHOLDER_HANDLE, (array) $block->editor_script_handles, true)
        );
        v102(
            'placeholder has the sessionId attribute',
            $block !== null && isset($block->attributes['sessionId'])
                && $block->attributes['sessionId']['type'] === 'number'
        );
        v102('placeholder has no render callback (never renders output)', $block !== null && $block->render_callback === null);

        foreach ([
            Generation::BASE_CSS_HANDLE,
            Generation::LIB_HANDLE,
            Generation::PLACEHOLDER_HANDLE,
            Generation::SIDEBAR_HANDLE,
        ] as $handle) {
            v102("script registered: $handle", wp_script_is($handle, 'registered'));
        }
        $deps = wp_scripts()->registered[Generation::SIDEBAR_HANDLE]->deps ?? [];
        v102('sidebar depends on wp-editor (NOT wp-edit-post)', in_array('wp-editor', $deps, true) && !in_array('wp-edit-post', $deps, true));

        $pattern = WP_Block_Patterns_Registry::get_instance()->get_registered(Generation::PATTERN);
        v102('pattern registered', is_array($pattern));
        v102('pattern postTypes is exactly [post]', ($pattern['postTypes'] ?? null) === ['post']);
        v102('pattern has NO blockTypes (starter modal stays off)', empty($pattern['blockTypes']));
        v102('pattern content carries the placeholder', str_contains($pattern['content'] ?? '', '<!-- wp:chronicler/session-placeholder /-->'));

        $transcript = WP_Block_Type_Registry::get_instance()->get_registered('chronicler/transcript');
        v102(
            'transcript registers the provenance attributes',
            $transcript !== null
                && ($transcript->attributes['sessionId']['type'] ?? null) === 'number'
                && ($transcript->attributes['generatedAt']['type'] ?? null) === 'string'
        );
        break;

    case 'seeding':
        require_once ABSPATH . 'wp-admin/includes/post.php';
        $sessionId = (int) ($args[1] ?? 0);
        if ($sessionId === 0) {
            fwrite(STDERR, "seeding needs the session id: ... verify-102.php seeding <id>\n");
            exit(1);
        }

        $seeded = static function () use ($sessionId): WP_Post {
            $_GET['chronicler_session'] = (string) $sessionId;
            $_REQUEST['chronicler_session'] = (string) $sessionId;
            $_GET['_wpnonce'] = wp_create_nonce(Generation::NONCE_ACTION);
            return get_default_post_to_edit('post');
        };

        // Authorized: admin + valid nonce + existing session.
        $post = $seeded();
        v102(
            'authorized deep link seeds the placeholder',
            str_contains($post->post_content, '<!-- wp:chronicler/session-placeholder {"sessionId":' . $sessionId . '} /-->'),
            $post->post_content
        );
        v102('seeded content carries the More cut', str_contains($post->post_content, '<!-- wp:more -->'));
        v102('seeded title is channel + date', $post->post_title === '#dargon-kween — July 10, 2026', $post->post_title);

        // Wrong nonce → untouched defaults.
        $_GET['_wpnonce'] = 'clearly-wrong';
        $post = get_default_post_to_edit('post');
        v102('wrong nonce leaves content unseeded', $post->post_content === '');
        v102('wrong nonce leaves title unseeded', $post->post_title === '');

        // Unknown session id, valid nonce → unseeded.
        $_GET['chronicler_session'] = '999999';
        $_REQUEST['chronicler_session'] = '999999';
        $_GET['_wpnonce'] = wp_create_nonce(Generation::NONCE_ACTION);
        $post = get_default_post_to_edit('post');
        v102('unknown session leaves content unseeded', !str_contains($post->post_content, 'chronicler/session-placeholder'));

        // Wrong post type → unseeded even when everything else is valid.
        $_GET['chronicler_session'] = (string) $sessionId;
        $_REQUEST['chronicler_session'] = (string) $sessionId;
        $page = get_default_post_to_edit('page');
        v102('page post type never seeds', !str_contains($page->post_content, 'chronicler/session-placeholder'));

        // No capability: a subscriber with a VALID nonce still gets nothing.
        $subscriber = get_user_by('login', 'verify102_subscriber')
            ?: get_user_by('id', wp_create_user('verify102_subscriber', wp_generate_password(), 'v102@example.test'));
        wp_set_current_user($subscriber->ID);
        $_GET['_wpnonce'] = wp_create_nonce(Generation::NONCE_ACTION);
        $post = get_default_post_to_edit('post');
        v102('subscriber (no chronicler_compose) never seeds', !str_contains($post->post_content, 'chronicler/session-placeholder'));
        wp_set_current_user(1);
        break;

    case 'image-json':
        $post = wp_insert_post(['post_type' => 'post', 'post_status' => 'draft', 'post_title' => 'v102 target']);
        v102('target post created', !is_wp_error($post) && $post > 0);

        // format=json → 200 {id, url}.
        $request = new WP_REST_Request('GET', '/chronicler/v1/image');
        $request->set_query_params(['url' => V102_IMAGE_URL, 'format' => 'json']);
        $response = rest_do_request($request);
        $data = $response->get_data();
        v102('format=json answers 200', $response->get_status() === 200, (string) $response->get_status());
        v102('format=json body is {id, url}', is_int($data['id'] ?? null) && is_string($data['url'] ?? null), wp_json_encode($data));
        v102('format=json url is the media-library copy', str_contains($data['url'] ?? '', '/wp-content/uploads/'));

        // Default → 302 with Location, unchanged contract.
        $request = new WP_REST_Request('GET', '/chronicler/v1/image');
        $request->set_query_params(['url' => V102_IMAGE_URL]);
        $response = rest_do_request($request);
        v102('default format still 302s', $response->get_status() === 302, (string) $response->get_status());
        v102('302 Location is the same local URL', ($response->get_headers()['Location'] ?? '') === $data['url']);

        // Both responses resolve to the SAME attachment (dedupe by mirror key).
        v102('json id matches the pre-mirrored attachment', get_post_meta($data['id'], Mirror::META_KEY, true) === Mirror::mirrorKey(V102_IMAGE_URL));

        // The sidebar's parenting call: POST /wp/v2/media/{id} {post}.
        $request = new WP_REST_Request('POST', '/wp/v2/media/' . $data['id']);
        $request->set_body_params(['post' => $post]);
        $response = rest_do_request($request);
        v102('media parenting call answers 200', $response->get_status() === 200, (string) $response->get_status());
        v102('attachment is parented to the post', (int) get_post($data['id'])->post_parent === (int) $post);
        v102('parented attachment no longer qualifies for eviction', !in_array($data['id'], Mirror::evictableIds(time() + 30 * 86400), true));
        break;

    default:
        fwrite(STDERR, "unknown case: $case\n");
        exit(1);
}

echo ($GLOBALS['v102_fail'] === 0 ? 'V102 OK' : 'V102 FAILED')
    . " — {$GLOBALS['v102_pass']} ok, {$GLOBALS['v102_fail']} failed\n";
exit($GLOBALS['v102_fail'] === 0 ? 0 : 1);
