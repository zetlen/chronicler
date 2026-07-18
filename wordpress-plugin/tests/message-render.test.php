<?php
// wordpress-plugin/tests/message-render.test.php
$fixtures = json_decode(
    file_get_contents(__DIR__ . '/fixtures/message-render.json'),
    true
);

foreach (['parity', 'defensive'] as $group) {
    foreach ($fixtures[$group] as $case) {
        $expected = $case['expected'];
        check(
            "message-render {$group}: {$case['name']} has a baked expectation",
            $expected !== '__BUILD_FROM_PHP__'
        );
        $actual = chronicler_render_message($case['attributes']);
        check(
            "message-render {$group}: {$case['name']}",
            $actual === $expected,
            "expected " . var_export($expected, true) . " got " . var_export($actual, true)
        );
    }
}

// Transcript font-size control (#70): strict whitelist, everything else unset.
check('transcript font-size accepts whitelisted values',
    chronicler_transcript_font_size('15px') === '15px'
    && chronicler_transcript_font_size('17px') === '17px'
    && chronicler_transcript_font_size('19px') === '19px'
    && chronicler_transcript_font_size('21px') === '21px');
check('transcript font-size rejects everything else',
    chronicler_transcript_font_size('') === ''
    && chronicler_transcript_font_size('19px;background:red') === ''
    && chronicler_transcript_font_size(null) === ''
    && chronicler_transcript_font_size(['19px']) === '');
