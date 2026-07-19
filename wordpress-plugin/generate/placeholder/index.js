/**
 * Editor script for chronicler/session-placeholder (#102): plain ES5 against
 * the wp.* globals in the house no-build style of editor.js. The block is a
 * pure editor affordance — pick a stored Session, press Generate, and the
 * placeholder replaces itself with the generated chronicler/transcript block
 * tree (wp.data replaceBlocks). save() returns null: only the placeholder
 * comment (with its sessionId) ever persists, never generated output, and the
 * block renders nothing on the front end (no render_callback).
 *
 * Generation mirrors images first: stored messages reference Slack-hosted
 * images through the capability-gated chronicler/v1/image route, which an
 * anonymous reader of the published post cannot fetch (401). Before creating
 * blocks, every referenced image is mirrored into the media library
 * (generate/mirror.js, ~4 at a time with progress in the UI), each mirrored
 * attachment is parented to this post (the Media\Mirror eviction contract —
 * getCurrentPostId() exists even on the auto-draft), and the stored srcs are
 * rewritten to the local uploads URLs (session-blocks.js rewriteImageUrls,
 * pure). A failed mirror warns on the console and leaves that src as-is —
 * it never blocks generation.
 *
 * Server-side registration comes from generate/placeholder/block.json via
 * register_block_type (Chronicler\Editor\Generation); the client re-declares
 * the same attribute schema so the registration is self-contained either way.
 *
 * Data access: wp.apiFetch — the block editor preconfigures it with same-
 * origin cookie auth + the REST nonce, so the chronicler/v1 routes' cookie +
 * X-WP-Nonce contract holds with no extra wiring. A 403 (author without the
 * chronicler_compose capability) surfaces as the error state.
 */
