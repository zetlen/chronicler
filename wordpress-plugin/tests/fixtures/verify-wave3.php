<?php
// wp-env verification driver for the Wave-3 integration fixes — NOT part of
// tests/run.php (it needs a live WordPress; pair it with
// verify-wave3-mock.php mapped in as an mu-plugin). Run inside wp-env:
//
//   npx @wordpress/env run cli wp eval-file \
//     wp-content/plugins/wordpress-plugin/tests/fixtures/verify-wave3.php <case>
//
// Cases:
//   seed            — create a fixture Session whose stored messages carry
//                     capability-gated mirror-route srcs in images[] AND
//                     avatarHtml/bodyHtml (both permalink forms, plus the
//                     Node app's legacy /api/slack-image form), built on the
//                     message-render parity fixtures. Prints SESSION_ID.
//   assert <postId> — after driving Generate + Publish in the editor:
//                     the SAVED content carries ZERO chronicler/v1/image or
//                     /api/slack-image occurrences, every image src is a
//                     local uploads URL, and every mirrored attachment is
//                     parented to the post. Prints IMG_SRC= lines for the
//                     anonymous-reader curl checks.
//   proxy           — conversations.list accepts and FORWARDS types/
//                     exclude_archived (observable via the mock's echo);
//                     an invalid types value is a 400 chronicler_bad_args.

use Chronicler\Media\Mirror;
use Chronicler\Settings\Screen;
use Chronicler\Store\Sessions;

$case = $args[0] ?? '';
wp_set_current_user(1); // admin

$GLOBALS['w3_pass'] = 0;
$GLOBALS['w3_fail'] = 0;
function w3(string $desc, bool $ok, string $detail = ''): void
{
    if ($ok) {
        $GLOBALS['w3_pass']++;
        echo "ok   - $desc\n";
    } else {
        $GLOBALS['w3_fail']++;
        echo "FAIL - $desc" . ($detail !== '' ? " — $detail" : '') . "\n";
    }
}

// The three Slack URLs the fixture references (all served by the mock).
const W3_FILE_URL = 'https://files.slack.com/files-pri/T1-F1/tide-rises.png';
const W3_AVATAR_URL = 'https://avatars.slack-edge.com/2026-07/U1_72.png';
const W3_LEGACY_URL = 'https://files.slack.com/files-pri/T1-F2/undertow.png';

/** The fixture messages: parity attributes + proxied-src bearers. */
function w3_messages(): array
{
    $fixtures = json_decode(file_get_contents(__DIR__ . '/message-render.json'), true);
    $messages = array_map(
        static fn (array $case): array => $case['attributes'],
        $fixtures['parity']
    );
    // Pretty-permalink mirror form in bodyHtml + images[]; plain-permalink
    // form (attr-escaped: & -> &amp;) in avatarHtml — exactly what the #96
    // session editor persists (components/admin/imageUrls.ts).
    $pretty = '/wp-json/chronicler/v1/image?url=' . rawurlencode(W3_FILE_URL);
    $plain = '/?rest_route=/chronicler/v1/image&url=' . rawurlencode(W3_AVATAR_URL);
    $plainAttr = str_replace('&', '&amp;', $plain);
    $messages[] = [
        'rootClass' => 'slk-msg slk-msg--text',
        'authorName' => 'Daisy',
        'authorColor' => '#3d6bbf',
        'authorColorDark' => '#6a93d8',
        'avatarHtml' => '<div class="slk-msg__avatar"><span class="slk-avatar">'
            . '<img class="slk-avatar__img" src="' . $plainAttr . '" alt="D" loading="lazy"></span></div>',
        'bodyHtml' => 'the tide rises <figure class="slk-image">'
            . '<img src="' . $pretty . '" alt="the tide" loading="lazy"><figcaption>The tide rises</figcaption></figure>',
        'images' => [[
            'src' => $pretty,
            'alt' => 'the tide',
            'caption' => 'The tide rises',
        ]],
    ];
    $messages[] = [
        'rootClass' => 'slk-msg slk-msg--text',
        'authorName' => 'GM',
        'authorColor' => '#c0392b',
        'authorColorDark' => '#d85d50',
        'bodyHtml' => 'undertow',
        'images' => [[
            'src' => '/api/slack-image?url=' . rawurlencode(W3_LEGACY_URL),
            'alt' => 'undertow',
        ]],
    ];
    return $messages;
}

