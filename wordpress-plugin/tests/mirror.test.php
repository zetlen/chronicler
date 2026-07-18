<?php
// Pure-logic checks for src/Media/Mirror.php (#103). Included by run.php.
// The WordPress HTTP/attachment/cron plumbing (wp_remote_get loop,
// media_handle_sideload, wp_schedule_event, the eviction query) is verified
// at runtime in wp-env, not here.

use Chronicler\Media\Mirror;

// --- Allowlist parity with lib/slack/imageHosts.ts ---------------------------
// Same hosts, same semantics: exact https hostnames only. The look-alike and
// scheme cases mirror lib/slack/imageHosts.test.ts so the two allowlists
// cannot drift apart silently.

check(
    'allowlist carries exactly the four Slack-operated hosts',
    Mirror::ALLOWED_HOSTS === [
        'files.slack.com',
        'avatars.slack-edge.com',
        'secure.gravatar.com',
        'a.slack-edge.com',
    ],
    implode(',', Mirror::ALLOWED_HOSTS)
);
foreach (Mirror::ALLOWED_HOSTS as $host) {
    check("allows https://$host", Mirror::isAllowedUrl("https://$host/x.png"));
}
check('rejects a subdomain look-alike', !Mirror::isAllowedUrl('https://files.slack.com.evil.com/x'));
check('rejects an allowlisted name in the path', !Mirror::isAllowedUrl('https://evil.com/files.slack.com'));
check('rejects plain http', !Mirror::isAllowedUrl('http://files.slack.com/x'));
check('rejects an allowlisted name as userinfo', !Mirror::isAllowedUrl('https://files.slack.com@evil.com/x'));
check('rejects a subdomain of an allowlisted host', !Mirror::isAllowedUrl('https://sub.files.slack.com/x'));
check('rejects garbage', !Mirror::isAllowedUrl('not a url'));
check('rejects a scheme-relative url', !Mirror::isAllowedUrl('//files.slack.com/x'));
check('host comparison is case-insensitive', Mirror::isAllowedUrl('HTTPS://FILES.SLACK.COM/x.png'));
// URL.hostname in the TS helper excludes the port, so a nonstandard port on
// an allowlisted host passes there too — parity, not a hole (host is pinned).
check('port does not defeat the host match', Mirror::isAllowedUrl('https://files.slack.com:8443/x.png'));

// --- Auth targeting: the token only ever goes to files.slack.com -------------

check('files.slack.com requires auth', Mirror::requiresSlackAuth('https://files.slack.com/f.png'));
check('avatars host gets no token', !Mirror::requiresSlackAuth('https://avatars.slack-edge.com/a.png'));
check('gravatar gets no token', !Mirror::requiresSlackAuth('https://secure.gravatar.com/avatar/0'));
check('slack-edge gets no token', !Mirror::requiresSlackAuth('https://a.slack-edge.com/i.png'));
check('a files.slack.com look-alike gets no token', !Mirror::requiresSlackAuth('https://files.slack.com.evil.com/f'));

// --- Hash keying: mirror-on-first-fetch dedupe -------------------------------

$key = Mirror::mirrorKey('https://files.slack.com/files-pri/T0-F0/img.png');
check('mirror key is sha256 hex', preg_match('/^[0-9a-f]{64}$/', $key) === 1, $key);
check('mirror key is deterministic', Mirror::mirrorKey('https://files.slack.com/files-pri/T0-F0/img.png') === $key);
check('different urls key differently', Mirror::mirrorKey('https://files.slack.com/files-pri/T0-F1/img.png') !== $key);
check('the query string is part of the identity', Mirror::mirrorKey('https://files.slack.com/files-pri/T0-F0/img.png?w=64') !== $key);
check('meta key name is the #102 contract', Mirror::META_KEY === 'chronicler_mirror_key');

// --- Redirect-hop policy (the SSRF guard, as pure decisions) -----------------
// Ports the fetchAllowedImage cases from lib/slack/imageHosts.test.ts:
// every hop re-checked, off-allowlist refusal, bounded hop count.

$gravatar = 'https://secure.gravatar.com/avatar/0';

$d = Mirror::redirectDecision(200, null, $gravatar, 0);
check('a 200 is final', ($d['final'] ?? false) === true);
$d = Mirror::redirectDecision(404, null, $gravatar, 0);
check('a 404 is final (fails later on status, not as a redirect)', ($d['final'] ?? false) === true);

// The metadata-endpoint case: an allowlisted host 302s to an internal
// address; the decision must refuse it so the loop never fetches it.
$d = Mirror::redirectDecision(302, 'http://169.254.169.254/latest/meta-data/', $gravatar, 0);
check('redirect to an internal address is refused', is_wp_error($d));
check('off-allowlist hop is a blocked host (400)', is_wp_error($d) && ($d->get_error_data()['status'] ?? 0) === 400);
check(
    'off-allowlist hop carries the disallowed-host code',
    is_wp_error($d) && $d->get_error_code() === 'chronicler_mirror_disallowed_host'
);
$d = Mirror::redirectDecision(302, 'https://files.slack.com.evil.com/x', $gravatar, 0);
check('redirect to a look-alike host is refused', is_wp_error($d));

