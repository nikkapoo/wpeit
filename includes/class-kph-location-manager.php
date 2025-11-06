<?php

if (!defined('WPINC')) {
    die;
}

class KPH_Location_Manager {

    private $adapter;

    public function __construct($adapter) {
        $this->adapter = $adapter;
        add_action('admin_menu', [$this, 'add_plugin_page']);
        add_action('admin_init', [$this, 'handle_form_submissions']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
    }
    
    public function enqueue_admin_scripts($hook) {
        $screen = get_current_screen();
        if (!$screen || (strpos($screen->id, 'kph-location-manager') === false)) {
            return;
        }
        
        // Custom script to initialize the select-all checkbox
        add_action('admin_footer', function() {
            ?>
            <script>
                jQuery(document).ready(function($){
                    $('#kph-select-all-events').on('click', function(event) {
                        var checkboxes = $(this).closest('table').find('tbody input[type="checkbox"]');
                        checkboxes.prop('checked', $(this).prop('checked'));
                    });
                });
            </script>
            <?php
        });
    }

    public function add_plugin_page() {
        // Add as a submenu under "Events"
        add_submenu_page(
            'edit.php?post_type=' . $this->adapter->get_post_type(),
            'Location Manager',
            'Location Manager',
            'manage_options',
            'kph-location-manager',
            [$this, 'create_admin_page']
        );
    }

    public function create_admin_page() {
        ?>
        <div class="wrap">
            <h1>Event Location Manager <span style="font-size: 12px; color: #666;">v<?php echo KPH_ICAL_IMPORTER_VERSION; ?></span></h1>
            <p>Use this page to manage location aliases and to bulk merge or delete locations.</p>

            <hr>

            <div id="col-container" class="wp-clearfix">
                <div id="col-left" style="width: 48%; float: left; margin-right: 4%;">
                    <div class="col-wrap">
                        <h2>Bulk Actions</h2>
                        <p>Select locations to delete, or select multiple locations to merge into a primary location (Keep?). All actions will be processed when you click "Update Selections".</p>
                        <form method="post" action="">
                            <?php wp_nonce_field('kph_bulk_actions_nonce', 'kph_bulk_nonce'); ?>
                            <input type="hidden" name="action" value="bulk_actions">
                            <div style="max-height: 400px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; margin-bottom: 10px;">
                                <?php $this->display_bulk_actions_table(); ?>
                            </div>
                            <?php submit_button('Update Selections'); ?>
                        </form>
                    </div>

                    <div class="col-wrap" style="margin-top: 20px;">
                         <h2>Events without a Location</h2>
                        <p>Use this section to assign a location to events that were imported without one, or to delete them.</p>
                        <form method="post" action="">
                            <?php wp_nonce_field('kph_unassigned_events_nonce', 'kph_unassigned_nonce'); ?>
                            <input type="hidden" name="action" value="manage_unassigned_events">
                            <?php $this->display_events_without_location_table(); ?>
                        </form>
                    </div>
                </div>
                <div id="col-right" style="width: 48%; float: right;">
                    <div class="col-wrap">
                        <h2>Manage Location Aliases</h2>
                        <p>Manage which imported location names (aliases) map to which EventON location. Add multiple source names separated by a comma.</p>
                        <form method="post" action="">
                            <?php wp_nonce_field('kph_save_location_aliases_nonce', 'kph_aliases_nonce'); ?>
                            <input type="hidden" name="action" value="save_aliases">
                            <table class="wp-list-table widefat fixed striped">
                                <thead>
                                    <tr>
                                        <th style="width: 40%;">Event Location</th>
                                        <th>Source Names / Aliases (from iCal)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $this->display_alias_mappings(); ?>
                                </tbody>
                            </table>
                            <?php submit_button('Save Location Mappings'); ?>
                        </form>
                    </div>
                </div>
            </div>

        </div>
        <?php
    }

    public function handle_form_submissions() {
        if (!current_user_can('manage_options') || !isset($_POST['action'])) {
            return;
        }

        if ($_POST['action'] === 'save_aliases' && isset($_POST['kph_aliases_nonce']) && wp_verify_nonce(sanitize_key($_POST['kph_aliases_nonce']), 'kph_save_location_aliases_nonce')) {
            $this->save_location_aliases();
        }

        if ($_POST['action'] === 'bulk_actions' && isset($_POST['kph_bulk_nonce']) && wp_verify_nonce(sanitize_key($_POST['kph_bulk_nonce']), 'kph_bulk_actions_nonce')) {
            $this->handle_merges();
            $this->handle_deletions();
        }

        if ($_POST['action'] === 'manage_unassigned_events' && isset($_POST['kph_unassigned_nonce']) && wp_verify_nonce(sanitize_key($_POST['kph_unassigned_nonce']), 'kph_unassigned_events_nonce')) {
            if (isset($_POST['assign_location_submit'])) {
                $this->handle_assign_location();
            } elseif (isset($_POST['delete_events_submit'])) {
                $this->handle_delete_events();
            }
        }
    }

    private function get_all_locations() {
        return get_terms([
            'taxonomy'   => $this->adapter->get_location_taxonomy(),
            'hide_empty' => false,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ]);
    }

    private function display_bulk_actions_table() {
        $locations = $this->get_all_locations();
        if (empty($locations)) {
            echo '<p>No locations available.</p>';
            return;
        }

        echo '<table>';
        echo '<tr><th style="text-align: left;">Delete?</th><th style="text-align: left;">Merge?</th><th style="text-align: left;">Keep?</th><th style="text-align: left;">Location Name (Event Count)</th></tr>';
        foreach ($locations as $location) {
            echo '<tr>';
            echo '<td><input type="checkbox" name="locations_to_delete[]" value="' . esc_attr($location->term_id) . '"></td>';
            echo '<td><input type="checkbox" name="locations_to_merge[]" value="' . esc_attr($location->term_id) . '"></td>';
            echo '<td><input type="radio" name="target_location" value="' . esc_attr($location->term_id) . '"></td>';
            echo '<td>' . esc_html($location->name) . ' (' . esc_html($location->count) . ')</td>';
            echo '</tr>';
        }
        echo '</table>';
    }

    private function display_alias_mappings() {
        $locations = $this->get_all_locations();

        if (empty($locations)) {
            echo '<tr><td colspan="2">No locations found. Import some events first to create locations.</td></tr>';
            return;
        }

        foreach ($locations as $location) {
            $aliases = get_term_meta($location->term_id, '_kph_location_aliases', true);
            $aliases_str = is_array($aliases) ? implode(', ', $aliases) : '';
            ?>
            <tr>
                <td>
                    <strong><?php echo esc_html($location->name); ?></strong>
                    <div class="row-actions">
                        <span class="edit">
                            <a href="<?php echo get_edit_term_link($location->term_id, $this->adapter->get_location_taxonomy()); ?>">Edit</a>
                        </span>
                    </div>
                </td>
                <td>
                    <input type="text" name="location_aliases[<?php echo esc_attr($location->term_id); ?>]" value="<?php echo esc_attr($aliases_str); ?>" class="large-text">
                </td>
            </tr>
            <?php
        }
    }

    private function save_location_aliases() {
        if (isset($_POST['location_aliases']) && is_array($_POST['location_aliases'])) {
            foreach ($_POST['location_aliases'] as $term_id => $aliases_str) {
                $term_id = intval($term_id);
                $aliases = array_filter(array_map('trim', array_map('strtolower', explode(',', sanitize_text_field($aliases_str)))));
                $aliases = array_unique($aliases);
                update_term_meta($term_id, '_kph_location_aliases', $aliases);
            }
            add_action('admin_notices', function() {
                echo '<div class="notice notice-success is-dismissible"><p>Location mappings have been saved successfully.</p></div>';
            });
        }
    }

    private function handle_merges() {
        if (!isset($_POST['locations_to_merge']) || !is_array($_POST['locations_to_merge']) || count($_POST['locations_to_merge']) < 2) {
            return;
        }

        if (!isset($_POST['target_location'])) {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-error is-dismissible"><p>Merge failed: Please select a primary location (Keep?) to merge into.</p></div>';
            });
            return;
        }

