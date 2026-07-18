/**
 * Session → transcript-block mapping for editor-native generation (#102).
 *
 * PURE plain JS: no wp.* references, no DOM, no network — the module only
 * turns data into data, so lib/wordpress/generateBlocks.test.ts can run it in
 * vitest and pin its serialized output against lib/wordpress/blockGrammar.ts,
 * the emitter today's Node publish flow uses. The ES5 editor scripts
 * (generate/placeholder/index.js, generate/sidebar.js) consume it via the
 * `chroniclerSessionBlocks` global and feed the descriptors through
 * wp.blocks.createBlock.
 *
 * A stored Session's messages[] items are EXACTLY the chronicler/message
 * block attributes (Rest\Schemas::messageItem()), so the mapping is 1:1:
 *
 *   { name: "chronicler/transcript",
 *     attributes: { scheme, density, baseCss, customCss?, sessionId, generatedAt },
 *     innerBlocks: [ { name: "chronicler/message", attributes: {...} }, ... ] }
 *
 * Parity contract (enforced by generateBlocks.test.ts): with the provenance
 * attributes (sessionId/generatedAt — inert extras the render callbacks
 * ignore) stripped, serializing this tree through blockGrammar's
 * containerBlock/voidBlock is byte-identical to what renderConversationBlocks
 * emits for a flat conversation — same attribute pruning, same wrapper keys
 * in the same order, baseCss the pinned dialogueCss copy (generate/base-css.js).
 */
