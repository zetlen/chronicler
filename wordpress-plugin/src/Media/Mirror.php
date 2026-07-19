<?php

namespace Chronicler\Media;

use Chronicler\Settings\Screen;
use WP_Error;

/**
 * Slack image mirroring (#103) — the WordPress replacement for the Node
 * app's streaming proxy (app/api/slack-image/route.ts).
 *
 * Instead of streaming Slack bytes through on every request, localUrl()
 * mirrors an image into the media library on FIRST fetch and returns the
 * local attachment URL from then on. The REST route (GET chronicler/v1/image)
 * 302s the editor's <img>s to that local URL; the #102 publish path calls
 * localUrl() directly and uses the attachment as featured_media/post images.
 *
 * Security posture, ported 1:1 from lib/slack/imageHosts.ts:
 * - Fixed https host allowlist (ALLOWED_HOSTS), exact-hostname matching —
 *   no subdomain wildcards, so files.slack.com.evil.com never passes.
 * - Redirects are NEVER followed automatically ('redirection' => 0): the
 *   download loop chases at most MAX_REDIRECTS Location hops by hand and
 *   re-checks EVERY hop against the allowlist, so an open redirect on an
 *   allow-listed host can't bounce the fetch to an internal address (SSRF).
 * - The Slack bot token is attached ONLY when the CURRENT hop is a host
 *   that requires it (files.slack.com); it never rides a redirect to
 *   another host, so it can't leak to CDN/gravatar hosts.
 * - The body must declare and measure under the size cap and be image/*.
 *
 * Duplicate media detection (#177): the same image posted to Slack twice
 * arrives at two DIFFERENT file URLs, so the URL key alone would store a
 * second identical copy on every repost. Every stored mirror therefore also
 * carries META_CONTENT (sha256 of the bytes); after a download, an existing
 * mirror with the same content hash is ADOPTED — the new URL's key/source
 * are recorded on it as extra meta rows and it is returned — instead of
 * storing a duplicate file. backfill() (same daily cron event as evict())
 * stamps content hashes onto mirrors that predate the feature so the lookup
 * reaches the existing library too.
 *
 * Consumer contract: a mirrored attachment that ends up used in a post MUST
 * be parented to it (media_handle_sideload $post_id, wp_update_post on
 * post_parent, or — for REST consumers like the #102 editor sidebar, which
 * mirrors via GET chronicler/v1/image&format=json and stages the id as
 * featured_media — POST/PATCH /wp/v2/media/{id} {post: <postId>}) — evict()
 * garbage-collects mirrors that are old AND still unattached (preview
 * fetches whose session was never published).
 *
 * The policy pieces (allowlist, auth targeting, redirect decisions, hash
 * keying) are pure static functions, unit-tested in tests/mirror.test.php;
 * the WordPress HTTP/attachment plumbing is verified at runtime in wp-env.
 */
final class Mirror
{
    /**
     * Slack-operated hosts the mirror is willing to fetch from — the same
     * list as ALLOWED_IMAGE_HOSTS in lib/slack/imageHosts.ts. Exact
     * hostnames, https only.
     */
    public const ALLOWED_HOSTS = [
        'files.slack.com',        // message file/image uploads (auth required)
        'avatars.slack-edge.com', // user profile photos & custom bot icons
        'secure.gravatar.com',    // Slack's default avatar fallbacks
        'a.slack-edge.com',       // stock app/bot icons
    ];

    /** Attachment meta holding the mirror key (hash of the source URL).
     *  One row per adopted source URL — an attachment reused for identical
     *  bytes (#177) carries several, and findByKey() matches any of them. */
    public const META_KEY = 'chronicler_mirror_key';
    /** Attachment meta holding the original Slack URL, for debugging.
     *  Like META_KEY, one row per adopted source URL. */
    public const META_SOURCE = 'chronicler_mirror_source';
    /** Attachment meta holding the sha256 of the stored bytes — the
     *  duplicate-detection identity (#177). '' marks a mirror whose file is
     *  gone: a real content key is 64 hex chars, so it never matches a
     *  lookup, and the backfill scan never revisits the row. */
    public const META_CONTENT = 'chronicler_mirror_sha256';
    /** Mirrors content-hashed per backfill() run (daily), keeping one run's
     *  disk reads bounded on large libraries. The
     *  chronicler_mirror_backfill_batch filter can adjust it. */
    public const BACKFILL_BATCH = 500;