        $term_ids_to_merge = array_map('intval', $_POST['locations_to_merge']);
        $target_term_id = intval($_POST['target_location']);

        if (!in_array($target_term_id, $term_ids_to_merge)) {
             add_action('admin_notices', function() {
                echo '<div class="notice notice-error is-dismissible"><p>Merge failed: The primary (Keep?) location must be one of the selected (Merge?) locations.</p></div>';
            });
            return;
        }

        $all_aliases = [];
        foreach ($term_ids_to_merge as $term_id) {
            $term = get_term($term_id, $this->adapter->get_location_taxonomy());
            if ($term && !is_wp_error($term)) {
                $all_aliases[] = strtolower(trim($term->name));
                $all_aliases[] = strtolower(trim($term->slug));
                $existing_aliases = get_term_meta($term_id, '_kph_location_aliases', true);
                if (is_array($existing_aliases)) {
                    $all_aliases = array_merge($all_aliases, $existing_aliases);
                }
            }
        }
        $unique_aliases = array_unique(array_filter($all_aliases));
        update_term_meta($target_term_id, '_kph_location_aliases', $unique_aliases);

        $ids_to_delete = array_diff($term_ids_to_merge, [$target_term_id]);
        
        foreach ($ids_to_delete as $term_id_to_delete) {
            $post_ids = get_posts([
                'post_type' => $this->adapter->get_post_type(),
                'posts_per_page' => -1,
                'fields' => 'ids',
                'tax_query' => [['taxonomy' => $this->adapter->get_location_taxonomy(), 'field' => 'term_id', 'terms' => $term_id_to_delete]],
            ]);

            foreach ($post_ids as $post_id) {
                wp_set_object_terms($post_id, $target_term_id, $this->adapter->get_location_taxonomy(), false);
            }
            
            wp_delete_term($term_id_to_delete, $this->adapter->get_location_taxonomy());
        }

        wp_update_term_count_now([$target_term_id], $this->adapter->get_location_taxonomy());

