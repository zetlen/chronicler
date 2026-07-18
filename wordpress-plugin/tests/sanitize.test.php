<?php
// Write-boundary sanitization (#159). Chronicler\Sanitize routes stored-HTML
// fields through wp_kses_post (stubbed minimally in run.php — the real
// filter is WordPress's own, verified at runtime in wp-env) and strips
// markup out of stylesheet strings, where '<' has no legitimate use.
// Included by run.php.

use Chronicler\Rest\Schemas;
use Chronicler\Sanitize;

// --- CSS guard: stripping '<' makes a </style> breakout unwritable. -----
check(
    'css passes ordinary CSS through untouched',
    Sanitize::css('.slack-log { color: teal; }') === '.slack-log { color: teal; }'
);
check(
    'css strips the style-element breakout',
    strpos(Sanitize::css('</style><script>alert(1)</script>'), '<') === false
);
check('css leaves the harmless remainder', Sanitize::css('a</style>b') === 'a/style>b');

// --- The transcript block's combined stylesheet (blocks.php emission). ---
$css = chronicler_transcript_css('.slack-log{}', 'x{}</style><script>alert(1)</script>');
check(
    'transcript css keeps both stylesheets',
    strpos($css, '.slack-log{}') === 0 && strpos($css, 'x{}') !== false,
    $css
);
check('transcript css cannot close the emitted style element', strpos($css, '<') === false, $css);
check('transcript css trims to empty', chronicler_transcript_css('', '') === '');
check(
    'the emitted style block has exactly one closing tag',
    substr_count('<style>' . $css . '</style>', '</style>') === 1
);

// --- Message HTML fields → wp_kses_post; render-escaped fields untouched. ---
$message = [
    'html' => '<div class="slk-msg">sys</div><script>alert(1)</script>',
    'authorName' => 'literal <script> stays: the renderer escapes it',
    'anchorId' => 'msg-1',
    'avatarHtml' => '<script>a()</script>',
    'headHtml' => '<span class="slk-msg__time">3:00 PM</span><script>h()</script>',
    'bodyHtml' => '<strong>hi</strong><script>steal()</script>',
    'extrasHtml' => '<script>e()</script>',
    'reactionsHtml' => '<script>r()</script>',
];
$clean = Sanitize::tree([$message])[0];
foreach (['html', 'avatarHtml', 'headHtml', 'bodyHtml', 'extrasHtml', 'reactionsHtml'] as $field) {
    check("tree strips script markup from $field", stripos($clean[$field], '<script') === false, $clean[$field]);
}
check('tree keeps allowed markup intact', $clean['bodyHtml'] === '<strong>hi</strong>steal()', $clean['bodyHtml']);
check('tree keeps the surviving fragment of html', $clean['html'] === '<div class="slk-msg">sys</div>alert(1)', $clean['html']);
check('tree leaves render-escaped text fields verbatim', $clean['authorName'] === $message['authorName']);
check('tree leaves non-HTML fields verbatim', $clean['anchorId'] === 'msg-1');
check('tree tolerates non-arrays', Sanitize::tree('nope') === 'nope');

// --- editorState: customCss guarded, nested HTML-named members sanitized,
// --- client-owned plain state (names!) never rewritten. -----------------
$state = Sanitize::tree([
    'scheme' => 'custom-dark',
    'customCss' => 'x{}</style><script>alert(1)</script>',
    'userOverrides' => ['U1' => ['name' => 'D&D <3', 'color' => '#123456']],
    'stash' => ['bodyHtml' => '<script>nested()</script>'],
]);
check('tree strips markup from customCss', strpos($state['customCss'], '<') === false, $state['customCss']);
check('tree sanitizes nested HTML-named members', stripos($state['stash']['bodyHtml'], '<script') === false);
check('tree leaves override names alone', $state['userOverrides']['U1']['name'] === 'D&D <3');
check('tree leaves scheme alone', $state['scheme'] === 'custom-dark');

// --- The write routes actually attach the callback. ---------------------
$sanitizer = [Sanitize::class, 'tree'];
foreach (['sessionCreateArgs', 'sessionUpdateArgs'] as $args) {
    $set = Schemas::$args();
    check("$args sanitizes messages", ($set['messages']['sanitize_callback'] ?? null) === $sanitizer);
    check("$args sanitizes editorState", ($set['editorState']['sanitize_callback'] ?? null) === $sanitizer);
}
check(
    'settingsPutArgs sanitizes channelDefaults',
    (Schemas::settingsPutArgs()['channelDefaults']['sanitize_callback'] ?? null) === $sanitizer
);
check(
    'importArgs sanitizes presets',
    (Schemas::importArgs()['presets']['sanitize_callback'] ?? null) === $sanitizer
);
