<?php

if (!defined('WPINC')) {
    die;
}

class KPH_Event_Header_Generator {

    private $adapter;
    private $options;
    private $log = [];
    const CLEANUP_PROGRESS_OPTION = 'kph_title_cleanup_progress';

    public function __construct($adapter) {
        $this->adapter = $adapter;
        add_action('admin_menu', [$this, 'add_plugin_page']);
        add_action('admin_init', [$this, 'page_init']);
        add_action('add_meta_boxes', [$this, 'add_generator_meta_box']);
    }

    public function add_plugin_page() {
        add_submenu_page(
            'edit.php?post_type=' . $this->adapter->get_post_type(),
            __('Event Flyer Generator', 'kph-ical-importer') . ' (v' . KPH_ICAL_IMPORTER_VERSION . ')',
            __('Flyer Generator', 'kph-ical-importer'),
            'manage_options',
            'kph-event-header-generator',
            [$this, 'create_admin_page']
        );
    }

    public function page_init() {
        // Register settings for this page
        register_setting(
            'kph_header_generator_group',
            'kph_header_generator_options',
            [$this, 'sanitize_settings']
        );

        // Handle form submissions for bulk actions
        $this->handle_bulk_generation();
        $this->handle_single_generation();
        $this->handle_bulk_title_cleanup();
    }
    
    // Renders the meta box on the event edit screen
    public function add_generator_meta_box() {
        add_meta_box(
            'kph_event_generator_metabox',
            __('Event Flyer Generator', 'kph-ical-importer'),
            [$this, 'render_meta_box_content'],
            $this->adapter->get_post_type(),
            'side',
            'low'
        );
    }

    // Displays the content of the meta box
    public function render_meta_box_content($post) {
        if (has_post_thumbnail($post->ID)) {
            echo '<p>' . __('A flyer has already been generated or manually assigned.', 'kph-ical-importer') . '</p>';
            return;
        }
        
        $generate_url = wp_nonce_url(
            admin_url('post.php?post=' . $post->ID . '&action=edit&kph_generate_single=' . $post->ID),
            'kph_generate_single_nonce',
            'kph_nonce'
        );

        echo '<a href="' . esc_url($generate_url) . '" class="button button-primary">' . __('Generate Flyer Image', 'kph-ical-importer') . '</a>';
        echo '<p class="description">' . __('Generates a new flyer using the default or location-specific template.', 'kph-ical-importer') . '</p>';
    }

    private function handle_single_generation() {
        if (isset($_GET['kph_generate_single']) && isset($_GET['kph_nonce'])) {
            if (!wp_verify_nonce($_GET['kph_nonce'], 'kph_generate_single_nonce')) {
                wp_die('Invalid nonce.');
            }

            $post_id = intval($_GET['kph_generate_single']);
            $this->generate_header_for_event($post_id, null); // location_term_id can be null here as it will be looked up
            
            // Redirect back to the edit page
            wp_safe_redirect(admin_url('post.php?post=' . $post_id . '&action=edit&message=10')); // message 10 is a custom success indicator
            exit;
        }

        // Add an admin notice on successful generation
        if (isset($_GET['message']) && $_GET['message'] == '10') {
             add_action('admin_notices', function() {
                echo '<div class="notice notice-success is-dismissible"><p>' . __('Flyer image generated successfully.', 'kph-ical-importer') . '</p></div>';
            });
        }
    }

    private function handle_bulk_generation() {
        if (isset($_POST['kph_bulk_generate_headers_nonce']) && wp_verify_nonce($_POST['kph_bulk_generate_headers_nonce'], 'kph_bulk_generate_headers')) {
            $batch_size = isset($_POST['batch_size']) ? intval($_POST['batch_size']) : 10;
            $events = $this->get_events_without_header($batch_size);

            $success_count = 0;
            $failure_count = 0;
            
            if (!empty($events)) {
                foreach ($events as $event_post) {
                    $result = $this->generate_header_for_event($event_post->ID, null); // location_term_id will be looked up inside
                    if ($result) {
                        $success_count++;
                    } else {
                        $failure_count++;
                    }
                }
            }
            
            // Store the log to display it
            set_transient('kph_generator_last_run_log', $this->log, 60);
            
            // Add a notice
            add_action('admin_notices', function() use ($success_count, $failure_count) {
                $message = sprintf(
                    __('Bulk process complete. Successfully generated %d header images. Failed to generate %d.', 'kph-ical-importer'),
                    $success_count,
                    $failure_count
                );
                echo '<div class="notice notice-info is-dismissible"><p>' . $message . '</p></div>';
            });
        }
    }
    
