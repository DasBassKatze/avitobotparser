<?php
/**
 * Plugin Name: Авито → WooCommerce · GG
 * Description: Подготовка, редактирование и перенос собственных объявлений в WooCommerce.
 * Version: 0.1.0
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Requires Plugins: woocommerce
 * License: GPL-2.0-or-later
 */
if (!defined('ABSPATH')) { exit; }
define('GG_AVITO_DIR', plugin_dir_path(__FILE__));
require_once GG_AVITO_DIR . 'includes/class-bundle.php';
require_once GG_AVITO_DIR . 'includes/class-plugin.php';
add_action('plugins_loaded', [GG_Avito_Plugin::class, 'boot']);
