<?php
if (!defined('WP_UNINSTALL_PLUGIN')) { exit; }
wp_clear_scheduled_hook('gg_avito_cleanup');
// Imported products and preparation records are deliberately retained.