    /** Max redirect hops to chase before giving up (each is re-checked). */
    public const MAX_REDIRECTS = 3;
    /** Abort a hung upstream so it can't pin a PHP worker indefinitely. */
    public const FETCH_TIMEOUT = 10;
    /** Refuse an upstream that declares or delivers more than this. The
     *  chronicler_mirror_max_bytes filter can raise/lower it. */
    public const DEFAULT_MAX_BYTES = 10485760; // 10 MB

    /** Daily eviction cron event; see evict(). */
    public const CRON_HOOK = 'chronicler_mirror_evict';
    /** Unattached mirrors older than this many days are evicted. The
     *  chronicler_mirror_eviction_days filter can adjust it. */
    public const EVICTION_DAYS = 14;

    /* ------------------------------------------------------------------ *
     * Pure policy (unit-tested standalone in tests/mirror.test.php)
     * ------------------------------------------------------------------ */

    /**
     * True only for https URLs whose exact hostname is allow-listed —
     * the PHP port of isAllowedImageUrl(). parse_url leaves credentials
     * ("https://files.slack.com@evil.com/") and look-alike registrable
     * domains ("files.slack.com.evil.com") out of 'host', so exact
     * comparison rejects both.
     */
    public static function isAllowedUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return false;
        }
        return strtolower($parts['scheme']) === 'https'
            && in_array(strtolower($parts['host']), self::ALLOWED_HOSTS, true);
    }

    /**
     * The one host that needs (and is trusted with) the bot token — the
     * PHP port of requiresSlackAuth(). Everything else must never see it.
     */
    public static function requiresSlackAuth(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        return is_string($host) && strtolower($host) === 'files.slack.com';
    }

    /**
     * Stable lookup key for a source URL: mirror-on-first-fetch dedupes on
     * this, stored in META_KEY attachment meta. Full sha256 hex — no
     * truncation, so distinct URLs can't collide in practice.
     */
    public static function mirrorKey(string $url): string
    {
        return hash('sha256', $url);
    }

    /**
     * Content identity of downloaded bytes (#177), stored in META_CONTENT:
     * two Slack URLs serving the same image hash to the same key, which is
     * what lets adoptDuplicate() reuse the first mirror instead of storing
     * an identical copy.
     */
    public static function contentKey(string $bytes): string
    {
        return hash('sha256', $bytes);
    }

    /**
     * One step of the manual redirect policy, given the response status,
     * its Location header, the URL just fetched, and how many redirects
     * were already taken (0 on the first response).
     *
     * Returns ['final' => true] when the response is the one to consume
     * (non-3xx, or a 3xx without a Location — same as the TS helper, the
     * caller then fails it on status/type checks), ['next' => url] when the
     * hop is allow-listed and under budget, or a WP_Error refusing it:
     * an off-allowlist hop is a blocked host (400), exhausting the hop
     * budget is a download failure (502).
     */
    public static function redirectDecision(
        int $status,
        ?string $location,
        string $currentUrl,
        int $hop
    ): array|WP_Error {
        if ($status < 300 || $status >= 400) {
            return ['final' => true];
        }
        if ($location === null || $location === '') {
            return ['final' => true];
        }
        if ($hop >= self::MAX_REDIRECTS) {
            return new WP_Error(
                'chronicler_mirror_download_failed',
                'Too many image redirects.',
                ['status' => 502]
            );
        }
        $next = self::resolveUrl($location, $currentUrl);
        if ($next === null || !self::isAllowedUrl($next)) {
            return new WP_Error(
                'chronicler_mirror_disallowed_host',
                'Image redirect left the allowed Slack hosts.',
                ['status' => 400]
            );
        }
        return ['next' => $next];
    }

    /**
     * Resolve a Location header against the URL that produced it (the
     * `new URL(location, url)` step in the TS helper). Handles absolute,
     * scheme-relative (//host/...), root-relative (/path), query-only (?d=x)
     * and path-relative forms; null when nothing resolvable comes out.
     * Dot segments are left as-is — only the host matters to the allowlist,
     * and the upstream serves or 404s the path either way.
     */
    public static function resolveUrl(string $location, string $base): ?string
    {
        $parts = parse_url($location);
        if (!is_array($parts)) {
            return null;
        }
        if (isset($parts['scheme'])) {
            return $location;
        }
        $baseParts = parse_url($base);
        if (!is_array($baseParts) || !isset($baseParts['scheme'], $baseParts['host'])) {
            return null;
        }
        if (str_starts_with($location, '//')) {
            return $baseParts['scheme'] . ':' . $location;
        }
        $origin = $baseParts['scheme'] . '://' . $baseParts['host']
            . (isset($baseParts['port']) ? ':' . $baseParts['port'] : '');
        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }
        $path = $baseParts['path'] ?? '/';
        if (str_starts_with($location, '?') || str_starts_with($location, '#')) {
            return $origin . $path . $location;
        }
        $slash = strrpos($path, '/');
        $dir = $slash === false ? '/' : substr($path, 0, $slash + 1);
        return $origin . $dir . $location;
    }

    /**
     * Normalized mime type when the Content-Type header names an image,
     * null otherwise. Parameters ("; charset=binary") are stripped. Slack
     * serves an HTML sign-in page (HTTP 200) instead of the file when the
     * bot token lacks files:read — this is what catches that.
     */
    public static function imageType(?string $contentType): ?string
    {
        if (!is_string($contentType)) {
            return null;
        }
        $mime = strtolower(trim(explode(';', $contentType, 2)[0]));
        return str_starts_with($mime, 'image/') && strlen($mime) > 6 ? $mime : null;
    }

    /**
     * Attachment filename for a mirrored URL: the URL path's basename,
     * conservatively cleaned (media_handle_sideload sanitizes further and
     * wp_unique_filename dedupes), always carrying the extension matching
     * the ACTUAL content type so wp_check_filetype_and_ext agrees.
     */
    public static function filenameFor(string $url, string $ext): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        $base = is_string($path) ? basename($path) : '';
        $base = (string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $base);
        $base = trim(substr($base, 0, 100), '.-');
        if ($base === '') {
            $base = 'slack-image';
        }
        if (!str_ends_with(strtolower($base), '.' . $ext)) {
            $base .= '.' . $ext;
        }
        return $base;
    }

    /** ISO cutoff (post_date_gmt comparison) for eviction at $now. */
    public static function evictionCutoff(int $now, int $days): string
    {
        return gmdate('Y-m-d H:i:s', $now - $days * 86400);
    }

    /* ------------------------------------------------------------------ *
     * Mirroring (WordPress side; verified at runtime in wp-env)
     * ------------------------------------------------------------------ */

    /**
     * The local media-library URL for a Slack-hosted image, mirroring it on
     * first fetch. See localAttachment() — this is its URL-only view, kept
     * for consumers that never need the attachment id (the 302 route path).
     */
    public static function localUrl(string $slackUrl, string $alt = ''): string|WP_Error
    {
        $attachment = self::localAttachment($slackUrl, $alt);
        return is_wp_error($attachment) ? $attachment : $attachment['url'];
    }

    /**
     * The local media-library attachment {id, url} for a Slack-hosted image,
     * mirroring it on first fetch. Repeat calls for the same source URL
     * return the same attachment (keyed on META_KEY = mirrorKey($slackUrl))
     * without touching the network, and a NEW source URL serving bytes the
     * library already stores adopts that attachment instead of duplicating
     * it (#177, see adoptDuplicate()). $alt becomes the attachment's alt
     * text when provided (first fetch only — an existing or adopted mirror
     * is returned untouched). The id exists for consumers that need an ATTACHMENT ID
     * (featured_media); they inherit the parenting obligation in the class
     * docblock.
     *
     * WP_Error statuses: 400 for a disallowed/invalid URL (including a
     * redirect hop leaving the allowlist), 502 for download/upstream
     * failures, 500 for local storage failures.
     *
     * @return array{id: int, url: string}|WP_Error
     */
    public static function localAttachment(string $slackUrl, string $alt = ''): array|WP_Error
    {
        if (!self::isAllowedUrl($slackUrl)) {
            return new WP_Error(
                'chronicler_mirror_disallowed_host',
                'Only Slack-hosted image URLs can be mirrored.',
                ['status' => 400]
            );
        }
        $key = self::mirrorKey($slackUrl);
        $existing = self::findByKey($key);
        if ($existing !== 0) {
            $url = wp_get_attachment_url($existing);
            if (is_string($url) && $url !== '') {
                return ['id' => $existing, 'url' => $url];
            }
            // A key row without a file (half-deleted attachment): fall
            // through and re-mirror over it.
        }

        $image = self::download($slackUrl);
        if (is_wp_error($image)) {
            return $image;
        }
        // #177: identical bytes already in the library (under a different
        // source URL) reuse that attachment instead of storing a copy.
        $contentKey = self::contentKey($image['body']);
        $duplicate = self::adoptDuplicate($contentKey, $slackUrl, $key);
        if ($duplicate !== null) {
            return $duplicate;
        }
        $id = self::store($slackUrl, $key, $contentKey, $image['body'], $image['type'], $alt);
        if (is_wp_error($id)) {
            return $id;
        }
        $url = wp_get_attachment_url($id);
        if (!is_string($url) || $url === '') {
            return new WP_Error(
                'chronicler_mirror_store_failed',
                'The mirrored attachment has no URL.',
                ['status' => 500]
            );
        }
        return ['id' => (int) $id, 'url' => $url];
    }

    /**
     * Reuse an existing mirror whose stored bytes match $contentKey (#177).
     * When a usable duplicate exists, the new source's key + URL are
     * recorded on it as EXTRA meta rows (findByKey() matches any row, so
     * the next fetch of this URL short-circuits before downloading) and its
     * {id, url} is returned; null means the library holds no usable copy
     * and the caller stores a fresh attachment. Re-adopting a key the
     * attachment already carries adds nothing — a half-deleted attachment
     * shadowing the key fast path re-downloads on every fetch, and those
     * repeats must not pile up meta rows. The adopted attachment itself is
     * otherwise untouched (same posture as the key fast path: alt text and
     * parenting stay as they are).
     *
     * @return array{id: int, url: string}|null
     */
    public static function adoptDuplicate(string $contentKey, string $sourceUrl, string $urlKey): ?array
    {
        $id = self::findByContent($contentKey);
        if ($id === 0) {
            return null;
        }
        $url = wp_get_attachment_url($id);
        if (!is_string($url) || $url === '') {
            // A content row without a file (half-deleted attachment) is not
            // a usable copy — same posture as the key fast path.
            return null;
        }
        $known = get_post_meta($id, self::META_KEY);
        $known = is_array($known) ? $known : [$known];
        if (!in_array($urlKey, $known, true)) {
            add_post_meta($id, self::META_KEY, $urlKey);
            add_post_meta($id, self::META_SOURCE, esc_url_raw($sourceUrl));
        }
        return ['id' => $id, 'url' => $url];
    }

    /** Attachment id already storing bytes with this content key, or 0. */
    private static function findByContent(string $contentKey): int
    {
        $ids = get_posts([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'posts_per_page' => 1,
            'orderby' => 'ID',
            'order' => 'ASC',
            'fields' => 'ids',
            'meta_key' => self::META_CONTENT,
            'meta_value' => $contentKey,
            'no_found_rows' => true,
        ]);
        return (int) ($ids[0] ?? 0);
    }

    /** Attachment id already mirroring this key, or 0. */
    private static function findByKey(string $key): int
    {
        $ids = get_posts([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'posts_per_page' => 1,
            'orderby' => 'ID',
            'order' => 'ASC',
            'fields' => 'ids',
            'meta_key' => self::META_KEY,
            'meta_value' => $key,
            'no_found_rows' => true,
        ]);
        return (int) ($ids[0] ?? 0);
    }

    /**
     * Fetch an allow-listed URL with the manual redirect loop described in
     * the class docblock. Returns ['body' => bytes, 'type' => image mime]
     * or a WP_Error carrying the REST status to surface.
     *
     * @return array{body: string, type: string}|WP_Error
     */
    private static function download(string $url): array|WP_Error
    {
        $max = (int) apply_filters('chronicler_mirror_max_bytes', self::DEFAULT_MAX_BYTES);
        $current = $url;
        $response = null;
        for ($hop = 0; ; $hop++) {
            $args = [
                'timeout' => self::FETCH_TIMEOUT,
                // NO automatic redirect following — every hop is re-checked
                // against the allowlist by redirectDecision() below.
                'redirection' => 0,
                'limit_response_size' => $max + 1,
            ];
            if (self::requiresSlackAuth($current)) {
                $token = Screen::bot_token();
                if ($token === null) {
                    return new WP_Error(
                        'chronicler_mirror_download_failed',
                        'No Slack bot token is configured (Chronicler → Settings).',
                        ['status' => 502]
                    );
                }
                // Only ever attached on a files.slack.com hop; see class docblock.
                $args['headers'] = ['Authorization' => 'Bearer ' . $token];
            }
            $response = wp_remote_get($current, $args);
            if (is_wp_error($response)) {
                return new WP_Error(
                    'chronicler_mirror_download_failed',
                    'Image download failed: ' . $response->get_error_message(),
                    ['status' => 502]
                );
            }
            $location = wp_remote_retrieve_header($response, 'location');
            if (is_array($location)) {
                $location = (string) reset($location);
            }
            $decision = self::redirectDecision(
                (int) wp_remote_retrieve_response_code($response),
                is_string($location) && $location !== '' ? $location : null,
                $current,
                $hop
            );
            if (is_wp_error($decision)) {
                return $decision;
            }
            if (isset($decision['next'])) {
                $current = $decision['next'];
                continue;
            }
            break;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return new WP_Error(
                'chronicler_mirror_download_failed',
                sprintf('Upstream returned HTTP %d.', $code),
                ['status' => 502]
            );
        }
        $declared = wp_remote_retrieve_header($response, 'content-length');
        if (is_numeric($declared) && (int) $declared > $max) {
            return new WP_Error(
                'chronicler_mirror_download_failed',
                'Image exceeds the size cap.',
                ['status' => 502]
            );
        }
        $type = self::imageType(wp_remote_retrieve_header($response, 'content-type') ?: null);
        if ($type === null) {
            return new WP_Error(
                'chronicler_mirror_download_failed',
                "Upstream did not return an image — for Slack files, the bot token likely lacks the 'files:read' scope.",
                ['status' => 502]
            );
        }
        $body = wp_remote_retrieve_body($response);
        if (!is_string($body) || $body === '') {
            return new WP_Error(
                'chronicler_mirror_download_failed',
                'Upstream returned an empty image body.',
                ['status' => 502]
            );
        }
        if (strlen($body) > $max) {
            return new WP_Error(
                'chronicler_mirror_download_failed',
                'Image exceeds the size cap.',
                ['status' => 502]
            );
        }
        return ['body' => $body, 'type' => $type];
    }

    /**
     * Sideload downloaded bytes into the media library and tag the
     * attachment with the mirror key. Unattached (post_parent 0) until a
     * publish actually uses it — that is what eviction keys on.
     */
    private static function store(
        string $sourceUrl,
        string $key,
        string $contentKey,
        string $body,
        string $type,
        string $alt
    ): int|WP_Error {
        $ext = wp_get_default_extension_for_mime_type($type);
        if ($ext === false) {
            return new WP_Error(
                'chronicler_mirror_download_failed',
                sprintf('Unsupported image type %s.', $type),
                ['status' => 502]
            );
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $name = self::filenameFor($sourceUrl, $ext);
        $tmp = wp_tempnam($name);
        if (!is_string($tmp) || $tmp === '' || file_put_contents($tmp, $body) === false) {
            return new WP_Error(
                'chronicler_mirror_store_failed',
                'Could not write the temporary image file.',
                ['status' => 500]
            );
        }

        $id = media_handle_sideload(['name' => $name, 'tmp_name' => $tmp], 0);
        if (is_wp_error($id)) {
            if (file_exists($tmp)) {
                @unlink($tmp);
            }
            return new WP_Error(
                'chronicler_mirror_store_failed',
                'Could not add the image to the media library: ' . $id->get_error_message(),
                ['status' => 500]
            );
        }
        update_post_meta($id, self::META_KEY, $key);
        update_post_meta($id, self::META_SOURCE, esc_url_raw($sourceUrl));
        update_post_meta($id, self::META_CONTENT, $contentKey);
        if ($alt !== '') {
            update_post_meta($id, '_wp_attachment_image_alt', sanitize_text_field($alt));
        }
        return $id;
    }

    /* ------------------------------------------------------------------ *
     * Eviction + hash backfill (daily WP-Cron)
     * ------------------------------------------------------------------ */

    /**
     * Hook registration: the cron callback plus the update-safe schedule
     * (activation hooks don't fire on plugin updates, so — like
     * Capabilities::ensure()/Schema::ensure() — ensureScheduled() runs on
     * every init; wp_next_scheduled() reads the autoloaded cron option, so
     * the steady-state cost is one option read).
     */
    public static function register(): void
    {
        add_action(self::CRON_HOOK, [self::class, 'evict']);
        // Same event (renaming CRON_HOOK would orphan already-scheduled
        // events on update), registered after evict so a run never hashes
        // files eviction is about to delete.
        add_action(self::CRON_HOOK, [self::class, 'backfill']);
        add_action('init', [self::class, 'ensureScheduled']);
    }

    public static function ensureScheduled(): void
    {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + DAY_IN_SECONDS, 'daily', self::CRON_HOOK);
        }
    }

    /** Deactivation hook: leave no orphaned cron event behind. */
    public static function unschedule(): void
    {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    /**
     * Delete preview mirrors that never got used in a post: attachments
     * older than EVICTION_DAYS days AND unattached (post_parent 0) AND
     * carrying META_KEY. Anything a publish attached to a post survives,
     * as does every non-Chronicler attachment (no META_KEY).
     */
    public static function evict(): void
    {
        foreach (self::evictableIds(time()) as $id) {
            wp_delete_attachment($id, true);
        }
    }

    /**
     * Stamp content hashes onto mirrors that predate #177, one bounded
     * batch per daily cron run, so adoptDuplicate()'s lookup reaches the
     * pre-existing library. A mirror whose file is gone is stamped '' (see
     * META_CONTENT) so the NOT EXISTS scan never revisits it.
     */
    public static function backfill(): void
    {
        $limit = (int) apply_filters('chronicler_mirror_backfill_batch', self::BACKFILL_BATCH);
        $ids = get_posts([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'posts_per_page' => $limit,
            'orderby' => 'ID',
            'order' => 'ASC',
            'fields' => 'ids',
            'meta_query' => [
                ['key' => self::META_KEY, 'compare' => 'EXISTS'],
                ['key' => self::META_CONTENT, 'compare' => 'NOT EXISTS'],
            ],
            'no_found_rows' => true,
        ]);
        foreach ($ids as $id) {
            $path = get_attached_file((int) $id);
            $hash = is_string($path) && $path !== '' && is_readable($path)
                ? (string) hash_file('sha256', $path)
                : '';
            update_post_meta((int) $id, self::META_CONTENT, $hash);
        }
    }

    /** @return int[] attachment ids qualifying for eviction at $now. */
    public static function evictableIds(int $now): array
    {
        $days = (int) apply_filters('chronicler_mirror_eviction_days', self::EVICTION_DAYS);
        $ids = get_posts([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'post_parent' => 0,
            'posts_per_page' => -1,
            'fields' => 'ids',
            'meta_query' => [['key' => self::META_KEY, 'compare' => 'EXISTS']],
            'date_query' => [[
                'column' => 'post_date_gmt',
                'before' => self::evictionCutoff($now, $days),
            ]],
            'no_found_rows' => true,
        ]);
        return array_map('intval', $ids);
    }
}
