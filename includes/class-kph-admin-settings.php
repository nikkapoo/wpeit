<?php

if (!defined('WPINC')) {
    die;
}

class KPH_Admin_Settings {

    private $adapter;

    public function __construct($adapter) {
        $this->adapter = $adapter;
        add_action('admin_menu', [$this, 'add_plugin_page']);
        add_action('admin_init', [$this, 'page_init']);
    }

    public function add_plugin_page() {
       add_menu_page(
    'Event Image Bot',                          // Page title
    'Event Image Bot',                          // Menu title (sidebar icon)
    'manage_options',
    'kph-importer',                  // Parent slug
    [$this, 'create_admin_page'],                     // Callback
    'dashicons-images-alt2',                    // Icon (event-y)
    60                                          // Sidebar position (after Posts, before Media)
);}

    public function create_admin_page() {
        $options = get_option('kph_importer_options');
        ?>
        <div class="wrap">
            <h1><?php _e('Import Settings', 'kph-importer'); ?> (v1.0)</h1>
            
            <?php
            $active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'settings';
            if (isset($_GET['message']) && $_GET['message'] == 'import-success') {
                $summary = get_transient('kph_import_summary');
                if($summary){
                     echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($summary) . '</p></div>';
                     delete_transient('kph_import_summary');
                }
            }
            ?>

            <h2 class="nav-tab-wrapper">
                <a href="?page=kph-ical-importer-admin&tab=settings" class="nav-tab <?php echo $active_tab == 'settings' ? 'nav-tab-active' : ''; ?>"><?php _e('Settings', 'kph-ical-importer'); ?></a>
                <a href="?page=kph-ical-importer-admin&tab=logs" class="nav-tab <?php echo $active_tab == 'logs' ? 'nav-tab-active' : ''; ?>"><?php _e('Logs', 'kph-ical-importer'); ?></a>
            </h2>

            <?php if ($active_tab == 'settings') : ?>
                <form method="post" action="options.php">
                    <?php
                    settings_fields('kph_option_group');
                    do_settings_sections('kph-importer-admin-section');
                    submit_button();
                    ?>
                </form>
                <hr>
                <div class="kph-manual-import">
                    <h2><?php _e('Manual Import', 'event-image-bot'); ?></h2>
                    <p><?php _e('Test the import process manually. This will fetch new events from your iCal URLs.', 'kph-ical-importer'); ?></p>
                    <form method="post" action="">
                        <input type="hidden" name="kph_manual_import_nonce" value="<?php echo wp_create_nonce('kph_manual_import'); ?>" />
                        <?php submit_button(__('Import Now', 'event-image-bot'), 'secondary', 'kph_manual_import_submit'); ?>
                    </form>
                </div>

            <?php elseif ($active_tab == 'logs') : ?>
                <div class="kph-logs-wrap">
                    <h2><?php _e('Automatic Import Log', 'event-image-bot'); ?></h2>
                    <p><?php _e('Showing the summary of the last 10 scheduled imports (runs twice daily).', 'kph-ical-importer'); ?></p>
                    <?php $this->display_cron_logs(); ?>

                    <h2><?php _e('Detailed Log of Last Import', 'event-image-bot'); ?></h2>
                    <p><?php _e('This log shows a detailed breakdown of the most recent import run (manual or automatic).', 'kph-ical-importer'); ?></p>
                    <?php $this->display_detailed_log(); ?>

                    <h2><?php _e('Raw iCal Data', 'event-image-bot'); ?></h2>
                    <p><?php _e('This is the raw .ics data from the last successful fetch. For debugging purposes.', 'kph-ical-importer'); ?></p>
                    <textarea readonly style="width: 100%; height: 300px; background: #f9f9f9;"><?php echo esc_textarea(get_option('kph_importer_last_raw_ical', 'No data fetched yet.')); ?></textarea>
                </div>
            <?php endif; ?>

        </div>
        <?php
    }

    public function page_init() {
        // Handle the manual import form submission
        $this->handle_manual_import();

        register_setting(
            'kph_option_group',
            'kph_importer_options',
            [$this, 'sanitize']
        );

        add_settings_section(
            'kph_setting_section_id',
            __('iCal Feed Settings', 'event-image-bot'),
            null,
            'kph-importer-admin-section'
        );

        add_settings_field(
            'ical_urls',
            __('iCal Feed URLs (one per line)', 'event-image-bot'),
            [$this, 'ical_urls_callback'],
            'kph-importer-admin-section',
            'kph_setting_section_id'
        );
    }
    
    private function handle_manual_import() {
        if (isset($_POST['kph_manual_import_nonce']) && wp_verify_nonce($_POST['kph_manual_import_nonce'], 'kph_manual_import')) {
            
            if (class_exists('KPH_Importer') && $this->adapter) {
                $importer = new KPH_Importer($this->adapter);
                $result = $importer->run_import(false); // false = not a cron run
                $summary = $result['summary'];
                
                // Store the summary in a transient to show after redirect
                set_transient('kph_import_summary', $summary, 30);
            }

            // Redirect to the same page with a success message
            wp_redirect(add_query_arg(['page' => 'kph-ical-importer-admin', 'tab' => 'logs', 'message' => 'import-success'], admin_url('options-general.php')));
            exit;
        }
    }

    public function sanitize($input) {
        $new_input = [];
        if (isset($input['ical_urls'])) {
            $urls = explode("\n", $input['ical_urls']);
            $sanitized_urls = [];
            foreach ($urls as $url) {
                if (!empty(trim($url))) {
                    $sanitized_urls[] = esc_url_raw(trim($url));
                }
            }
            $new_input['ical_urls'] = implode("\n", $sanitized_urls);
        }
        return $new_input;
    }

    public function ical_urls_callback() {
        $options = get_option('kph_importer_options');
        printf(
            '<textarea id="ical_urls" name="kph_importer_options[ical_urls]" rows="10" cols="80">%s</textarea>',
            isset($options['ical_urls']) ? esc_attr($options['ical_urls']) : ''
        );
    }

    private function display_cron_logs() {
        $logs = get_option('kph_importer_cron_logs', []);
        if (empty($logs)) {
            echo '<p>No automatic imports have run yet.</p>';
            return;
        }
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr><th style="width: 180px;">Timestamp</th><th>Result</th></tr></thead>';
        echo '<tbody>';
        foreach ($logs as $log) {
            echo '<tr>';
            echo '<td>' . esc_html(get_date_from_gmt(date('Y-m-d H:i:s', $log['timestamp']), 'Y-m-d H:i:s')) . '</td>';
            echo '<td>' . esc_html($log['summary']) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    private function display_detailed_log() {
        $logs = get_option('kph_importer_cron_logs', []);
        if (empty($logs)) {
            echo '<p>No import has run yet.</p>';
            return;
        }
        $latest_log = $logs[0]['log']; // Get the full log from the most recent run
        
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr><th>Event Title</th><th>Status</th></tr></thead>';
        echo '<tbody>';
        foreach ($latest_log as $item) {
            echo '<tr>';
            echo '<td>' . esc_html($item['title']) . '</td>';
            echo '<td>' . esc_html($item['status']) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }
}