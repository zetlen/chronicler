<?php
// wp-env verification driver for the #109 Rules CRUD screen — NOT part of
// tests/run.php (it needs a live WordPress). Simulates the classic metabox
// form's save_post path exactly (slashed $_POST, real nonce, real current
// user) plus in-process REST round trips, so the two write surfaces can be
// compared on the same install.
//
//   npx @wordpress/env run cli wp eval-file \
//     wp-content/plugins/wordpress-plugin/tests/fixtures/verify-109.php <case>
//
// Cases: valid | invalid | invalid-edit <id> | subscriber-save (needs a
// `verify_subscriber` user) | rest-shape | rest-invalid

use Chronicler\Rules\AdminPage;
use Chronicler\Store\Rules;

$case = $args[0] ?? 'valid';
wp_set_current_user(1); // admin

function chronicler_verify_form_save(array $fields): int
{
    $_POST[AdminPage::NONCE_FIELD] = wp_create_nonce(AdminPage::NONCE_ACTION);
    $_POST[AdminPage::FIELD] = wp_slash($fields); // as PHP's magic slashing would
    $id = wp_insert_post([
        'post_type' => Rules::POST_TYPE,
        'post_status' => 'publish',
        'post_title' => '(should be overwritten)',
    ], true);
    if (is_wp_error($id)) {
        echo "INSERT ERROR: " . $id->get_error_message() . "\n";
        exit(1);
    }
    return (int) $id;
}

switch ($case) {
    case 'valid':
        $id = chronicler_verify_form_save([
            'pattern' => 'ooc: <@U\\d+>',
            'flags' => 'im',
            'mode' => 'hide',
            'className' => '',
            'tagNames' => '',
            'description' => 'Hide OOC pings (admin form)',
        ]);
        $post = get_post($id);
        echo "id=$id status={$post->post_status} title={$post->post_title}\n";
        echo 'meta=' . get_post_meta($id, Rules::META_RULE, true) . "\n";
        echo 'served=' . wp_json_encode(Rules::get($id)) . "\n";
        break;

    case 'invalid':
        $id = chronicler_verify_form_save([
            'pattern' => '',
            'flags' => 'i',
            'mode' => 'hide',
            'className' => '',
            'tagNames' => '',
            'description' => 'should be rejected',
        ]);
        $post = get_post($id);
        $meta = get_post_meta($id, Rules::META_RULE, true);
        echo "id=$id status={$post->post_status} title={$post->post_title}\n";
        echo 'meta=' . var_export($meta, true) . "\n";
        echo 'served=' . var_export(Rules::get($id), true) . "\n";
        echo 'in-all=' . var_export(in_array($id, array_column(Rules::all(), 'id'), true), true) . "\n";
        break;

    case 'invalid-edit':
        // A bad edit to a valid rule must keep the stored config and status.
        $ruleId = (int) ($args[1] ?? 0);
        $_POST[AdminPage::NONCE_FIELD] = wp_create_nonce(AdminPage::NONCE_ACTION);
        $_POST[AdminPage::FIELD] = wp_slash([
            'pattern' => '',
            'flags' => 'i',
            'mode' => 'hide',
            'className' => '',
            'tagNames' => '',
            'description' => 'attempted clobber',
        ]);
        wp_update_post(['ID' => $ruleId, 'post_title' => 'x']);
        $post = get_post($ruleId);
        echo "id=$ruleId status={$post->post_status}\n";
        echo 'served=' . wp_json_encode(Rules::get($ruleId)) . "\n";
        break;

    case 'subscriber-save':
        // A subscriber must not be able to drive the save handler.
        $sub = get_user_by('login', 'verify_subscriber');
        wp_set_current_user($sub ? $sub->ID : 0);
        echo 'can_edit_posts_cap=' . var_export(current_user_can(get_post_type_object(Rules::POST_TYPE)->cap->edit_posts), true) . "\n";
        echo 'can_create=' . var_export(current_user_can(get_post_type_object(Rules::POST_TYPE)->cap->create_posts), true) . "\n";
        break;

    case 'rest-shape':
        // In-process REST round trip incl. permission callback.
        $request = new WP_REST_Request('POST', '/chronicler/v1/rules');
        $request->set_body_params([
            'pattern' => '^#session-start',
            'mode' => 'start',
            'description' => 'Opener (REST)',
        ]);
        $response = rest_do_request($request);
        echo 'rest-create status=' . $response->get_status() . ' body=' . wp_json_encode($response->get_data()) . "\n";
        break;

    case 'rest-invalid':
        $request = new WP_REST_Request('POST', '/chronicler/v1/rules');
        $request->set_body_params(['pattern' => '', 'mode' => 'hide']);
        $response = rest_do_request($request);
        echo 'rest-invalid status=' . $response->get_status() . ' code=' . wp_json_encode($response->get_data()['code'] ?? null) . "\n";
        break;
}