(function (root) {
  "use strict";

  /** TranscriptScheme vocabulary (lib/transform/types.ts / Rest\Schemas). */
  var SCHEMES = ["light", "dark", "custom-light", "custom-dark"];

  /** Media\Mirror::ALLOWED_HOSTS — the server re-checks; this only filters
   *  the candidate list down to what the mirror endpoint could accept. */
  var ALLOWED_IMAGE_HOSTS = [
    "files.slack.com",
    "avatars.slack-edge.com",
    "secure.gravatar.com",
    "a.slack-edge.com",
  ];

  /**
   * Drop empty strings/arrays so the serialized grammar stays lean — the
   * exact pruning renderConversationBlocks applies (pruneEmpty in
   * lib/wordpress/renderBlocks.ts); key order is preserved.
   */
  function pruneEmpty(attributes) {
    var out = {};
    for (var key in attributes) {
      if (!Object.prototype.hasOwnProperty.call(attributes, key)) continue;
      var value = attributes[key];
      if (value === "") continue;
      if (Array.isArray(value) && value.length === 0) continue;
      out[key] = value;
    }
    return out;
  }

  /**
   * The transcript wrapper's attributes for a Session, mirroring today's
   * publish emission (renderConversationBlocks + LogFormatterApp):
   * scheme/density always explicit, baseCss travels with the post, customCss
   * only for the custom-* schemes (activeCustomCss) and only when non-blank,
   * trimmed. The provenance pair rides last so parity checks can strip it.
   */
  function transcriptAttributes(session, opts) {
    var state = (session && session.editorState) || {};
    var scheme = SCHEMES.indexOf(state.scheme) !== -1 ? state.scheme : "light";
    var attributes = {
      scheme: scheme,
      density: state.density === "compact" ? "compact" : "comfortable",
      baseCss: typeof opts.baseCss === "string" ? opts.baseCss : "",
    };
    var isCustom = scheme === "custom-light" || scheme === "custom-dark";
    var customCss =
      isCustom && typeof state.customCss === "string" ? state.customCss.trim() : "";
    if (customCss) {
      attributes.customCss = customCss;
    }
    attributes.sessionId = session && typeof session.id === "number" ? session.id : 0;
    attributes.generatedAt = typeof opts.generatedAt === "string" ? opts.generatedAt : "";
    return attributes;
  }

  /**
   * The full generated block tree for a Session: one transcript wrapper, one
   * message child per stored message object, in stored (display) order. The
   * Session stores a flat, already rule-filtered message list, so there are
   * no thread/replies containers to reconstruct.
   */
  function sessionToBlocks(session, opts) {
    var messages = session && Array.isArray(session.messages) ? session.messages : [];
    return {
      name: "chronicler/transcript",
      attributes: transcriptAttributes(session, opts || {}),
      innerBlocks: messages.map(function (message) {
        return {
          name: "chronicler/message",
          attributes: pruneEmpty(message),
          innerBlocks: [],
        };
      }),
    };
  }

  /* ------------------------------------------------------------------ *
   * Tag derivation (the sidebar's "Apply tags from session")
   * ------------------------------------------------------------------ */

  /** Comma-separated tag names → trimmed non-empty tokens (rules.ts tagTokens). */
  function tagTokens(tagNames) {
    return String(tagNames == null ? "" : tagNames)
      .split(",")
      .map(function (name) { return name.trim(); })
      .filter(function (name) { return name !== ""; });
  }

  /** Compile a rule's regex, or null when empty/invalid (rules.ts compileRule). */
  function compileRule(rule) {
    var source = String((rule && rule.pattern) || "").trim();
    if (!source) return null;
    try {
      return new RegExp(source, String((rule && rule.flags) || "").replace(/[gy]/g, ""));
    } catch (_e) {
      return null;
    }
  }

  /**
   * A stored message's matchable text. Today's rules match raw Slack mrkdwn
   * (lib/transform/rules.ts applyRules on message.text); a Session stores only
   * the post-transform block attributes, so the closest faithful input is the
   * body HTML with tags stripped and the transform's five escape entities
   * decoded (&amp; last, mirroring editor.js decodeRichTextValue).
   */
  function messageText(message) {
    var html = (message && (message.bodyHtml || message.html)) || "";
    return String(html)
      .replace(/<br\s*\/?>/gi, "\n")
      .replace(/<[^>]*>/g, "")
      .replace(/&lt;/g, "<")
      .replace(/&gt;/g, ">")
      .replace(/&quot;/g, '"')
      .replace(/&#39;/g, "'")
      .replace(/&amp;/g, "&");
  }

  /**
   * WordPress tags proposed by the Session's attached wp-tag rules, in
   * attachment order, deduped — the applyRules "tags" semantics ported to
   * REST data. A Session's stored messages are already trimmed/hidden-
   * filtered, so "the match survived hiding" is simply "a stored message
   * matches". Disabled concepts don't exist here: an attached rule is live,
   * and blank/invalid patterns are inert exactly as in the app.
   *
   * @param session full Session (GET sessions/{id})
   * @param rules   Rule list (GET rules) — session.rule_ids selects/orders
   */
  function deriveTags(session, rules) {
    var byId = {};
    (Array.isArray(rules) ? rules : []).forEach(function (rule) {
      if (rule && rule.id != null) byId[String(rule.id)] = rule;
    });
    var attached = ((session && session.rule_ids) || [])
      .map(function (id) { return byId[String(id)]; })
      .filter(function (rule) { return !!rule; });
    var texts = ((session && session.messages) || []).map(messageText);
    var tags = [];
    attached.forEach(function (rule) {
      if (rule.mode !== "wp-tag") return;
      var names = tagTokens(rule.tagNames);
      if (names.length === 0) return;
      var regex = compileRule(rule);
      if (!regex) return;
      var matched = texts.some(function (text) { return regex.test(text); });
      if (!matched) return;
      names.forEach(function (name) {
        if (tags.indexOf(name) === -1) tags.push(name);
      });
    });
    return tags;
  }

  /* ------------------------------------------------------------------ *
   * Featured-image candidates (the sidebar's "Set featured image")
   * ------------------------------------------------------------------ */

  function safeDecode(value) {
    try {
      return decodeURIComponent(value);
    } catch (_e) {
      return null;
    }
  }

  /**
   * Recover the underlying Slack URL from a stored images[].src, in any of
   * the shapes a Session can carry: the Node app's proxied form
   * (/api/slack-image?url=...), the plugin's mirror route
   * (chronicler/v1/image?...url=...), or a bare allow-listed https URL.
   * Anything else (e.g. an already-local uploads URL whose source is lost)
   * yields null and drops out of the candidate list — the mirror endpoint
   * could not accept it anyway.
   */
  function slackUrlFromSrc(src) {
    if (typeof src !== "string" || src === "") return null;
    var proxied = /^\/api\/slack-image\?url=(.+)$/.exec(src);
    if (proxied) return checkSlackUrl(safeDecode(proxied[1]));
    if (/chronicler\/v1\/image/.test(src)) {
      var param = /[?&]url=([^&#]+)/.exec(src);
      return param ? checkSlackUrl(safeDecode(param[1])) : null;
    }
    return checkSlackUrl(src);
  }

  /** Exact-host allowlist check, mirroring Media\Mirror::isAllowedUrl. */
  function checkSlackUrl(url) {
    if (typeof url !== "string" || url.indexOf("https://") !== 0) return null;
    var host = url.slice("https://".length).split(/[/?#]/)[0].toLowerCase();
    return ALLOWED_IMAGE_HOSTS.indexOf(host) !== -1 ? url : null;
  }

  /* ------------------------------------------------------------------ *
   * Publish-time image rewriting (the #96/#102 integration fix)
   *
   * Stored messages reference Slack-hosted images through the plugin's
   * capability-gated mirror route (components/admin/imageUrls.ts) — fine in
   * the editor (a nonce is added at render time), a broken 401 for anonymous
   * readers of a published post. The Generate flow therefore mirrors every
   * referenced image into the media library first and rewrites the stored
   * srcs to the local uploads URLs before creating blocks — the same job the
   * Node publish flow did in lib/wordpress/prepareContent.ts for the
   * /api/slack-image proxy. Both halves here are PURE (collect + rewrite);
   * the network side lives in generate/mirror.js.
   * ------------------------------------------------------------------ */

  /** The opaque-HTML message attributes — blockGrammar's MESSAGE_HTML_KEYS. */
  var HTML_KEYS = ["html", "avatarHtml", "headHtml", "bodyHtml", "extrasHtml", "reactionsHtml"];

  /** src="..." attribute occurrences inside an opaque-HTML string. */
  var SRC_ATTR_RE = /src="([^"]*)"/g;

  /**
   * Undo escapeAttr's &-escaping for a src attribute value. The persisted
   * mirror form is a root-relative base + an encodeURIComponent'd URL, so
   * &amp; (the plain-permalink `&url=` chain, or an `&alt=`/`&format=` arg)
   * is the only entity that can appear.
   */
  function attrDecode(value) {
    return String(value).replace(/&amp;/g, "&");
  }

  /** escapeAttr for a URL destined for src="..." (prepareContent parity). */
  function attrEncode(value) {
    return String(value).replace(/&/g, "&amp;");
  }

  /**
   * Every distinct mirrorable image URL a Session's stored messages
   * reference, in message order: structured images[].src plus src="..."
   * occurrences inside every opaque-HTML attribute (avatars in avatarHtml,
   * block-kit figures in bodyHtml, whatever else rides the proxy). Each src
   * is reduced to its underlying Slack URL via slackUrlFromSrc, so all three
   * stored shapes count (the plugin mirror route in both its ?url=/&url=
   * permalink forms — never carrying a nonce, which is render-time-only —
   * the Node app's /api/slack-image form, and bare allow-listed https URLs);
   * anything the mirror endpoint could not accept is left out.
   */
  function collectImageUrls(messages) {
    var seen = {};
    var out = [];
    function add(src) {
      var slackUrl = slackUrlFromSrc(src);
      if (!slackUrl || Object.prototype.hasOwnProperty.call(seen, slackUrl)) return;
      seen[slackUrl] = true;
      out.push(slackUrl);
    }
    (Array.isArray(messages) ? messages : []).forEach(function (message) {
      if (!message) return;
      HTML_KEYS.forEach(function (key) {
        var html = message[key];
        if (typeof html !== "string" || html === "") return;
        var match;
        SRC_ATTR_RE.lastIndex = 0;
        while ((match = SRC_ATTR_RE.exec(html)) !== null) {
          add(attrDecode(match[1]));
        }
      });
      var images = Array.isArray(message.images) ? message.images : [];
      images.forEach(function (image) {
        if (image) add(image.src);
      });
    });
    return out;
  }

  /**
   * Rewrite every mirrorable src to its local uploads URL. `urlMap` maps
   * Slack URL (as collectImageUrls reports it) → local URL; a src whose
   * Slack URL is absent from the map — including every failed mirror — stays
   * verbatim, matching prepareContent's replaceImageSrcs fallback. Pure and
   * non-mutating: returns new message objects; untouched fields are carried
   * by reference.
   */
  function rewriteImageUrls(messages, urlMap) {
    var map = urlMap || {};
    function localFor(src) {
      var slackUrl = slackUrlFromSrc(src);
      return slackUrl && Object.prototype.hasOwnProperty.call(map, slackUrl)
        ? map[slackUrl]
        : null;
    }
    return (Array.isArray(messages) ? messages : []).map(function (message) {
      if (!message) return message;
      var out = {};
      for (var key in message) {
        if (!Object.prototype.hasOwnProperty.call(message, key)) continue;
        out[key] = message[key];
      }
      HTML_KEYS.forEach(function (key) {
        if (typeof out[key] !== "string" || out[key] === "") return;
        out[key] = out[key].replace(SRC_ATTR_RE, function (full, src) {
          var local = localFor(attrDecode(src));
          return local === null ? full : 'src="' + attrEncode(local) + '"';
        });
      });
      if (Array.isArray(out.images)) {
        out.images = out.images.map(function (image) {
          var local = image ? localFor(image.src) : null;
          if (local === null) return image;
          var copy = {};
          for (var field in image) {
            if (Object.prototype.hasOwnProperty.call(image, field)) copy[field] = image[field];
          }
          copy.src = local;
          return copy;
        });
      }
      return out;
    });
  }

  /**
   * The Session's mirrorable images as featured-image candidates:
   * {src, slackUrl, alt, caption}, deduped by slackUrl, in message order.
   */
  function imageCandidates(messages) {
    var seen = {};
    var out = [];
    (Array.isArray(messages) ? messages : []).forEach(function (message) {
      var images = (message && message.images) || [];
      (Array.isArray(images) ? images : []).forEach(function (image) {
        var slackUrl = slackUrlFromSrc(image && image.src);
        if (!slackUrl || Object.prototype.hasOwnProperty.call(seen, slackUrl)) return;
        seen[slackUrl] = true;
        out.push({
          src: image.src,
          slackUrl: slackUrl,
          alt: (image && image.alt) || "",
          caption: (image && image.caption) || "",
        });
      });
    });
    return out;
  }

  root.chroniclerSessionBlocks = {
    pruneEmpty: pruneEmpty,
    transcriptAttributes: transcriptAttributes,
    sessionToBlocks: sessionToBlocks,
    tagTokens: tagTokens,
    compileRule: compileRule,
    messageText: messageText,
    deriveTags: deriveTags,
    slackUrlFromSrc: slackUrlFromSrc,
    collectImageUrls: collectImageUrls,
    rewriteImageUrls: rewriteImageUrls,
    imageCandidates: imageCandidates,
  };
})(typeof self !== "undefined" ? self : this);
