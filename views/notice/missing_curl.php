<?php
if (!defined('ABSPATH')) {
    exit;
}

/** @var \Inpsyde\BackWPup\Notice\NoticeMessage $bind */
?>
<p><strong><?php esc_html_e('cURL functions are missing', 'backwpup'); ?></strong></p>
<p><?php esc_html_e('PHP cURL functions are missing in your installation. Not all features of BackWPup will work without them, e.g. some backup destinations cannot be used. Please ask your hosting provider to enable the PHP cURL extension.', 'backwpup'); ?></p>
