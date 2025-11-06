<?php

if (!defined('WPINC')) {
    die;
}

class KPH_Event_Cleanup {

    private $adapter;
    private $options;

    public function __construct($adapter) {
        $this->adapter = $adapter;
        add_action('admin_menu', [$this, 'add_plugin_page']);
        add_action('admin_init', [$this, 'page_init']);
    }

    public function add_plugin_page() {
        add_submenu_page(
            'edit.php?post_type=' . $this->adapter->get_post_type(),
            __('Event Filtering / Cleanup', 'kph-ical-importer'),
            __('Event Filtering', 'kph-ical-importer'),
            'manage_options',
            'kph-event-cleanup',
            [$this, 'create_admin_page']
        );
    }

    public function page_init() {
        register_setting(
            'kph_event_cleanup_group',
            'kph_event_cleanup_options',
            [$this, 'sanitize']
        );
    }

    public function create_admin_page() {
        $this->options = get_option('kph_event_cleanup_options');
        ?>
        <div class="wrap">
            <h1><?php _e('Event Title Filtering & Cleanup', 'kph-ical-importer'); ?></h1>
            <p><?php _e('Use these tools to automatically clean and standardize event titles during import and for existing events.', 'kph-ical-importer'); ?></p>

            <form method="post" action="options.php">
                <?php
                settings_fields('kph_event_cleanup_group');
                ?>

                <hr>
                <h2><?php _e('1. Blacklist', 'kph-ical-importer'); ?></h2>
                <p><?php _e('Words, characters, or phrases to completely remove from event titles. One item per line.', 'kph-ical-importer'); ?></p>
                <textarea name="kph_event_cleanup_options[blacklist]" rows="10" cols="50" class="large-text"><?php echo esc_textarea($this->options['blacklist'] ?? ''); ?></textarea>
                
                <hr>
                <h2><?php _e('2. Find & Replace', 'kph-ical-importer'); ?></h2>
                <p><?php _e('Replace specific words or characters. Use the format <code>find | replace</code> on each line. The process is case-insensitive. Rules are applied in order from top to bottom.', 'kph-ical-importer'); ?></p>
                <p><em><?php _e('Example: To replace "Saturday" with "Sat." but protect the phrase "Hollystone Saturday", you would use two lines in this order:', 'kph-ical-importer'); ?></em><br>
                <code>Hollystone Saturday | Hollystone Saturday</code><br>
                <code>Saturday | Sat.</code></p>
                <textarea name="kph_event_cleanup_options[replacements]" rows="10" cols="50" class="large-text"><?php echo esc_textarea($this->options['replacements'] ?? ''); ?></textarea>

                <hr>
                <h2><?php _e('3. Location Name Exclusions', 'kph-ical-importer'); ?></h2>
                <p><?php _e('By default, any text that exactly matches the event\'s location name will be removed from the title (e.g., "Techno Party at Seaview Resort" becomes "Techno Party"). Add location names here (one per line) to prevent this from happening for specific venues.', 'kph-ical-importer'); ?></p>
                <textarea name="kph_event_cleanup_options[exclusions]" rows="10" cols="50" class="large-text"><?php echo esc_textarea($this->options['exclusions'] ?? ''); ?></textarea>

                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    public function sanitize($input) {
        $new_input = [];
        $new_input['blacklist'] = isset($input['blacklist']) ? sanitize_textarea_field($input['blacklist']) : '';
        $new_input['replacements'] = isset($input['replacements']) ? sanitize_textarea_field($input['replacements']) : '';
        $new_input['exclusions'] = isset($input['exclusions']) ? sanitize_textarea_field($input['exclusions']) : '';
        return $new_input;
    }

    /**
     * Applies all defined cleanup rules to a given title.
     * This is the central sanitization function.
     * * @param string $title The original event title.
     * @param int|null $location_term_id The term ID of the event's location.
     * @param array $rules The array of cleanup rules from the options.
     * @return string The cleaned title.
     */
    public static function apply_cleanup_rules($title, $location_term_id, $rules) {
        $cleaned_title = $title;
        $adapter = new KPH_Calendar_Adapter(); // This is okay here because it's self-contained

        // 1. Apply blacklist
        if (!empty($rules['blacklist'])) {
            $blacklist = explode("\n", $rules['blacklist']);
            $blacklist = array_map('trim', $blacklist);
            $cleaned_title = str_ireplace($blacklist, '', $cleaned_title);
        }

        // 2. Apply find and replace
        if (!empty($rules['replacements'])) {
            $replacements = explode("\n", $rules['replacements']);
            foreach ($replacements as $replacement) {
                $parts = explode('|', $replacement);
                if (count($parts) === 2) {
                    $find = trim($parts[0]);
                    $replace = trim($parts[1]);
                    if (!empty($find)) {
                        $cleaned_title = str_ireplace($find, $replace, $cleaned_title);
                    }
                }
            }
        }
        
        // 3. Remove location name from title, unless excluded
        if ($location_term_id) {
            $location = get_term($location_term_id, $adapter->get_location_taxonomy());
            if ($location && !is_wp_error($location)) {
                $exclusions = isset($rules['exclusions']) ? explode("\n", $rules['exclusions']) : [];
                $exclusions = array_map('trim', array_map('strtolower', $exclusions));
                if (!in_array(strtolower($location->name), $exclusions)) {
                     $cleaned_title = str_ireplace($location->name, '', $cleaned_title);
                }
            }
        }
        
        // 4. Final cleanup: remove extra spaces, hyphens, etc. from start/end
        $cleaned_title = trim($cleaned_title);
        $cleaned_title = trim($cleaned_title, " \t\n\r\0\x0B-–|:");

        return $cleaned_title;
    }
}

