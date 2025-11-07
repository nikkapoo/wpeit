<?php
/**
 * Plugin Name: Event Image Bot
 * Plugin URI: https://github.com/nikkapoo/wpeit
 * Description: The ultimate automation tool for event websites. Import events from any iCal/.ics feed and automatically generate stunning, branded flyer images for each one.
 * Version: 2.2.0
 * Author: liibooz
 * Author URI: https://github.com/nikkapoo
 * Contributors: liibooz
 * Tags: events, import, ical, eventon, the events calendar, modern events calendar, automate, featured image, image generator
 * Requires at least: 5.5
 * Tested up to: 6.5
 * Stable tag: 2.2.0
 * Requires PHP: 7.4
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: event-image-bot
 */

// Safety check - exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

if (!defined('WPINC')) {
    die;
}

define('KPH_ICAL_IMPORTER_VERSION', '1.4.0');
define('KPH_ICAL_IMPORTER_PATH', plugin_dir_path(__FILE__));
define('KPH_ICAL_IMPORTER_URL', plugin_dir_url(__FILE__));

// Composer Autoloader
if (file_exists(KPH_ICAL_IMPORTER_PATH . 'vendor/autoload.php')) {
    require_once KPH_ICAL_IMPORTER_PATH . 'vendor/autoload.php';
} else {
    add_action('admin_notices', function() {
        echo '<div class="notice notice-error"><p>';
        echo __('<strong>Event Flyer Generator & Importer:</strong> The required <code>ics-parser</code> library is missing. Please run <code>composer install</code> in the plugin directory.', 'event-image-bot');
        echo '</p></div>';
    });
    return;
}

// Include all the plugin classes
require_once KPH_ICAL_IMPORTER_PATH . 'includes/class-kph-calendar-adapter.php';
require_once KPH_ICAL_IMPORTER_PATH . 'includes/class-kph-importer.php';
require_once KPH_ICAL_IMPORTER_PATH . 'includes/class-kph-admin-settings.php';
require_once KPH_ICAL_IMPORTER_PATH . 'includes/class-kph-location-manager.php';
require_once KPH_ICAL_IMPORTER_PATH . 'includes/class-kph-organizer-settings.php';
require_once KPH_ICAL_IMPORTER_PATH . 'includes/class-kph-event-header-generator.php';
require_once KPH_ICAL_IMPORTER_PATH . 'includes/class-kph-event-cleanup.php';


/**
 * Initialize the plugin.
 */
function kph_ical_importer_init() {
    $adapter = new KPH_Calendar_Adapter();
    
    // FIX: Corrected the method name from is_supported_plugin_active to is_supported_calendar_active
    if (!$adapter->is_supported_calendar_active()) {
         add_action('admin_notices', function() {
            echo '<div class="notice notice-error"><p>';
            echo __('<strong>Event Flyer Generator & Importer:</strong> No supported calendar plugin (EventON, The Events Calendar, or Modern Events Calendar) is active. Please activate one to use this plugin.', 'event-image-bot');
            echo '</p></div>';
        });
        return;
    }

    new KPH_Admin_Settings($adapter);
    new KPH_Location_Manager($adapter);
    new KPH_Organizer_Settings($adapter);
    new KPH_Event_Header_Generator($adapter);
    new KPH_Event_Cleanup($adapter);
    
    // Welcome screen redirect
    if (!get_option('kph_importer_first_activation')) {
        update_option('kph_importer_first_activation', '1');
        wp_safe_redirect(admin_url('admin.php?page=kph-importer-welcome'));
        exit;
    }
}
add_action('plugins_loaded', 'kph_ical_importer_init');


// --- Cron Job Setup ---

function kph_activate_cron() {
    if (!wp_next_scheduled('kph_ical_import_hook')) {
        wp_schedule_event(time(), 'twicedaily', 'kph_ical_import_hook');
    }
}
register_activation_hook(__FILE__, 'kph_activate_cron');

function kph_run_scheduled_import() {
    $adapter = new KPH_Calendar_Adapter();
    $importer = new KPH_Importer($adapter);
    $importer->run_import(true); // true indicates this is a cron run
}
add_action('kph_ical_import_hook', 'kph_run_scheduled_import');

function kph_deactivate_cron() {
    $timestamp = wp_next_scheduled('kph_ical_import_hook');
    wp_unschedule_event($timestamp, 'kph_ical_import_hook');
}
register_deactivation_hook(__FILE__, 'kph_deactivate_cron');