// A hop that stays on the allowlist is followed (gravatar → Slack default).
$d = Mirror::redirectDecision(302, 'https://a.slack-edge.com/default.png', $gravatar, 0);
check('allowlisted hop is followed', ($d['next'] ?? null) === 'https://a.slack-edge.com/default.png');
$d = Mirror::redirectDecision(301, 'https://a.slack-edge.com/moved.png', $gravatar, 0);
check('301 follows like 302', ($d['next'] ?? null) === 'https://a.slack-edge.com/moved.png');
$d = Mirror::redirectDecision(307, 'https://a.slack-edge.com/t.png', $gravatar, 0);
check('307 follows like 302', ($d['next'] ?? null) === 'https://a.slack-edge.com/t.png');

// Relative Locations resolve against the CURRENT hop before re-checking.
$d = Mirror::redirectDecision(302, '/avatar/other', $gravatar, 0);
check('root-relative hop stays on the host', ($d['next'] ?? null) === 'https://secure.gravatar.com/avatar/other');
$d = Mirror::redirectDecision(302, 'other.png', 'https://a.slack-edge.com/icons/app.png', 0);
check('path-relative hop resolves against the base dir', ($d['next'] ?? null) === 'https://a.slack-edge.com/icons/other.png');
$d = Mirror::redirectDecision(302, '//files.slack.com/f.png', $gravatar, 0);
check('scheme-relative hop inherits https', ($d['next'] ?? null) === 'https://files.slack.com/f.png');
$d = Mirror::redirectDecision(302, '//evil.com/f.png', $gravatar, 0);
check('scheme-relative hop to a foreign host is refused', is_wp_error($d));

// Hop budget: 3 redirects at most, exactly like MAX_IMAGE_REDIRECTS.
check('hop budget matches the Node proxy', Mirror::MAX_REDIRECTS === 3);
$next = 'https://a.slack-edge.com/next.png';
$d = Mirror::redirectDecision(302, $next, $gravatar, 2);
check('hop 2 is still under budget', ($d['next'] ?? null) === $next);
$d = Mirror::redirectDecision(302, $next, $gravatar, 3);
check('hop 3 exhausts the budget even on-allowlist', is_wp_error($d));
check('budget exhaustion is a download failure (502)', is_wp_error($d) && ($d->get_error_data()['status'] ?? 0) === 502);

// A 3xx with no Location is final (the caller then rejects it on status) —
// same shape as the TS helper returning the response.
$d = Mirror::redirectDecision(302, null, $gravatar, 0);
check('redirect without Location is final', ($d['final'] ?? false) === true);
$d = Mirror::redirectDecision(302, '', $gravatar, 0);
check('redirect with empty Location is final', ($d['final'] ?? false) === true);

// --- Content-type gate: image/* or nothing -----------------------------------

check('image/png passes', Mirror::imageType('image/png') === 'image/png');
check('content-type parameters are stripped', Mirror::imageType('image/jpeg; charset=binary') === 'image/jpeg');
check('content-type is case-insensitive', Mirror::imageType('IMAGE/PNG') === 'image/png');
check("Slack's HTML sign-in page is refused", Mirror::imageType('text/html; charset=utf-8') === null);
check('a bare image/ prefix is refused', Mirror::imageType('image/') === null);
check('a missing header is refused', Mirror::imageType(null) === null);

// --- Filename derivation ------------------------------------------------------

check('filename comes from the url basename', Mirror::filenameFor('https://files.slack.com/files-pri/T0-F0/photo.png', 'png') === 'photo.png');
check('extension follows the ACTUAL content type', Mirror::filenameFor('https://files.slack.com/files-pri/T0-F0/photo.jpg', 'png') === 'photo.jpg.png');
check('a bare host still yields a name', Mirror::filenameFor('https://a.slack-edge.com', 'png') === 'slack-image.png');
check('hostile characters are flattened', strpbrk(Mirror::filenameFor("https://files.slack.com/a b\u{202E}gnp.exe", 'png'), " \\/\u{202E}") === false);
$long = Mirror::filenameFor('https://files.slack.com/' . str_repeat('a', 300) . '.png', 'png');
check('overlong basenames are truncated', strlen($long) <= 110, (string) strlen($long));

// --- Size cap & eviction constants (the wp-env checks lean on these) ---------

check('default size cap is 10 MB', Mirror::DEFAULT_MAX_BYTES === 10 * 1024 * 1024);
check('eviction window is 14 days', Mirror::EVICTION_DAYS === 14);
check('eviction cutoff is a GMT MySQL datetime 14 days back', Mirror::evictionCutoff(1_752_000_000, 14) === gmdate('Y-m-d H:i:s', 1_752_000_000 - 14 * 86400));
check('cron hook name is stable', Mirror::CRON_HOOK === 'chronicler_mirror_evict');
