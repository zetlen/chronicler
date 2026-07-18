/**
 * Media\Mirror REST plumbing shared by editor-native generation
 * (generate/placeholder/index.js) and the document sidebar
 * (generate/sidebar.js): mirror a Slack-hosted image into the media library
 * and honor the Mirror consumer obligation of parenting used attachments to
 * their post (so the 14-day eviction of unattached mirrors never collects
 * an image a post references).
 *
 * Plain ES5, no build step. wp.apiFetch is read lazily off the root at call
 * time (never at load time), so lib/wordpress/generateBlocks.test.ts can
 * evaluate this file against a sandbox carrying a fake `wp` — the same
 * recipe session-blocks.js uses, plus that one seam.
 */
(function (root) {
  "use strict";

  function apiFetch(options) {
    return root.wp.apiFetch(options);
  }

  /**
   * Mirror one Slack-hosted image: GET chronicler/v1/image?format=json —
   * a 200 {id, url} (the attachment id and its local uploads URL) instead
   * of the <img>-oriented 302. Cookie + nonce auth comes preconfigured on
   * the block editor's wp.apiFetch.
   */
  function mirrorImage(slackUrl, alt) {
    return apiFetch({
      path:
        '/chronicler/v1/image?url=' + encodeURIComponent(slackUrl) +
        (alt ? '&alt=' + encodeURIComponent(alt) : '') +
        '&format=json',
    });
  }

  /**
   * The Media\Mirror consumer obligation: parent a mirrored attachment to
   * the post that uses it (POST /wp/v2/media/{id} {post}), taking it out of
   * eviction's unattached pool.
   */
  function parentAttachment(attachmentId, postId) {
    return apiFetch({
      path: '/wp/v2/media/' + attachmentId,
      method: 'POST',
      data: { post: postId },
    });
  }

  /**
   * Mirror a list of Slack URLs with limited concurrency (default 4 — kind
   * to shared PHP hosts; each first-time mirror is an upstream fetch plus a
   * sideload). Resolves {byUrl, failed}:
   *
   * - byUrl:  {slackUrl: {id, url}} for every successful mirror
   * - failed: [slackUrl] for every failure — console.warn'd and SKIPPED, so
   *           one broken image never blocks generation; the caller leaves
   *           that src as-is (an editor-only URL beats no post at all).
   *
   * options.onProgress(done, total) fires after each settle, success or not.
   */
  function mirrorAll(urls, options) {
    var opts = options || {};
    var concurrency = opts.concurrency > 0 ? opts.concurrency : 4;
    var onProgress = typeof opts.onProgress === 'function' ? opts.onProgress : function () {};
    var list = Array.isArray(urls) ? urls : [];
    var byUrl = {};
    var failed = [];
    var done = 0;
    var next = 0;

    function work() {
      if (next >= list.length) return Promise.resolve();
      var url = list[next++];
      return mirrorImage(url).then(
        function (mirrored) {
          byUrl[url] = mirrored;
        },
        function (err) {
          failed.push(url);
          if (root.console && root.console.warn) {
            root.console.warn(
              'Chronicler: could not mirror ' + url + '; leaving the original src in place.',
              err
            );
          }
        }
      ).then(function () {
        done++;
        onProgress(done, list.length);
        return work();
      });
    }

    var workers = [];
    for (var i = 0; i < Math.min(concurrency, list.length); i++) {
      workers.push(work());
    }
    return Promise.all(workers).then(function () {
      return { byUrl: byUrl, failed: failed };
    });
  }

  root.chroniclerMirror = {
    mirrorImage: mirrorImage,
    parentAttachment: parentAttachment,
    mirrorAll: mirrorAll,
  };
})(typeof self !== "undefined" ? self : this);
