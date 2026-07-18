/**
 * Editor-side definitions for the chronicler/* blocks: plain ES5 against the
 * wp.* globals, so the plugin needs no build step. Containers edit as nested
 * InnerBlocks (enabling select/reorder/delete) and save their inner block
 * grammar. The message block has two edit surfaces: v3 blocks (authorName
 * or bodyHtml set) get an editable bubble — RichText fields for author and
 * body, an inspector with a color picker and image controls — while legacy
 * v2 blocks (and system messages, which only ever carry `html`) preview
 * their pre-rendered html read-only. Either way nothing is saved to post
 * content; the blocks are dynamic, rendered by PHP from attributes on the
 * front end.
 */
(function (wp) {
  var el = wp.element.createElement;
  var Fragment = wp.element.Fragment;
  var RawHTML = wp.element.RawHTML;
  var InnerBlocks = wp.blockEditor.InnerBlocks;
  var InspectorControls = wp.blockEditor.InspectorControls;
  var useBlockProps = wp.blockEditor.useBlockProps;
  var RichText = wp.blockEditor.RichText;
  var MediaUpload = wp.blockEditor.MediaUpload;
  var MediaUploadCheck = wp.blockEditor.MediaUploadCheck;
  var PanelBody = wp.components.PanelBody;
  var SelectControl = wp.components.SelectControl;
  var TextareaControl = wp.components.TextareaControl;
  var TextControl = wp.components.TextControl;
  var Button = wp.components.Button;
  var ColorPalette = wp.components.ColorPalette;
  var ToggleControl = wp.components.ToggleControl;

  // ------------------------------------------------------------------
  // Identity colors: ported from lib/transform/color.ts (PALETTE /
  // DARK_PALETTE / darkVariantOf). editorScript.test.ts enforces parity.
  // ------------------------------------------------------------------
  var PALETTE = ['#3d6bbf', '#c22e1d', '#177245', '#8e44ad', '#a2540a',
    '#c9438a', '#0c828d', '#7757ff', '#617400', '#a3195b'];
  var DARK_PALETTE = ['#6a93d8', '#e06055', '#2eb872', '#c778dd', '#ef8632',
    '#ef91bd', '#18b3c2', '#9a85ff', '#c9b210', '#ea6ba1'];
  function darkVariantOf(color) {
    var i = PALETTE.indexOf(color);
    return i === -1 ? color : DARK_PALETTE[i];
  }

  // Inline inventory check: mirrors lib/transform/richTextSafe.ts. Bodies
  // outside the inventory (bot Block Kit figures/dividers) stay read-only.
  var SAFE_TAGS = { strong: 1, em: 1, s: 1, del: 1, a: 1, code: 1, br: 1, span: 1, img: 1 };
  function isRichTextSafeHtml(html) {
    var re = /<\/?([a-zA-Z][a-zA-Z0-9]*)/g;
    var m;
    while ((m = re.exec(html || ''))) {
      if (!Object.prototype.hasOwnProperty.call(SAFE_TAGS, m[1].toLowerCase())) return false;
    }
    return true;
  }

  // RichText's onChange for a plain-text field (no formats) still hands back
  // its HTML-escaped serialization ("Daisy & co" arrives as "Daisy &amp;
  // co"). authorName is a plain-text attribute that PHP/TS escape again on
  // render, so it must be decoded before setAttributes or the escaping
  // compounds. Decodes only the five entities RichText's serializer
  // produces; &amp; decodes LAST so an already-escaped entity like
  // "&amp;lt;" comes back as the literal text "&lt;", not "<".
  function decodeRichTextValue(v) {
    if (typeof v !== 'string') return v;
    return v
      .replace(/&lt;/g, '<')
      .replace(/&gt;/g, '>')
      .replace(/&quot;/g, '"')
      .replace(/&#39;/g, "'")
      .replace(/&amp;/g, '&');
  }

  // The published markup's custom inline formats, so RichText edits keep
  // them intact. Order and tag/class pairs are pinned to
  // RICH_TEXT_FORMAT_MARKUP in lib/transform/richTextSafe.ts by test.
  var noEdit = function () { return null; };
  wp.richText.registerFormatType('chronicler/mention',
    { title: 'Mention', tagName: 'span', className: 's-mention', edit: noEdit });
  wp.richText.registerFormatType('chronicler/emoji-name',
    { title: 'Emoji', tagName: 'span', className: 's-emoji', edit: noEdit });
  wp.richText.registerFormatType('chronicler/emoji-image',
    { title: 'Custom emoji', tagName: 'img', className: 'slk-emoji', object: true,
      attributes: { src: 'src', alt: 'alt', loading: 'loading' }, edit: noEdit });
  wp.richText.registerFormatType('chronicler/quote',
    { title: 'Quote', tagName: 'span', className: 'slk-quote', edit: noEdit });
  wp.richText.registerFormatType('chronicler/pre',
    { title: 'Code block', tagName: 'span', className: 'slk-pre', edit: noEdit });

  var ALLOWED_FORMATS = ['core/bold', 'core/italic', 'core/link', 'core/strikethrough',
    'core/code', 'chronicler/mention', 'chronicler/emoji-name',
    'chronicler/emoji-image', 'chronicler/quote', 'chronicler/pre'];

  // Message variants — mirrors lib/transform/variants.ts (editorScript.test.ts
  // enforces parity). Stored order is pinned to the vocabulary order.
  var MESSAGE_VARIANTS = ['ooc', 'important'];
  var VARIANT_LABELS = { ooc: 'Out of character', important: 'Important' };
  function variantClasses(variants) {
    var set = variants || [];
    return MESSAGE_VARIANTS.filter(function (v) { return set.indexOf(v) !== -1; })
      .map(function (v) { return 'slk-msg--' + v; });
  }
  function hasVariant(variants, v) { return (variants || []).indexOf(v) !== -1; }
  function toggleVariant(variants, v, on) {
    var set = (variants || []).filter(function (x) { return x !== v; });
    if (on) set.push(v);
    return MESSAGE_VARIANTS.filter(function (x) { return set.indexOf(x) !== -1; });
  }
  // The author shown in a bubble and whether the field stays editable. OOC
  // reveals the realName (matching message-render.php / composeBubble); since
  // realName is not an editable attribute, the field goes read-only when it is
  // shown. With no realName there's nothing to reveal, so the character name
  // stays — and stays editable.
  function authorView(a) {
    if (hasVariant(a.variants, 'ooc') && a.realName) {
      return { name: a.realName, editable: false };
    }
    return { name: a.authorName, editable: true };
  }

  // Exposed for lib/wordpress/editorScript.test.ts only.
  wp.chroniclerEditorInternals = {
    darkVariantOf: darkVariantOf,
    isRichTextSafeHtml: isRichTextSafeHtml,
    decodeRichTextValue: decodeRichTextValue,
    PALETTE: PALETTE,
    variantClasses: variantClasses,
    MESSAGE_VARIANTS: MESSAGE_VARIANTS,
    authorView: authorView,
  };

  function container(title, className, attributes) {
    return {
      title: title,
      category: 'text',
      attributes: attributes,
      edit: function () {
        return el('div', useBlockProps({ className: className }), el(InnerBlocks, {}));
      },
      save: function () {
        return el(InnerBlocks.Content);
      },
    };
  }

  // The transcript's editor view mirrors the PHP render_callback exactly:
  // class derivation from scheme/density, and both stylesheets injected
  // from attributes. There is no enqueued stylesheet anywhere — a post's
  // styles are its own data, in the editor as on the front end.
  wp.blocks.registerBlockType('chronicler/transcript', {
    title: 'Chronicler transcript',
    category: 'text',
    attributes: {
      scheme: { type: 'string', default: 'light' },
      density: { type: 'string', default: 'comfortable' },
      fontSize: { type: 'string', default: '' },
      baseCss: { type: 'string', default: '' },
      customCss: { type: 'string', default: '' },
      // Inert #102 generation provenance; must be registered here too or a
      // Gutenberg re-save would drop it from the serialized comment.
      sessionId: { type: 'number', default: 0 },
      generatedAt: { type: 'string', default: '' },
    },
    edit: function (props) {
      var a = props.attributes;
      var className = 'slack-log'
        + (a.scheme === 'dark' ? ' slk-dark' : '')
        + (a.density === 'compact' ? ' slk-density-compact' : '');
      var css = ((a.baseCss || '') + '\n' + (a.customCss || '')).trim();
      var children = [el(InnerBlocks, { key: 'inner' })];
      if (css) {
        children.unshift(el('style', { key: 'css' }, css));
      }
      // The post's styles are its own data, so they're editable right here;
      // the canvas re-renders live because the view derives from attributes.
      var inspector = el(InspectorControls, { key: 'inspector' },
        el(PanelBody, { title: 'Transcript' },
          el(SelectControl, {
            label: 'Color scheme',
            value: a.scheme,
            options: [
              { label: 'Light', value: 'light' },
              { label: 'Dark', value: 'dark' },
              { label: 'Custom (light base + custom CSS)', value: 'custom' },
            ],
            onChange: function (v) { props.setAttributes({ scheme: v }); },
          }),
          el(SelectControl, {
            label: 'Density',
            value: a.density,
            options: [
              { label: 'Comfortable', value: 'comfortable' },
              { label: 'Compact', value: 'compact' },
            ],
            onChange: function (v) { props.setAttributes({ density: v }); },
          }),
          el(SelectControl, {
            label: 'Font size',
            value: a.fontSize || '',
            options: [
              { label: 'Default (stylesheet)', value: '' },
              { label: 'Small (15px)', value: '15px' },
              { label: 'Medium (17px)', value: '17px' },
              { label: 'Large (19px)', value: '19px' },
              { label: 'Extra large (21px)', value: '21px' },
            ],
            onChange: function (v) { props.setAttributes({ fontSize: v }); },
          })
        ),
        el(PanelBody, { title: 'Custom CSS', initialOpen: false },
          el(TextareaControl, {
            label: 'Layered after the base stylesheet',
            help: 'Scope selectors under .slack-log.',
            rows: 10,
            value: a.customCss,
            onChange: function (v) { props.setAttributes({ customCss: v }); },
          })
        ),
        el(PanelBody, { title: 'Base stylesheet (advanced)', initialOpen: false },
          el(TextareaControl, {
            help: 'The stylesheet this transcript was published with. Edits affect this post only.',
            rows: 14,
            value: a.baseCss,
            onChange: function (v) { props.setAttributes({ baseCss: v }); },
          })
        )
      );
      var wrapProps = { className: className };
      if (a.fontSize) {
        wrapProps.style = { '--slk-font-size': a.fontSize };
      }
      return el(Fragment, null,
        inspector,
        el('div', useBlockProps(wrapProps), children)
      );
    },
    save: function () {
      return el(InnerBlocks.Content);
    },
  });
  wp.blocks.registerBlockType('chronicler/thread', container('Chronicler thread', 'slk-thread', {
    context: { type: 'boolean', default: false },
    contextNote: { type: 'string', default: '' },
  }));
  wp.blocks.registerBlockType('chronicler/replies', container('Chronicler thread replies', 'slk-thread__replies', {
    beforeNote: { type: 'string', default: '' },
    afterNote: { type: 'string', default: '' },
  }));

  wp.blocks.registerBlockType('chronicler/message', {
    title: 'Slack message',
    category: 'text',
    attributes: {
      html: { type: 'string', default: '' },
      rootClass: { type: 'string', default: '' },
      anchorId: { type: 'string', default: '' },
      authorName: { type: 'string', default: '' },
      authorColor: { type: 'string', default: '' },
      authorColorDark: { type: 'string', default: '' },
      avatarHtml: { type: 'string', default: '' },
      headHtml: { type: 'string', default: '' },
      bodyHtml: { type: 'string', default: '' },
      images: { type: 'array', default: [] },
      extrasHtml: { type: 'string', default: '' },
      reactionsHtml: { type: 'string', default: '' },
      variants: { type: 'array', default: [] },
      realName: { type: 'string', default: '' },
    },
    edit: function (props) {
      var a = props.attributes;
      var isLegacy = !a.authorName && !a.bodyHtml && a.html;
      if (isLegacy) {
        // v2 blocks (and system messages): the pre-rendered preview, read-only.
        return el('div', useBlockProps(), el(RawHTML, null, a.html));
      }

      var color = a.authorColor || '#64748b'; // slate default for new/color-less blocks (PALETTE has 10 entries)
      var rootClass = (a.rootClass || 'slk-msg slk-msg--text');
      var bodySafe = isRichTextSafeHtml(a.bodyHtml);
      var authorPreview = authorView(a);

      function setImage(i, key, value) {
        var next = a.images.map(function (im, j) {
          if (j !== i) return im;
          var copy = {};
          for (var k in im) copy[k] = im[k];
          copy[key] = value;
          return copy;
        });
        props.setAttributes({ images: next });
      }

      var figures = a.images.map(function (im, i) {
        return el('figure', { className: 'slk-image', key: 'img' + i },
          el('img', { src: im.src, alt: im.alt || '', loading: 'lazy' }),
          el('figcaption', null, im.caption || im.alt || ''));
      });

      var inspector = el(InspectorControls, { key: 'inspector' },
        el(PanelBody, { title: 'Author' },
          el(ColorPalette, {
            colors: PALETTE.map(function (c) { return { color: c }; }),
            value: color,
            onChange: function (v) {
              if (!v) return;
              props.setAttributes({ authorColor: v, authorColorDark: darkVariantOf(v) });
            },
          })
        ),
        el(PanelBody, { title: 'Images', initialOpen: false },
          a.images.map(function (im, i) {
            return el('div', { key: 'imgctl' + i },
              el(TextControl, { label: 'Alt text', value: im.alt || '',
                onChange: function (v) { setImage(i, 'alt', v); } }),
              el(TextControl, { label: 'Caption', value: im.caption || '',
                onChange: function (v) { setImage(i, 'caption', v); } }),
              el(Button, { isDestructive: true, variant: 'secondary',
                onClick: function () {
                  props.setAttributes({
                    images: a.images.filter(function (_, j) { return j !== i; }),
                  });
                } }, 'Remove image')
            );
          }),
          el(MediaUploadCheck, null, el(MediaUpload, {
            onSelect: function (media) {
              props.setAttributes({
                images: a.images.concat([{
                  src: media.url,
                  alt: media.alt || media.title || 'image',
                  caption: media.caption || media.title || '',
                }]),
              });
            },
            allowedTypes: ['image'],
            render: function (open) {
              return el(Button, { variant: 'primary', onClick: open.open }, 'Add image');
            },
          }))
        ),
        el(PanelBody, { title: 'Treatment', initialOpen: false },
          MESSAGE_VARIANTS.map(function (v) {
            return el(ToggleControl, {
              key: v,
              label: VARIANT_LABELS[v],
              checked: hasVariant(a.variants, v),
              onChange: function (on) {
                props.setAttributes({ variants: toggleVariant(a.variants, v, on) });
              },
            });
          })
        )
      );

      var bodyChildren = [];
      if (bodySafe) {
        bodyChildren.push(el(RichText, {
          key: 'body',
          tagName: 'span',
          value: a.bodyHtml,
          allowedFormats: ALLOWED_FORMATS,
          onChange: function (v) { props.setAttributes({ bodyHtml: v }); },
          placeholder: 'Message…',
        }));
      } else {
        // Bot Block Kit bodies carry figures/dividers no inline form can
        // represent; show them read-only so RichText can never mangle them.
        bodyChildren.push(el(RawHTML, { key: 'body' }, a.bodyHtml));
      }
      if (a.images.length > 0) {
        bodyChildren.push(el('div', { className: 'slk-images', key: 'figs' }, figures));
      }
      if (a.extrasHtml) bodyChildren.push(el(RawHTML, { key: 'extras' }, a.extrasHtml));

      return el(Fragment, null,
        inspector,
        el('div', useBlockProps({
          className: [rootClass]
            .concat(variantClasses(a.variants))
            .concat(a.className ? [a.className] : [])
            .join(' '),
          style: { '--slk-id': color, '--slk-id-dark': a.authorColorDark || darkVariantOf(color) },
        }),
          a.avatarHtml ? el(RawHTML, null, a.avatarHtml) : null,
          el('div', { className: 'slk-msg__main' },
            el('div', { className: 'slk-msg__head' },
              // OOC reveals the realName here, read-only, exactly as the front
              // end does (message-render.php / composeBubble). realName is not
              // an editable attribute, so the field can't be a RichText when
              // it's shown; otherwise the author is the editable authorName.
              authorPreview.editable
                ? el(RichText, {
                    tagName: 'span',
                    className: 'slk-msg__author',
                    value: a.authorName,
                    allowedFormats: [],
                    withoutInteractiveFormatting: true,
                    onChange: function (v) { props.setAttributes({ authorName: decodeRichTextValue(v) }); },
                    placeholder: 'Speaker',
                  })
                : el('span', { className: 'slk-msg__author' }, authorPreview.name),
              a.headHtml ? el(RawHTML, null, a.headHtml) : null
            ),
            el('div', { className: 'slk-msg__body' }, bodyChildren),
            a.reactionsHtml ? el(RawHTML, null, a.reactionsHtml) : null
          )
        )
      );
    },
    save: function () {
      return null;
    },
  });
})(window.wp);
