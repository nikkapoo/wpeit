<?php

if (!defined('WPINC')) {
    die;
}

class KPH_Organizer_Settings {

    private $adapter;

    public function __construct($adapter) {
        $this->adapter = $adapter;
        add_action('admin_menu', [$this, 'add_plugin_page']);
        add_action('admin_init', [$this, 'handle_form_submissions']);
    }

    public function add_plugin_page() {
        add_submenu_page(
            'edit.php?post_type=' . $this->adapter->get_post_type(),
            'Organizer Settings',
            'Organizer Settings',
            'manage_options',
            'kph-organizer-settings',
            [$this, 'create_admin_page']
        );
    }

    public function create_admin_page() {
        ?>
        <div class="wrap">
            <h1>Organizer Settings <span style="font-size: 12px; color: #666;">v<?php echo KPH_ICAL_IMPORTER_VERSION; ?></span></h1>
            <p>Tools for managing event organizers.</p>
            <hr>
            <h2>Cleanup Organizers</h2>
            <p>This tool will find organizers where all of their events occur at a location with the exact same name. It will then create a 301 redirect from the organizer's page to the location's page for better SEO and user experience.</p>
            <form method="post" action="">
                <?php wp_nonce_field('kph_cleanup_organizers_nonce', 'kph_cleanup_nonce'); ?>
                <input type="hidden" name="action" value="cleanup_organizers">
                <?php submit_button('Run Organizer Cleanup'); ?>
            </form>
        </div>
        <?php
    }

    public function handle_form_submissions() {
        if (isset($_POST['action']) && $_POST['action'] === 'cleanup_organizers' && isset($_POST['kph_cleanup_nonce']) && wp_verify_nonce($_POST['kph_cleanup_nonce'], 'kph_cleanup_organizers_nonce')) {
            $this->bulk_cleanup_organizers();
        }
    }

    private function bulk_cleanup_organizers() {
        if (!$this->adapter->get_organizer_taxonomy()) {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-warning is-dismissible"><p>The active calendar plugin does not support organizers.</p></div>';
            });
            return;
        }

        $organizers = get_terms(['taxonomy' => $this->adapter->get_organizer_taxonomy(), 'hide_empty' => false]);
        $redirected_count = 0;

        foreach ($organizers as $organizer) {
            if (self::check_and_redirect_organizer($organizer->term_id)) {
                $redirected_count++;
            }
        }

        add_action('admin_notices', function() use ($redirected_count) {
            $message = sprintf('Cleanup complete. Created or confirmed %d redirects.', $redirected_count);
            echo '<div class="notice notice-success is-dismissible"><p>' . $message . '</p></div>';
        });
    }

    public static function check_and_redirect_organizer($organizer_term_id) {
        $adapter = new KPH_Calendar_Adapter();
        if (!$adapter->is_supported_plugin_active() || !$adapter->get_organizer_taxonomy()) {
            return false;
        }

        $organizer = get_term($organizer_term_id, $adapter->get_organizer_taxonomy());
        if (!$organizer || is_wp_error($organizer)) {
            return false;
        }

        $matching_location = get_term_by('name', $organizer->name, $adapter->get_location_taxonomy());
        if (!$matching_location) {
            return false;
        }

        $events_query = new WP_Query([
            'post_type' => $adapter->get_post_type(),
            'posts_per_page' => -1,
            'fields' => 'ids',
            'tax_query' => [
                [
                    'taxonomy' => $adapter->get_organizer_taxonomy(),
                    'field'    => 'term_id',
                    'terms'    => $organizer->term_id,
                ],
            ],
        ]);

        if (!$events_query->have_posts()) {
            return false;
        }

        $all_match = true;
        foreach ($events_query->posts as $post_id) {
            $event_locations = wp_get_post_terms($post_id, $adapter->get_location_taxonomy());
            if (count($event_locations) !== 1 || $event_locations[0]->term_id !== $matching_location->term_id) {
                $all_match = false;
                break;
            }
        }

        if ($all_match) {
            $redirects = get_option('kph_organizer_redirects', []);
            $redirects[$organizer->term_id] = $matching_location->term_id;
            update_option('kph_organizer_redirects', $redirects);
            return true;
        }

        return false;
    }
}
