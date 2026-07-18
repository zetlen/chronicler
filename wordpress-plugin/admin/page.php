<?php
// Wiring for the Chronicler session-editor admin page (#97). The logic lives
// in Chronicler\Admin\Page (src/Admin/Page.php); this file sits in admin/ so
// the page and its dist/ bundle (#96 build output) stay together.

if (!defined('ABSPATH')) {
    exit;
}

(new Chronicler\Admin\Page())->register();