    private function handle_bulk_title_cleanup() {
        $nonce_action = 'kph_cleanup_nonce';
        $progress_option = self::CLEANUP_PROGRESS_OPTION;

        if (isset($_POST['kph_cleanup_reset']) && check_admin_referer($nonce_action)) {
            delete_option($progress_option);
            add_action('admin_notices', function() {
                echo '<div class="notice notice-success is-dismissible"><p>' . __('Cleanup progress has been reset.', 'kph-ical-importer') . '</p></div>';
            });
            return;
        }

        if (isset($_POST['kph_cleanup_confirm']) && check_admin_referer($nonce_action)) {
            $changes_to_apply = get_transient('kph_cleanup_preview');
            if ($changes_to_apply && is_array($changes_to_apply)) {
                $updated_count = 0;
                foreach ($changes_to_apply as $change) {
                    wp_update_post([
                        'ID' => $change['post_id'],
                        'post_title' => $change['new_title'],
                        'post_name' => sanitize_title($change['new_title'])
                    ]);
                    $updated_count++;
                }
                
                // Update progress after applying changes
                $progress = get_option($progress_option, 0);
                update_option($progress_option, $progress + count($changes_to_apply));

                delete_transient('kph_cleanup_preview');

                add_action('admin_notices', function() use ($updated_count) {
                    $message = sprintf(__('Successfully updated %d event titles.', 'kph-ical-importer'), $updated_count);
                    echo '<div class="notice notice-success is-dismissible"><p>' . $message . '</p></div>';
                });
            }
        }
        
        if (isset($_POST['kph_cleanup_start']) && check_admin_referer($nonce_action)) {
            $batch_size = isset($_POST['cleanup_batch_size']) ? intval($_POST['cleanup_batch_size']) : 50;
            $offset = get_option($progress_option, 0);

            $args = [
                'post_type' => $this->adapter->get_post_type(),
                'post_status' => 'publish',
                'posts_per_page' => $batch_size,
                'offset' => $offset,
                'orderby' => 'ID',
                'order' => 'ASC'
            ];
            $query = new WP_Query($args);
            $events_to_check = $query->posts;

            $changes_preview = [];
            $cleanup_options = get_option('kph_event_cleanup_options', []);

            foreach ($events_to_check as $event) {
                $location_terms = get_the_terms($event->ID, $this->adapter->get_location_taxonomy());
                $location_term_id = (!empty($location_terms) && !is_wp_error($location_terms)) ? $location_terms[0]->term_id : null;

                $cleaned_title = KPH_Event_Cleanup::apply_cleanup_rules($event->post_title, $location_term_id, $cleanup_options);

                if ($cleaned_title !== $event->post_title) {
                    $changes_preview[] = [
                        'post_id' => $event->ID,
                        'old_title' => $event->post_title,
                        'new_title' => $cleaned_title
                    ];
                }
            }
            
            // Store preview in a transient
            set_transient('kph_cleanup_preview', $changes_preview, 300);

            if (empty($events_to_check)) {
                 add_action('admin_notices', function() {
                    echo '<div class="notice notice-info is-dismissible"><p>' . __('All events have been checked. No more items to process. You can reset the counter to start over.', 'kph-ical-importer') . '</p></div>';
                });
            }
        }
    }


