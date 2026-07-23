/**
 * "Chronicler" document sidebar panel (#102): plain ES5 against the wp.*
 * globals. Registered with wp.plugins.registerPlugin; the panel component is
 * PluginDocumentSettingPanel from the wp.editor global (NOT wp.editPost,
 * whose copy is a deprecated forward since WP 6.6).
 *
 * The panel appears only when the post contains Chronicler session output:
 * a generated chronicler/transcript wrapper (identified by its sessionId
 * provenance attribute) or a chronicler/session-placeholder. Two actions,
 * both STAGING edits (nothing saves until the user saves the post):
 *
 * - "Apply tags from session": re-derives the wp-tag rule tags the way the
 *   publish flow does (session-blocks.js deriveTags over the session's
 *   messages + attached rules), creates missing post_tag terms via
 *   wp.data.dispatch('core').saveEntityRecord, and stages the merged id list
 *   with editPost({tags}).
 *
 * - "Set featured image from session": lists the session's mirrorable images
 *   (session-blocks.js imageCandidates), mirrors the chosen one through
 *   GET chronicler/v1/image?format=json (200 {id, url} instead of the 302 —
 *   featured_media needs the attachment ID), stages
 *   editPost({featured_media}), and then parents the attachment to the post
 *   (POST /wp/v2/media/{id} {post}) — the documented Media\Mirror consumer
 *   obligation, so the 14-day eviction of unattached mirrors never collects
 *   an image that is in use. The mirror/parent REST plumbing is shared with
 *   the placeholder's Generate flow (generate/mirror.js).
 */