        add_action('admin_notices', function() use ($ids_to_delete, $target_term_id) {
            $count = count($ids_to_delete);
            $target_term = get_term($target_term_id);
            $message = sprintf('Successfully merged %d location(s) into "%s". All associated aliases have been combined.', $count, esc_html($target_term->name));
            echo '<div class="notice notice-success is-dismissible"><p>' . $message . '</p></div>';
        });
    }

    private function handle_deletions() {
        if (!isset($_POST['locations_to_delete']) || !is_array($_POST['locations_to_delete'])) {
            return;
        }

        $term_ids_to_delete = array_map('intval', $_POST['locations_to_delete']);
        $deleted_count = 0;

        foreach ($term_ids_to_delete as $term_id) {
            $result = wp_delete_term($term_id, $this->adapter->get_location_taxonomy());
            if ($result) {
                $deleted_count++;
            }
        }

        if ($deleted_count > 0) {
            add_action('admin_notices', function() use ($deleted_count) {
                echo '<div class="notice notice-success is-dismissible"><p>Successfully deleted ' . esc_html($deleted_count) . ' location(s).</p></div>';
            });
        }
    }

    private function display_events_without_location_table() {
        $events_query = new WP_Query([
            'post_type' => $this->adapter->get_post_type(),
            'posts_per_page' => -1,
            'tax_query' => [
                [
                    'taxonomy' => $this->adapter->get_location_taxonomy(),
                    'operator' => 'NOT EXISTS',
                ],
            ],
        ]);

        if (!$events_query->have_posts()) {
            echo '<p>No events without a location were found.</p>';
            return;
        }

        $locations = $this->get_all_locations();
        ?>
        <div style="max-height: 400px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; margin-bottom: 10px;">
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width: 5%;"><input type="checkbox" id="kph-select-all-events"></th>
                        <th>Event Title</th>
                        <th>Event Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($events_query->have_posts()) : $events_query->the_post(); ?>
                        <tr>
                            <td><input type="checkbox" name="events_to_manage[]" value="<?php echo get_the_ID(); ?>"></td>
                            <td><a href="<?php echo get_edit_post_link(); ?>"><?php the_title(); ?></a></td>
                            <td><?php echo date('Y-m-d', (int)get_post_meta(get_the_ID(), $this->adapter->config['meta_keys']['start_date'], true)); ?></td>
                        </tr>
                    <?php endwhile; wp_reset_postdata(); ?>
                </tbody>
            </table>
        </div>

        <div class="actions" style="margin-top: 10px;">
            <label for="target_location_for_assignment">Assign to Location:</label>
            <select name="target_location_for_assignment" id="target_location_for_assignment">
                <option value="">-- Select a Location --</option>
                <?php foreach ($locations as $location) : ?>
                    <option value="<?php echo esc_attr($location->term_id); ?>"><?php echo esc_html($location->name); ?></option>
                <?php endforeach; ?>
            </select>
            <input type="submit" name="assign_location_submit" class="button button-primary" value="Assign Location">
            <input type="submit" name="delete_events_submit" class="button button-danger" value="Delete Selected Events" onclick="return confirm('Are you sure you want to permanently delete the selected events? This cannot be undone.');">
        </div>
        <?php
    }

    private function handle_assign_location() {
        if (empty($_POST['events_to_manage']) || !is_array($_POST['events_to_manage'])) {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-warning is-dismissible"><p>Please select at least one event to assign.</p></div>';
            });
            return;
        }

        if (empty($_POST['target_location_for_assignment'])) {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-warning is-dismissible"><p>Please select a location to assign the events to.</p></div>';
            });
            return;
        }

        $event_ids = array_map('intval', $_POST['events_to_manage']);
        $target_term_id = intval($_POST['target_location_for_assignment']);
        $assigned_count = 0;

        foreach ($event_ids as $event_id) {
            wp_set_object_terms($event_id, $target_term_id, $this->adapter->get_location_taxonomy(), false);
            $assigned_count++;
        }

        if ($assigned_count > 0) {
            add_action('admin_notices', function() use ($assigned_count) {
                echo '<div class="notice notice-success is-dismissible"><p>Successfully assigned ' . esc_html($assigned_count) . ' event(s) to a new location.</p></div>';
            });
        }
    }

    private function handle_delete_events() {
        if (empty($_POST['events_to_manage']) || !is_array($_POST['events_to_manage'])) {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-warning is-dismissible"><p>Please select at least one event to delete.</p></div>';
            });
            return;
        }

        $event_ids = array_map('intval', $_POST['events_to_manage']);
        $deleted_count = 0;

        foreach ($event_ids as $event_id) {
            // Use wp_delete_post to permanently delete the event
            $result = wp_delete_post($event_id, true); // true = force delete, bypass trash
            if ($result) {
                $deleted_count++;
            }
        }

        if ($deleted_count > 0) {
            add_action('admin_notices', function() use ($deleted_count) {
                echo '<div class="notice notice-success is-dismissible"><p>Successfully deleted ' . esc_html($deleted_count) . ' event(s).</p></div>';
            });
        }
    }
}