    public function create_admin_page() {
        $this->options = get_option('kph_header_generator_options');
        ?>
        <div class="wrap">
            <h1><?php _e('Event Flyer Generator', 'kph-ical-importer'); ?> (v<?php echo KPH_ICAL_IMPORTER_VERSION; ?>)</h1>
            <p><?php _e('Use this page to configure and run the automatic flyer image generator.', 'kph-ical-importer'); ?></p>

            <form method="post" action="options.php">
                <?php
                settings_fields('kph_header_generator_group');
                $this->render_settings_fields();
                submit_button(__('Save Generator Settings', 'kph-ical-importer'));
                ?>
            </form>
            
            <hr>

            <h2><?php _e('Bulk Generate Flyers for Existing Events', 'kph-ical-importer'); ?></h2>
            <p><?php _e('Generate flyers for events that are missing one. This runs in batches to prevent timeouts.', 'kph-ical-importer'); ?></p>
            <form method="post" action="">
                <input type="hidden" name="kph_bulk_generate_headers_nonce" value="<?php echo wp_create_nonce('kph_bulk_generate_headers'); ?>">
                <label for="batch_size"><?php _e('Batch Size:', 'kph-ical-importer'); ?></label>
                <input type="number" id="batch_size" name="batch_size" min="1" max="50" value="10">
                <?php submit_button(__('Generate Flyers for Batch', 'kph-ical-importer'), 'primary', 'submit_bulk_generate'); ?>
            </form>
            
            <?php $this->display_generation_log(); ?>
            
            <hr>

            <h2><?php _e('Bulk Clean Event Titles', 'kph-ical-importer'); ?></h2>
            <p><?php _e('Run the title cleanup rules on all existing events. This processes in batches and keeps track of its progress.', 'kph-ical-importer'); ?></p>
             <form method="post" action="">
                <?php wp_nonce_field('kph_cleanup_nonce'); ?>
                <label for="cleanup_batch_size"><?php _e('Batch Size:', 'kph-ical-importer'); ?></label>
                <input type="number" id="cleanup_batch_size" name="cleanup_batch_size" min="1" max="100" value="50">
                <?php submit_button(__('Start/Continue Cleanup (Dry Run)', 'kph-ical-importer'), 'secondary', 'kph_cleanup_start'); ?>
                <?php submit_button(__('Reset Counter', 'kph-ical-importer'), 'delete', 'kph_cleanup_reset'); ?>
            </form>

            <?php $this->display_cleanup_preview(); ?>
        </div>
        <?php
    }
    
