<?php
// Pure name-resolution helpers (sheets/names.php).

check(
    'display_name_for prefers a non-empty goes-by',
    chronicler_sheets_display_name_for('WOLFGANG', 'Wolfgang Glizzy') === 'WOLFGANG'
);
check(
    'display_name_for falls back to the title when goes-by is empty',
    chronicler_sheets_display_name_for('', 'Wolfgang Glizzy') === 'Wolfgang Glizzy'
);
check(
    'display_name_for trims whitespace-only goes-by to the title',
    chronicler_sheets_display_name_for('   ', 'Wolfgang Glizzy') === 'Wolfgang Glizzy'
);

$members = [
    ['id' => 'U1', 'name' => 'alice', 'profile' => ['display_name' => 'Alice A', 'real_name' => 'Alice Anderson']],
    ['id' => 'U2', 'name' => 'bob', 'profile' => ['display_name' => '', 'real_name' => 'Bob Brown']],
    ['id' => 'U3', 'name' => 'carol', 'profile' => ['display_name' => '', 'real_name' => '']],
    ['id' => 'U4', 'name' => 'ghost', 'deleted' => true, 'profile' => ['display_name' => 'Ghost']],
    ['id' => 'U5', 'name' => 'botty', 'is_bot' => true, 'profile' => ['display_name' => 'Botty']],
    ['id' => 'USLACKBOT', 'name' => 'slackbot', 'profile' => ['display_name' => 'Slackbot']],
    ['id' => 'U6', 'name' => '', 'profile' => ['display_name' => '', 'real_name' => '']],
];
$parsed = chronicler_sheets_parse_slack_users($members);

check('parse prefers display_name', ($parsed['U1'] ?? null) === 'Alice A');
check('parse falls back to real_name', ($parsed['U2'] ?? null) === 'Bob Brown');
check('parse falls back to name', ($parsed['U3'] ?? null) === 'carol');
check('parse skips deleted users', !array_key_exists('U4', $parsed));
check('parse skips bots', !array_key_exists('U5', $parsed));
check('parse skips USLACKBOT', !array_key_exists('USLACKBOT', $parsed));
check('parse falls back to id when all names are empty', ($parsed['U6'] ?? null) === 'U6');
