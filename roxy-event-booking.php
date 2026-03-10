<?php
/**
 * Plugin Name: Roxy Event Booking (WooCommerce + Sling)
 * Description: Private/Public event booking calendar for Newport Roxy. Customers can book time slots, pay via WooCommerce, and automatically create staffing shifts in Sling.
 * Version: 1.3.2
 * Author: Newport Roxy (AI Team)
 * Text Domain: roxy-event-booking
 */

if (!defined('ABSPATH')) exit;

define('ROXY_EB_VERSION', '1.2.9');
define('ROXY_EB_PLUGIN_FILE', __FILE__);
define('ROXY_EB_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ROXY_EB_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once ROXY_EB_PLUGIN_DIR . 'includes/schema.php';
require_once ROXY_EB_PLUGIN_DIR . 'includes/settings.php';
require_once ROXY_EB_PLUGIN_DIR . 'includes/repository.php';
require_once ROXY_EB_PLUGIN_DIR . 'includes/availability.php';
require_once ROXY_EB_PLUGIN_DIR . 'includes/woo.php';
require_once ROXY_EB_PLUGIN_DIR . 'includes/shortcode.php';
require_once ROXY_EB_PLUGIN_DIR . 'includes/my-account.php';
require_once ROXY_EB_PLUGIN_DIR . 'includes/admin-pages.php';
require_once ROXY_EB_PLUGIN_DIR . 'includes/emails.php';
require_once ROXY_EB_PLUGIN_DIR . 'includes/sling.php';

register_activation_hook(__FILE__, function () {
    if (!class_exists('WooCommerce')) {
        // Still create DB tables; Woo can be enabled later.
        roxy_eb_install_schema();
        update_option('roxy_eb_db_version', ROXY_EB_VERSION);
        return;
    }
    roxy_eb_install_schema();
    roxy_eb_maybe_create_booking_product();
    update_option('roxy_eb_db_version', ROXY_EB_VERSION);
});

add_action('plugins_loaded', function () {
    // Lightweight schema migration on upgrades
    $dbv = get_option('roxy_eb_db_version');
    if ($dbv !== ROXY_EB_VERSION) {
        roxy_eb_install_schema();
        update_option('roxy_eb_db_version', ROXY_EB_VERSION);
    }

    // Ensure WC exists before initializing Woo hooks
    roxy_eb_register_settings();
    roxy_eb_register_shortcodes();
    roxy_eb_register_my_account_endpoints();
    roxy_eb_register_admin_pages();

    if (class_exists('WooCommerce')) {
        roxy_eb_register_woo_hooks();
    }
});