    private function display_cleanup_preview() {
        $changes_preview = get_transient('kph_cleanup_preview');
        $total_events = wp_count_posts($this->adapter->get_post_type())->publish;
        $processed_count = get_option(self::CLEANUP_PROGRESS_OPTION, 0);

        echo '<p><strong>' . sprintf(__('Progress: %d / %d events checked.', 'kph-ical-importer'), $processed_count, $total_events) . '</strong></p>';

        if ($changes_preview && is_array($changes_preview) && !empty($changes_preview)) {
            echo '<h3>' . __('Confirm Title Changes', 'kph-ical-importer') . '</h3>';
            echo '<p>' . __('The following changes will be made. Click the button below to apply them.', 'kph-ical-importer') . '</p>';

            echo '<form method="post" action="">';
            wp_nonce_field('kph_cleanup_nonce');
            echo '<table class="wp-list-table widefat fixed striped">';
            echo '<thead><tr><th>' . __('Old Title', 'kph-ical-importer') . '</th><th>' . __('New Title', 'kph-ical-importer') . '</th></tr></thead>';
            echo '<tbody>';
            foreach ($changes_preview as $change) {
                echo '<tr>';
                echo '<td>' . esc_html($change['old_title']) . '</td>';
                echo '<td>' . esc_html($change['new_title']) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
            submit_button(__('Confirm & Apply Changes', 'kph-ical-importer'), 'primary', 'kph_cleanup_confirm');
            echo '</form>';
        } elseif (isset($_POST['kph_cleanup_start'])) {
             echo '<p>' . __('No titles needed changes in this batch.', 'kph-ical-importer') . '</p>';
        }
    }

    /**
     * The core image generation function.
     * @param int $post_id The ID of the event post.
     * @param int|null $location_term_id The term ID of the event's location.
     * @return bool True on success, false on failure.
     */
    public function generate_header_for_event($post_id, $location_term_id) {
        // Prevent re-generation if an image already exists
        if (has_post_thumbnail($post_id)) {
            $this->log[$post_id][] = "Skipped: Event already has a featured image.";
            return false;
        }

        // Get event details
        $event_post = get_post($post_id);
        $event_title = strtoupper($event_post->post_title);
        $start_time = get_post_meta($post_id, $this->adapter->get_meta_key('start_time'), true);
        $event_date_str = date('D, M j | g:i A', (int)$start_time);
        
        if (!$location_term_id) {
             $location_terms = get_the_terms($post_id, $this->adapter->get_location_taxonomy());
             $location_term_id = (!empty($location_terms) && !is_wp_error($location_terms)) ? $location_terms[0]->term_id : null;
        }
        
        $location_name = $location_term_id ? get_term($location_term_id)->name : 'Koh Phangan';

        // Determine which settings to use (location-specific or default)
        $opts = $this->options;
        $loc_opts = $location_term_id ? get_term_meta($location_term_id, 'kph_location_flyer_options', true) : [];

        $template_id = $loc_opts['template_id'] ?? ($opts['default_template_id'] ?? 0);
        $title_color_hex = $loc_opts['title_color'] ?? ($opts['default_title_color'] ?? '#FFFFFF');
        $date_color_hex = $loc_opts['date_color'] ?? ($opts['default_date_color'] ?? '#FFFFFF');
        $location_color_hex = $loc_opts['location_color'] ?? ($opts['default_location_color'] ?? '#FFFFFF');
        $logo_id = $loc_opts['logo_id'] ?? null;
        
        // --- Image Generation Logic ---
        $template_path = get_attached_file($template_id);
        if (!$template_path || !file_exists($template_path)) {
            $this->log[$post_id][] = "Failed: Template file not found.";
            return false;
        }

        $font_path = KPH_ICAL_IMPORTER_PATH . 'assets/font.ttf';
        if (!file_exists($font_path)) {
            $this->log[$post_id][] = "Failed: Font file not found at " . $font_path;
            return false;
        }
        
        // Create image resource from template
        $image = imagecreatefromstring(file_get_contents($template_path));
        if(!$image){
             $this->log[$post_id][] = "Failed: Could not create image from template.";
            return false;
        }
        imagealphablending($image, true);
        imagesavealpha($image, true);

        // Allocate colors
        $title_color = $this->hex_to_rgb($title_color_hex, $image);
        $date_color = $this->hex_to_rgb($date_color_hex, $image);
        $location_color = $this->hex_to_rgb($location_color_hex, $image);
        $shadow_color = imagecolorallocatealpha($image, 0, 0, 0, 60);

        $image_width = imagesx($image);
        $image_height = imagesy($image);

        // Logo rendering
        $current_y = 80;
        if($logo_id){
             $logo_path = get_attached_file($logo_id);
             if($logo_path && file_exists($logo_path)){
                $logo_img = imagecreatefromstring(file_get_contents($logo_path));
                if($logo_img){
                    $logo_w = imagesx($logo_img);
                    $logo_h = imagesy($logo_img);
                    
                    // Resize logic
                    $max_w = 860; $max_h = 230;
                    $ratio = min($max_w / $logo_w, $max_h / $logo_h);
                    $new_w = $logo_w * $ratio;
                    $new_h = $logo_h * $ratio;

                    $logo_resized = imagecreatetruecolor($new_w, $new_h);
                    imagealphablending($logo_resized, false);
                    imagesavealpha($logo_resized, true);
                    $transparent = imagecolorallocatealpha($logo_resized, 255, 255, 255, 127);
                    imagefilledrectangle($logo_resized, 0, 0, $new_w, $new_h, $transparent);
                    imagecopyresampled($logo_resized, $logo_img, 0, 0, 0, 0, $new_w, $new_h, $logo_w, $logo_h);

                    $logo_x = ($image_width - $new_w) / 2;
                    imagecopy($image, $logo_resized, $logo_x, $current_y, 0, 0, $new_w, $new_h);
                    
                    $current_y += $new_h + 80; // Add padding after logo
                    imagedestroy($logo_img);
                    imagedestroy($logo_resized);
                }
             }
        } else {
            $current_y += 230 + 80; // Reserve space for logo even if not present
        }

        // Text rendering
        $margin = 100;
        $text_width = $image_width - ($margin * 2);
        
        // Title
        $title_font_size = strlen($event_title) > 40 ? 48 : 60;
        $current_y = $this->add_text_with_wrap($image, $title_font_size, $font_path, $event_title, $title_color, $shadow_color, $current_y, $text_width, 1.2, true);
        $current_y += 30; // Padding after title
        
        // Date
        $current_y = $this->add_text_with_wrap($image, 45, $font_path, $event_date_str, $date_color, $shadow_color, $current_y, $text_width, 1.5, true);
        $current_y += 20; // Padding after date
        
        // Location
        $this->add_text_with_wrap($image, 45, $font_path, $location_name, $location_color, $shadow_color, $current_y, $text_width, 1.5);

        // --- Save image and attach to post ---
        $upload_dir = wp_upload_dir();
        $filename = sanitize_file_name($event_title . '-' . $post_id . '.jpg');
        $filepath = $upload_dir['path'] . '/' . $filename;
        imagejpeg($image, $filepath, 90);
        imagedestroy($image);

        $attachment = [
            'guid'           => $upload_dir['url'] . '/' . $filename,
            'post_mime_type' => 'image/jpeg',
            'post_title'     => $event_title,
            'post_content'   => '',
            'post_status'    => 'inherit'
        ];
        $attach_id = wp_insert_attachment($attachment, $filepath, $post_id);
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        $attach_data = wp_generate_attachment_metadata($attach_id, $filepath);
        wp_update_attachment_metadata($attach_id, $attach_data);
        set_post_thumbnail($post_id, $attach_id);
        
        $this->log[$post_id][] = "Success: Flyer generated and attached.";
        return true;
    }
    
    private function hex_to_rgb($hex, $image) {
        $hex = str_replace('#', '', $hex);
        if (strlen($hex) == 3) {
            $r = hexdec(substr($hex, 0, 1) . substr($hex, 0, 1));
            $g = hexdec(substr($hex, 1, 1) . substr($hex, 1, 1));
            $b = hexdec(substr($hex, 2, 1) . substr($hex, 2, 1));
        } else {
            $r = hexdec(substr($hex, 0, 2));
            $g = hexdec(substr($hex, 2, 4));
            $b = hexdec(substr($hex, 4, 6));
        }
        return imagecolorallocate($image, $r, $g, $b);
    }
    
    // Renders the settings fields for the generator
    private function render_settings_fields() {
         wp_enqueue_media();
        ?>
         <table class="form-table">
            <tr valign="top">
                <th scope="row"><?php _e('Default Flyer Template', 'kph-ical-importer'); ?></th>
                <td>
                    <?php $image_id = $this->options['default_template_id'] ?? 0; ?>
                    <div class="image-uploader-wrapper">
                        <img src="<?php echo esc_url(wp_get_attachment_image_url($image_id, 'medium')); ?>" style="max-width:200px; height:auto; display: <?php echo $image_id ? 'block' : 'none'; ?>;">
                        <button type="button" class="button kph-upload-image-button"><?php _e('Upload/Select Image', 'kph-ical-importer'); ?></button>
                        <button type="button" class="button kph-remove-image-button" style="display: <?php echo $image_id ? 'inline-block' : 'none'; ?>;"><?php _e('Remove Image', 'kph-ical-importer'); ?></button>
                        <input type="hidden" name="kph_header_generator_options[default_template_id]" value="<?php echo esc_attr($image_id); ?>">
                    </div>
                     <p class="description"><?php _e('Recommended size: 1080x1350px.', 'kph-ical-importer'); ?></p>
                </td>
            </tr>
            <tr valign="top">
                <th scope="row"><?php _e('Default Title Color', 'kph-ical-importer'); ?></th>
                <td><input type="color" name="kph_header_generator_options[default_title_color]" value="<?php echo esc_attr($this->options['default_title_color'] ?? '#FFFFFF'); ?>" /></td>
            </tr>
            <tr valign="top">
                <th scope="row"><?php _e('Default Date Color', 'kph-ical-importer'); ?></th>
                <td><input type="color" name="kph_header_generator_options[default_date_color]" value="<?php echo esc_attr($this->options['default_date_color'] ?? '#FFFFFF'); ?>" /></td>
            </tr>
            <tr valign="top">
                <th scope="row"><?php _e('Default Location Color', 'kph-ical-importer'); ?></th>
                <td><input type="color" name="kph_header_generator_options[default_location_color]" value="<?php echo esc_attr($this->options['default_location_color'] ?? '#FFFFFF'); ?>" /></td>
            </tr>
        </table>

        <script type="text/javascript">
        jQuery(document).ready(function($){
            $('body').on('click', '.kph-upload-image-button', function(e){
                e.preventDefault();
                var button = $(this);
                var wrapper = button.closest('.image-uploader-wrapper');
                var frame = wp.media({
                    title: 'Select Image',
                    button: { text: 'Use this image' },
                    multiple: false
                });
                frame.on('select', function(){
                    var attachment = frame.state().get('selection').first().toJSON();
                    wrapper.find('input[type=hidden]').val(attachment.id);
                    wrapper.find('img').attr('src', attachment.sizes.medium.url).show();
                    wrapper.find('.kph-remove-image-button').show();
                });
                frame.open();
            });

            $('body').on('click', '.kph-remove-image-button', function(e){
                e.preventDefault();
                var button = $(this);
                var wrapper = button.closest('.image-uploader-wrapper');
                wrapper.find('input[type=hidden]').val('0');
                wrapper.find('img').hide();
                button.hide();
            });
        });
        </script>
        <?php
    }
    
    // Gets a list of events without a featured image.
    private function get_events_without_header($count = 10) {
        $args = [
            'post_type'      => $this->adapter->get_post_type(),
            'posts_per_page' => $count,
            'post_status'    => 'publish',
            'meta_query'     => [
                [
                    'key'     => '_thumbnail_id',
                    'compare' => 'NOT EXISTS'
                ]
            ]
        ];
        $query = new WP_Query($args);
        return $query->posts;
    }
    
    // A helper function to add text that wraps automatically.
    private function add_text_with_wrap($image, $font_size, $font_path, $text, $color, $shadow_color, $y, $max_width, $line_height_multiplier = 1.5, $is_bold = false) {
        $words = explode(' ', $text);
        $lines = [];
        $current_line = '';
        
        foreach ($words as $word) {
            $test_box = imagettfbbox($font_size, 0, $font_path, $current_line . ' ' . $word);
            if ($test_box[2] > $max_width) {
                $lines[] = $current_line;
                $current_line = $word;
            } else {
                $current_line .= (empty($current_line) ? '' : ' ') . $word;
            }
        }
        $lines[] = $current_line;
        
        foreach ($lines as $line) {
            $bbox = imagettfbbox($font_size, 0, $font_path, $line);
            $x = (imagesx($image) - $bbox[2]) / 2;
            
            // Shadow
            imagettftext($image, $font_size, 0, $x + 2, $y + 2, $shadow_color, $font_path, $line);
            if($is_bold) imagettftext($image, $font_size, 0, $x + 3, $y + 2, $shadow_color, $font_path, $line);
            
            // Text
            imagettftext($image, $font_size, 0, $x, $y, $color, $font_path, $line);
             if($is_bold) imagettftext($image, $font_size, 0, $x + 1, $y, $color, $font_path, $line);
            
            $y += $font_size * $line_height_multiplier;
        }

        return $y;
    }

    private function display_generation_log() {
        $log_data = get_transient('kph_generator_last_run_log');
        if (!$log_data) return;

        echo '<h3>' . __('Last Run Log', 'kph-ical-importer') . '</h3>';
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr><th style="width: 40%;">' . __('Event Processed', 'kph-ical-importer') . '</th><th>' . __('Status', 'kph-ical-importer') . '</th></tr></thead>';
        echo '<tbody>';
        foreach ($log_data as $post_id => $messages) {
            $post_title = get_the_title($post_id);
            foreach($messages as $message){
                echo '<tr>';
                echo '<td>' . esc_html($post_title) . ' (ID: ' . $post_id . ')</td>';
                echo '<td>' . esc_html($message) . '</td>';
                echo '</tr>';
            }
        }
        echo '</tbody></table>';
        delete_transient('kph_generator_last_run_log');
    }

    public function sanitize_settings($input) {
        $new_input = [];
        $new_input['default_template_id'] = isset($input['default_template_id']) ? absint($input['default_template_id']) : 0;
        $new_input['default_title_color'] = isset($input['default_title_color']) ? sanitize_hex_color($input['default_title_color']) : '#FFFFFF';
        $new_input['default_date_color'] = isset($input['default_date_color']) ? sanitize_hex_color($input['default_date_color']) : '#FFFFFF';
        $new_input['default_location_color'] = isset($input['default_location_color']) ? sanitize_hex_color($input['default_location_color']) : '#FFFFFF';
        return $new_input;
    }
}

