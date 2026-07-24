/**
 * Shared wp.data tag-staging for the generate flow: find-or-create each
 * post_tag term and stage the merged id list on the post with
 * editPost({tags}). The tag NAMES come from session-blocks.js deriveTags (the
 * pure wp-tag rule semantics); this module is only the wp.data side effect,
 * shared so the two callers stage identically:
 *
 * - the placeholder's Generate flow auto-applies them the moment the
 *   transcript is generated (generate/placeholder/index.js), and
 * - the sidebar's "Apply tags from session" button re-applies them on demand
 *   (generate/sidebar.js), e.g. after editing a wp-tag rule.
 *
 * Staging only — like the generated blocks themselves, nothing persists until
 * the post is saved.
 */
(function (wp) {
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

  /** Find-or-create the named terms and merge their ids into the post's staged
   *  tags (editPost), leaving any the user already set. Resolves to the names
   *  once staged. */
  function stageTags(names) {
    return ensureTermIds(names).then(function (ids) {
      var current = wp.data.select('core/editor').getEditedPostAttribute('tags') || [];
      var merged = current.slice();
      ids.forEach(function (id) {
        if (merged.indexOf(id) === -1) merged.push(id);
      });
      wp.data.dispatch('core/editor').editPost({ tags: merged });
      return names;
    });
  }

  window.chroniclerSessionTags = { ensureTermIds: ensureTermIds, stageTags: stageTags };
})(window.wp);