(function (wp, lib, mirror) {
  var el = wp.element.createElement;
  var useState = wp.element.useState;
  var registerPlugin = wp.plugins.registerPlugin;
  var PluginDocumentSettingPanel = wp.editor.PluginDocumentSettingPanel;
  var useSelect = wp.data.useSelect;
  var Button = wp.components.Button;
  var Notice = wp.components.Notice;

  function errorMessage(err) {
    if (err && err.code === 'rest_forbidden') {
      return 'You don\'t have access to Chronicler sessions — ask an administrator to grant you Chronicler access.';
    }
    return (err && err.message) || 'Request failed.';
  }

  var NO_RAW_MESSAGE =
    'This session has no stored Slack data yet — open it in the Chronicler session editor and press Refresh, then try again.';

  /** Fresh, rule-applied message attributes via the bundled engine (over the
   *  session's stored raw), or null when there is no stored raw. */
  function messagesFor(session, rules) {
    var engine = window.chroniclerSessionEngine;
    return engine && engine.messagesFor ? engine.messagesFor(session, rules) : null;
  }

  /** A shallow copy of the session with messages replaced — deriveTags reads
   *  session.messages + session.rule_ids off the object. */
  function withMessages(session, messages) {
    var copy = {};
    for (var key in session) {
      if (Object.prototype.hasOwnProperty.call(session, key)) copy[key] = session[key];
    }
    copy.messages = messages;
    return copy;
  }

  /**
   * The session this post's content points at: the first generated
   * chronicler/transcript wrapper (provenance sessionId > 0) or
   * chronicler/session-placeholder, depth-first.
   * Returns {sessionId, kind} or null.
   */
  function findSessionRef(blocks) {
    for (var i = 0; i < blocks.length; i++) {
      var block = blocks[i];
      if (
        block.name === 'chronicler/transcript' &&
        block.attributes && block.attributes.sessionId > 0
      ) {
        return { sessionId: block.attributes.sessionId, kind: 'generated' };
      }
      if (block.name === 'chronicler/session-placeholder') {
        return { sessionId: (block.attributes && block.attributes.sessionId) || 0, kind: 'placeholder' };
      }
      var inner = findSessionRef(block.innerBlocks || []);
      if (inner) return inner;
    }
    return null;
  }

  function fetchSession(sessionId) {
    return wp.apiFetch({ path: '/chronicler/v1/sessions/' + sessionId });
  }

  /** Find-or-create each post_tag term; resolves to the term id list. */
  function ensureTermIds(names) {
    return Promise.all(
      names.map(function (name) {
        return wp.data
          .dispatch('core')
          .saveEntityRecord('taxonomy', 'post_tag', { name: name }, { throwOnError: true })
          .then(function (record) {
            return record.id;
          })
          .catch(function (err) {
            // Creating an existing term 400s with term_exists + the id.
            if (err && err.code === 'term_exists' && err.data && err.data.term_id) {
              return err.data.term_id;
            }
            throw err;
          });
      })
    );
  }

  function Panel() {
    var ref = useSelect(function (select) {
      return findSessionRef(select('core/block-editor').getBlocks());
    }, []);
    var tagState = useState({ busy: false, note: '', error: '' });
    var tags = tagState[0];
    var setTags = tagState[1];
    // images: {kind:'idle'} | {busy} | {kind:'list', candidates} | note/error
    var imageState = useState({ kind: 'idle', busy: false, note: '', error: '' });
    var images = imageState[0];
    var setImages = imageState[1];

    if (!ref) {
      return null;
    }
    var sessionId = ref.sessionId;

    function applyTags() {
      setTags({ busy: true, note: '', error: '' });
      Promise.all([fetchSession(sessionId), wp.apiFetch({ path: '/chronicler/v1/rules' })])
        .then(function (results) {
          var session = results[0];
          var rules = Array.isArray(results[1]) ? results[1] : [];
          // Tags derive from the freshly ruled transcript (engine over stored
          // raw), so a wp-tag rule attached after the fetch is honored.
          var messages = messagesFor(session, rules);
          if (messages === null) {
            setTags({ busy: false, note: '', error: NO_RAW_MESSAGE });
            return null;
          }
          var names = lib.deriveTags(withMessages(session, messages), rules);
          if (names.length === 0) {
            setTags({ busy: false, note: 'No wp-tag rule matched this session — nothing to apply.', error: '' });
            return null;
          }
          return ensureTermIds(names).then(function (ids) {
            var current = wp.data.select('core/editor').getEditedPostAttribute('tags') || [];
            var merged = current.slice();
            ids.forEach(function (id) {
              if (merged.indexOf(id) === -1) merged.push(id);
            });
            wp.data.dispatch('core/editor').editPost({ tags: merged });
            setTags({
              busy: false,
              note: 'Staged ' + names.length + ' tag' + (names.length === 1 ? '' : 's') + ': ' + names.join(', ') + '. Save the post to apply.',
              error: '',
            });
          });
        })
        .catch(function (err) {
          setTags({ busy: false, note: '', error: errorMessage(err) });
        });
    }

    function listImages() {
      setImages({ kind: 'idle', busy: true, note: '', error: '' });
      Promise.all([fetchSession(sessionId), wp.apiFetch({ path: '/chronicler/v1/rules' })])
        .then(function (results) {
          var messages = messagesFor(results[0], Array.isArray(results[1]) ? results[1] : []);
          if (messages === null) {
            setImages({ kind: 'idle', busy: false, note: NO_RAW_MESSAGE, error: '' });
            return;
          }
          var candidates = lib.imageCandidates(messages);
          if (candidates.length === 0) {
            setImages({ kind: 'idle', busy: false, note: 'This session has no mirrorable images.', error: '' });
            return;
          }
          setImages({ kind: 'list', busy: false, candidates: candidates, note: '', error: '' });
        })
        .catch(function (err) {
          setImages({ kind: 'idle', busy: false, note: '', error: errorMessage(err) });
        });
    }

    function useImage(candidate) {
      setImages({ kind: 'list', busy: true, candidates: images.candidates, note: '', error: '' });
      mirror
        .mirrorImage(candidate.slackUrl, candidate.alt)
        .then(function (mirrored) {
          wp.data.dispatch('core/editor').editPost({ featured_media: mirrored.id });
          // Media\Mirror consumer obligation: parent the attachment to this
          // post so eviction (unattached mirrors > 14 days) never takes it.
          var postId = wp.data.select('core/editor').getCurrentPostId();
          return mirror.parentAttachment(mirrored.id, postId);
        })
        .then(function () {
          setImages({ kind: 'idle', busy: false, note: 'Featured image staged. Save the post to apply.', error: '' });
        })
        .catch(function (err) {
          setImages({ kind: 'list', busy: false, candidates: images.candidates, note: '', error: errorMessage(err) });
        });
    }

    function candidateLabel(candidate, index) {
      var text = candidate.caption || candidate.alt;
      if (!text) {
        var path = candidate.slackUrl.split(/[?#]/)[0].split('/');
        text = path[path.length - 1] || 'image ' + (index + 1);
      }
      return text;
    }

    return el(
      PluginDocumentSettingPanel,
      { name: 'chronicler-session', title: 'Chronicler' },
      el('p', { style: { marginTop: 0 } },
        ref.kind === 'generated'
          ? 'This post was generated from Chronicler session #' + sessionId + '.'
          : sessionId
            ? 'This post has a placeholder for Chronicler session #' + sessionId + '.'
            : 'This post has a Chronicler session placeholder. Pick a session in the block to enable these tools.'),
      el(
        Button,
        { variant: 'secondary', disabled: !sessionId || tags.busy, isBusy: tags.busy, onClick: applyTags },
        'Apply tags from session'
      ),
      tags.note ? el(Notice, { status: 'info', isDismissible: false }, tags.note) : null,
      tags.error ? el(Notice, { status: 'error', isDismissible: false }, tags.error) : null,
      el(
        Button,
        {
          variant: 'secondary',
          disabled: !sessionId || images.busy,
          isBusy: images.busy && images.kind !== 'list',
          onClick: listImages,
          style: { marginTop: '12px' },
        },
        'Set featured image from session'
      ),
      images.kind === 'list'
        ? el(
            'ul',
            { style: { margin: '8px 0 0' } },
            images.candidates.map(function (candidate, index) {
              return el(
                'li',
                { key: candidate.slackUrl },
                el(
                  Button,
                  { variant: 'link', disabled: images.busy, onClick: function () { useImage(candidate); } },
                  candidateLabel(candidate, index)
                )
              );
            })
          )
        : null,
      images.note ? el(Notice, { status: 'info', isDismissible: false }, images.note) : null,
      images.error ? el(Notice, { status: 'error', isDismissible: false }, images.error) : null
    );
  }

  registerPlugin('chronicler-session-tools', { render: Panel });
})(window.wp, window.chroniclerSessionBlocks, window.chroniclerMirror);