(function (wp, lib, mirror) {
  var el = wp.element.createElement;
  var useState = wp.element.useState;
  var useEffect = wp.element.useEffect;
  var useBlockProps = wp.blockEditor.useBlockProps;
  var SelectControl = wp.components.SelectControl;
  var Button = wp.components.Button;
  var Spinner = wp.components.Spinner;
  var Notice = wp.components.Notice;
  var Placeholder = wp.components.Placeholder;

  // Localized by Editor\Generation::registerAssets (adminUrl of the
  // Chronicler session-editor page, for the no-sessions empty state).
  var boot = window.chroniclerGenerateBoot || {};

  function errorMessage(err) {
    if (err && err.code === 'rest_forbidden') {
      return 'You don\'t have access to Chronicler sessions — ask an administrator to grant you Chronicler access.';
    }
    return (err && err.message) || 'Request failed.';
  }

  /** "#channel — start → end (N messages)" for the session picker. */
  function sessionLabel(session) {
    var channel = (session.channel && (session.channel.name || session.channel.id)) || 'unknown channel';
    var range = (session.start || '?') + ' → ' + (session.end || '?');
    return '#' + channel + ' — ' + range + ' (' + (session.messageCount || 0) + ' messages)';
  }

  /** Descriptor tree (session-blocks.js) → real blocks via createBlock. */
  function toBlock(node) {
    return wp.blocks.createBlock(
      node.name,
      node.attributes,
      (node.innerBlocks || []).map(toBlock)
    );
  }

  function Edit(props) {
    var attributes = props.attributes;
    var blockProps = useBlockProps();
    // list: {kind: 'loading'} | {kind: 'ready', sessions: []} | {kind: 'error', message}
    var listState = useState({ kind: 'loading' });
    var list = listState[0];
    var setList = listState[1];
    // gen: {busy, error, progress} — progress is the mirroring status line.
    var genState = useState({ busy: false, error: '', progress: '' });
    var gen = genState[0];
    var setGen = genState[1];

    // GET /sessions is paginated (#164): accumulate pages until a short one
    // arrives, so installs past the default page size keep their whole
    // history in the picker. per_page=200 is the route's max — one round
    // trip for all but the largest archives. (wp.apiFetch converts the
    // path's query for plain-permalink roots itself.)
    function fetchAllSessions(page, acc) {
      return wp
        .apiFetch({ path: '/chronicler/v1/sessions?page=' + page + '&per_page=200' })
        .then(function (sessions) {
          var batch = Array.isArray(sessions) ? sessions : [];
          var all = acc.concat(batch);
          return batch.length === 200 ? fetchAllSessions(page + 1, all) : all;
        });
    }

    function loadSessions() {
      setList({ kind: 'loading' });
      fetchAllSessions(1, [])
        .then(function (sessions) {
          setList({ kind: 'ready', sessions: sessions });
        })
        .catch(function (err) {
          setList({ kind: 'error', message: errorMessage(err) });
        });
    }

    useEffect(loadSessions, []);

    function generate() {
      if (!attributes.sessionId) return;
      setGen({ busy: true, error: '', progress: '' });
      wp.apiFetch({ path: '/chronicler/v1/sessions/' + attributes.sessionId })
        .then(function (session) {
          var messages = Array.isArray(session.messages) ? session.messages : [];
          var urls = lib.collectImageUrls(messages);
          return mirror
            .mirrorAll(urls, {
              concurrency: 4,
              onProgress: function (done, total) {
                setGen({
                  busy: true,
                  error: '',
                  progress: 'Mirroring images (' + done + ' of ' + total + ')…',
                });
              },
            })
            .then(function (mirrored) {
              // Media\Mirror consumer obligation: parent every mirrored
              // attachment to this post (the id exists on the auto-draft)
              // so eviction (unattached mirrors > 14 days) never takes an
              // image the published post references. Best-effort like the
              // mirroring itself: a failure warns, never blocks.
              var postId = wp.data.select('core/editor').getCurrentPostId();
              var urlMap = {};
              var parenting = Object.keys(mirrored.byUrl).map(function (url) {
                urlMap[url] = mirrored.byUrl[url].url;
                if (!postId) return null;
                return mirror
                  .parentAttachment(mirrored.byUrl[url].id, postId)
                  .catch(function (err) {
                    console.warn(
                      'Chronicler: could not parent mirrored attachment ' +
                        mirrored.byUrl[url].id + ' to post ' + postId + '.',
                      err
                    );
                  });
              });
              return Promise.all(parenting).then(function () {
                var rewritten = {};
                for (var key in session) {
                  if (Object.prototype.hasOwnProperty.call(session, key)) {
                    rewritten[key] = session[key];
                  }
                }
                rewritten.messages = lib.rewriteImageUrls(messages, urlMap);
                var tree = lib.sessionToBlocks(rewritten, {
                  generatedAt: new Date().toISOString(),
                  baseCss: window.chroniclerTranscriptBaseCss || '',
                });
                wp.data
                  .dispatch('core/block-editor')
                  .replaceBlocks(props.clientId, [toBlock(tree)]);
                // No setGen after this: replaceBlocks unmounts the placeholder.
              });
            });
        })
        .catch(function (err) {
          setGen({ busy: false, error: errorMessage(err), progress: '' });
        });
    }

    var body;
    if (list.kind === 'loading') {
      body = el(Spinner, { key: 'spin' });
    } else if (list.kind === 'error') {
      body = el(
        'div',
        { key: 'err', style: { width: '100%' } },
        el(Notice, { status: 'error', isDismissible: false }, list.message),
        el(Button, { variant: 'secondary', onClick: loadSessions, style: { marginTop: '8px' } }, 'Retry')
      );
    } else if (list.sessions.length === 0) {
      body = el(
        'div',
        { key: 'empty', style: { width: '100%' } },
        el('p', null, 'No Chronicler sessions have been saved yet. Create one in the session editor, then come back to generate its transcript.'),
        boot.chroniclerAdminUrl
          ? el(Button, { variant: 'primary', href: boot.chroniclerAdminUrl }, 'Open Chronicler')
          : null
      );
    } else {
      body = el(
        'div',
        { key: 'pick', style: { width: '100%' } },
        el(SelectControl, {
          label: 'Session',
          value: attributes.sessionId ? String(attributes.sessionId) : '',
          options: [{ label: 'Select a session…', value: '' }].concat(
            list.sessions.map(function (session) {
              return { label: sessionLabel(session), value: String(session.id) };
            })
          ),
          onChange: function (value) {
            props.setAttributes({ sessionId: parseInt(value, 10) || 0 });
          },
          __nextHasNoMarginBottom: true,
        }),
        gen.error
          ? el(Notice, { status: 'error', isDismissible: false }, gen.error)
          : null,
        el(
          Button,
          {
            variant: 'primary',
            disabled: !attributes.sessionId || gen.busy,
            isBusy: gen.busy,
            onClick: generate,
            style: { marginTop: '8px' },
          },
          gen.busy ? 'Generating…' : 'Generate transcript'
        ),
        gen.busy && gen.progress
          ? el('p', { style: { marginTop: '8px', marginBottom: 0 } }, gen.progress)
          : null
      );
    }

    return el(
      'div',
      blockProps,
      el(
        Placeholder,
        {
          icon: 'book-alt',
          label: 'Chronicler session',
          instructions:
            'Generating replaces this placeholder with the transcript. To regenerate later, insert a fresh placeholder.',
        },
        body
      )
    );
  }

  wp.blocks.registerBlockType('chronicler/session-placeholder', {
    title: 'Chronicler session',
    category: 'text',
    icon: 'book-alt',
    attributes: {
      sessionId: { type: 'number', default: 0 },
    },
    edit: Edit,
    // The placeholder never persists output — only its own comment survives.
    save: function () {
      return null;
    },
  });
})(window.wp, window.chroniclerSessionBlocks, window.chroniclerMirror);