switch ($case) {
    case 'seed':
        $session = Sessions::create([
            'integration' => 'slack',
            'channel' => ['id' => 'C0700', 'name' => 'dargon-kween'],
            'start' => '2026-07-10T19:00',
            'end' => '2026-07-11T02:00',
            'rule_ids' => [],
            'editorState' => ['scheme' => 'light'],
            'messages' => w3_messages(),
        ]);
        w3('fixture session created', is_array($session));
        w3('session stores all messages', ($session['messageCount'] ?? 0) === count(w3_messages()));
        echo 'SESSION_ID=' . $session['id'] . "\n";
        break;

    case 'assert':
        $postId = (int) ($args[1] ?? 0);
        if ($postId === 0) {
            fwrite(STDERR, "assert needs the post id: ... verify-wave3.php assert <postId>\n");
            exit(1);
        }
        $post = get_post($postId);
        w3('post exists', $post !== null);
        w3('post is published', $post !== null && $post->post_status === 'publish', $post->post_status ?? '');
        $content = $post->post_content ?? '';
        w3('content carries the generated transcript', str_contains($content, 'wp:chronicler/transcript'));
        w3(
            'ZERO chronicler/v1/image occurrences in saved content',
            !str_contains($content, 'chronicler/v1/image')
        );
        w3(
            'ZERO legacy /api/slack-image occurrences in saved content',
            !str_contains($content, 'slack-image')
        );
        w3('no rest_route image form survives either', !str_contains($content, 'rest_route'));

        // Every img src in the saved grammar is a local uploads URL. Two
        // shapes: HTML-attribute srcs inside opaque-HTML strings (Gutenberg
        // serializes the embedded quotes as the u0022 unicode escape) and
        // structured images[].src JSON members.
        $uploads = wp_get_upload_dir()['baseurl'];
        preg_match_all('/src=\\\\u0022(.+?)\\\\u0022/', $content, $matches);
        preg_match_all('/"src":"([^"]+)"/', $content, $structured);
        $srcs = array_values(array_unique(array_merge($matches[1], $structured[1])));
        // The parity fixtures carry one inert non-Slack sample image; only
        // the mirrorable ones must have become uploads URLs.
        $mirrored = array_values(array_filter(
            $srcs,
            static fn (string $src): bool => str_starts_with($src, $uploads)
        ));
        w3('found rewritten srcs in the saved content', count($mirrored) >= 3, wp_json_encode($srcs));
        foreach ([W3_FILE_URL, W3_AVATAR_URL, W3_LEGACY_URL] as $slackUrl) {
            $id = 0;
            $found = get_posts([
                'post_type' => 'attachment',
                'post_status' => 'inherit',
                'posts_per_page' => 1,
                'fields' => 'ids',
                'meta_key' => Mirror::META_KEY,
                'meta_value' => Mirror::mirrorKey($slackUrl),
                'no_found_rows' => true,
            ]);
            $id = (int) ($found[0] ?? 0);
            w3("mirrored attachment exists for $slackUrl", $id > 0);
            if ($id > 0) {
                w3(
                    "attachment $id is parented to post $postId",
                    (int) get_post($id)->post_parent === $postId,
                    'post_parent=' . get_post($id)->post_parent
                );
                $url = wp_get_attachment_url($id);
                w3("attachment $id url is under uploads", is_string($url) && str_starts_with($url, $uploads));
                w3("saved content references attachment $id's url", str_contains($content, $url));
                echo 'IMG_SRC=' . $url . "\n";
            }
        }
        break;

    case 'proxy':
        // A token must exist for the proxy to forward (the mock answers).
        update_option(Screen::OPTION, 'xoxb-wave3-verification', false);

        $request = new WP_REST_Request('POST', '/chronicler/v1/slack/conversations.list');
        $request->set_body_params([
            'types' => 'public_channel,private_channel',
            'exclude_archived' => true,
            'limit' => 200,
        ]);
        $response = rest_do_request($request);
        $data = $response->get_data();
        w3('conversations.list with types/exclude_archived answers 200', $response->get_status() === 200, wp_json_encode($data));
        w3('mock saw conversations.list', ($data['method'] ?? '') === 'conversations.list');
        w3(
            'types forwarded verbatim',
            ($data['received']['types'] ?? null) === 'public_channel,private_channel',
            wp_json_encode($data['received'] ?? null)
        );
        w3(
            'exclude_archived forwarded form-encodable',
            ($data['received']['exclude_archived'] ?? null) === 'true'
        );
        // pre_http_request sees the body before transport encoding, so the
        // normalized int hasn't been stringified yet.
        w3('limit forwarded', in_array($data['received']['limit'] ?? null, [200, '200'], true));
        w3('no token forwarded from the body', !isset($data['received']['token']));

        $request = new WP_REST_Request('POST', '/chronicler/v1/slack/conversations.list');
        $request->set_body_params(['types' => 'public_channel,secret_channel']);
        $response = rest_do_request($request);
        w3('invalid types value is a 400', $response->get_status() === 400, (string) $response->get_status());
        w3(
            'invalid types is chronicler_bad_args',
            ($response->get_data()['code'] ?? '') === 'chronicler_bad_args',
            wp_json_encode($response->get_data())
        );

        delete_option(Screen::OPTION);
        break;

    default:
        fwrite(STDERR, "unknown case: $case\n");
        exit(1);
}

echo ($GLOBALS['w3_fail'] === 0 ? 'W3 OK' : 'W3 FAILED')
    . " — {$GLOBALS['w3_pass']} ok, {$GLOBALS['w3_fail']} failed\n";
exit($GLOBALS['w3_fail'] === 0 ? 0 : 1);
