<?php

if (!defined('WPINC')) {
    die;
}

use ICal\ICal;

class KPH_Importer {

    private $adapter;
    private $log = [];

    public function __construct($adapter) {
        $this->adapter = $adapter;
    }
    
    /**
     * The main import function.
     * @param bool $is_cron Whether this is an automatic cron run.
     */
    public function run_import($is_cron = false) {
        $options = get_option('kph_importer_options');
        $this->log = []; // Reset log for this run

        if (empty($options['ical_urls'])) {
            $this->log_summary('No iCal URLs provided in settings.');
            if ($is_cron) $this->save_cron_log();
            return ['log' => $this->log, 'summary' => $this->get_summary_from_log()];
        }

        $urls = explode("\n", $options['ical_urls']);
        
        foreach ($urls as $url) {
            $url = trim($url);
            if (empty($url)) continue;

            $ical_content = $this->fetch_ical_content($url);

            if ($ical_content === false) {
                 $this->log[] = ['title' => 'Fetch Error', 'status' => 'Failed to fetch content from URL: ' . esc_url($url)];
                continue;
            }
            
            update_option('kph_importer_last_raw_ical', esc_textarea($ical_content));

            try {
                // The library can handle different line endings, the warning is non-critical.
                ini_set('auto_detect_line_endings', true);

                $ical = new ICal(false, ['skipRecurrence' => true]);
                $ical->initString($ical_content);
                
                if ($ical->hasEvents()) {
                    $this->process_events($ical->events());
                }

            } catch (\Exception $e) {
                $this->log[] = ['title' => 'Parse Error', 'status' => 'Error parsing iCal feed: ' . $e->getMessage()];
                continue;
            }
        }
        
        $summary = $this->get_summary_from_log();
        $this->log_summary($summary);

        // Save log regardless of run type, so manual log is visible
        $this->save_cron_log();

        return ['log' => $this->log, 'summary' => $summary];
    }
    
    private function process_events($events) {
        // Sort all events by start date, soonest first
        usort($events, function($a, $b) {
            $a_time = $this->get_event_start_time($a);
            $b_time = $this->get_event_start_time($b);
            return $a_time <=> $b_time;
        });

        // Filter out past events
        $future_events = array_filter($events, function($event) {
            $start_time = $this->get_event_start_time($event);
            if (!$start_time) return false; // Skip if date is invalid
            // Allow events from the last 24 hours to be included for late imports
            return $start_time > (time() - 86400); 
        });

        // Limit to the next 50 upcoming events
        $events_to_process = array_slice($future_events, 0, 50);
        
        foreach ($events_to_process as $event) {
            $event_title = !empty($event->summary) ? $event->summary : 'Untitled Event';
            
            if ($this->event_exists($event)) {
                $this->log[] = ['title' => $event_title, 'status' => 'Skipped (Duplicate)'];
                continue;
            }

            $location_term_id = $this->get_or_create_location_term($event);
            $this->create_event($event, $location_term_id);
        }
    }

    private function create_event($event, $location_term_id) {
        // Get pure UTC timestamps, the adapter will handle timezones
        $start_unix = $this->get_event_start_time($event);
        $end_unix = $this->get_event_end_time($event);

        if (!$start_unix) {
            $this->log[] = ['title' => $event->summary, 'status' => 'Failed (Invalid Date)'];
            return;
        }

        if (!$end_unix) {
            $end_unix = $start_unix + (2 * 3600); // Default to 2 hours if no end time
        }
        
        $cleaned_title = $this->clean_event_title($event->summary, $location_term_id);
        
        if(empty(trim($cleaned_title))){
            $this->log[] = ['title' => $event->summary, 'status' => 'Failed (Title became empty after cleaning)'];
            $cleaned_title = $event->summary; // Fallback to original title
        }

        $post_data = [
            'post_title'   => $cleaned_title,
            'post_content' => !empty($event->description) ? wp_kses_post($event->description) : '',
            'post_status'  => 'publish',
            'post_type'    => $this->adapter->get_post_type(),
        ];

        $post_id = wp_insert_post($post_data);

        if ($post_id && !is_wp_error($post_id)) {
            // Let the adapter handle saving the event meta
            $this->adapter->save_event_meta($post_id, $start_unix, $end_unix);

            if (!empty($event->uid)) {
                update_post_meta($post_id, '_kph_ical_uid', sanitize_text_field($event->uid));
            }
            update_post_meta($post_id, '_kph_import_date', time());
            
            if ($location_term_id) {
                // The adapter now provides the correct taxonomy (e.g., 'tribe_venue')
                wp_set_object_terms($post_id, (int)$location_term_id, $this->adapter->get_location_taxonomy());
            }

            if (!empty($event->organizer)) {
                $organizer_name_raw = is_object($event->organizer) && isset($event->organizer->getParams()['CN']) ? $event->organizer->getParams()['CN'] : $event->organizer;
                $organizer_name = sanitize_text_field( str_replace('mailto:','',$organizer_name_raw));
                wp_set_object_terms($post_id, $organizer_name, $this->adapter->get_organizer_taxonomy(), true);
            }
            
            // Generate the flyer image
            $header_generator = new KPH_Event_Header_Generator($this->adapter);
            $header_generator->generate_header_for_event($post_id, $location_term_id);

            $this->log[] = ['title' => $cleaned_title, 'status' => 'Imported'];
            
        } else {
             $this->log[] = ['title' => $cleaned_title, 'status' => 'Failed (WP Error on insert)'];
        }
    }

    private function event_exists($event) {
        $ical_uid = !empty($event->uid) ? sanitize_text_field($event->uid) : null;
        
        if ($ical_uid) {
            $args = [
                'post_type'      => $this->adapter->get_post_type(),
                'post_status'    => 'any',
                'posts_per_page' => 1,
                'meta_query'     => [
                    [
                        'key'   => '_kph_ical_uid',
                        'value' => $ical_uid,
                    ],
                ],
            ];
            $query = new WP_Query($args);
            if ($query->have_posts()) {
                return true;
            }
        }

        // Get pure UTC time for comparison
        $start_time = $this->get_event_start_time($event);
        if (!$start_time) return false;

        $cleaned_title = $this->clean_event_title($event->summary, null);
        
        $start_time_meta_key = $this->adapter->get_meta_key('start_time_utc'); 

        $args_fallback = [
            'post_type'      => $this->adapter->get_post_type(),
            'post_status'    => 'any',
            'posts_per_page' => 1,
            'title'          => $cleaned_title,
            'meta_query'     => [
                [
                    'key'     => $start_time_meta_key,
                    'value'   => $start_time, // Compare pure UTC timestamp
                    'compare' => '=',
                    'type'    => 'NUMERIC',
                ],
            ],
        ];
        $query_fallback = new WP_Query($args_fallback);
        return $query_fallback->have_posts();
    }
    
    private function get_or_create_location_term($event) {
        $ical_location_name = !empty($event->location) ? sanitize_text_field($event->location) : '';
        if (empty($ical_location_name)) {
            return null;
        }

        $location_obj = $this->adapter->find_location_term_by_name_or_alias($ical_location_name);
        $location_id = null;

        if ($location_obj) {
            if ($this->adapter->get_location_taxonomy() === 'tribe_venue') {
                $location_id = $location_obj->ID; // It's a WP_Post object
            } else {
                $location_id = $location_obj->term_id; // It's a WP_Term object
            }
            $this->adapter->ensure_location_alias($location_id, $ical_location_name);
            return $location_id;
        }


        $cleanup_options = get_option('kph_event_cleanup_options', []);
        $generic_terms_raw = $cleanup_options['blacklist'] ?? '';
        $generic_terms = array_map('trim', explode("\n", $generic_terms_raw));

        if (in_array(strtolower($ical_location_name), array_map('strtolower', $generic_terms))) {
            if (!empty($event->organizer)) {
                 $organizer_name_raw = is_object($event->organizer) && isset($event->organizer->getParams()['CN']) ? $event->organizer->getParams()['CN'] : $event->organizer;
                 $organizer_name = sanitize_text_field( str_replace('mailto:','',$organizer_name_raw));
                 $organizer_term = get_term_by('name', $organizer_name, $this->adapter->get_organizer_taxonomy());
                 if($organizer_term){
                     $existing_location = $this->adapter->find_location_for_organizer($organizer_term->term_id);
                     if($existing_location){
                        if ($this->adapter->get_location_taxonomy() === 'tribe_venue') {
                            return $existing_location->ID; // It's a WP_Post object
                        } else {
                            return $existing_location->term_id; // It's a WP_Term object
                        }
                     }
                 }
            }
            return null;
        }

        $new_term_id = $this->adapter->create_location_term($ical_location_name);
        
        if($new_term_id && !is_wp_error($new_term_id)){
             $this->adapter->ensure_location_alias($new_term_id, $ical_location_name);
             return $new_term_id;
        }

        return null;
    }

    private function fetch_ical_content($url) {
        $response = wp_remote_get($url, [
            'timeout'    => 30,
            'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
        ]);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return false;
        }
        return wp_remote_retrieve_body($response);
    }
    
    /**
     * Reliably gets the event start time as a PURE UTC timestamp.
     * @param object $event The iCal event object.
     * @return int|false The UNIX timestamp or false on failure.
     */
    private function get_event_start_time($event) {
        // FIX: The parser library provides dtstart_unix. This is the simplest,
        // most reliable, pre-calculated UTC timestamp. We will use this ONLY.
        if (!empty($event->dtstart_unix)) {
            return (int) $event->dtstart_unix;
        }
        
        // Fallback just in case, but dtstart_unix should always exist.
        try {
            if (!empty($event->dtstart_tz)) {
                $date = new DateTime($event->dtstart_tz, new DateTimeZone('UTC'));
                 return $date->getTimestamp();
            } elseif (!empty($event->dtstart)) {
                 $date = new DateTime($event->dtstart, new DateTimeZone('UTC'));
                 return $date->getTimestamp();
            }
        } catch (Exception $e) {
            $this->log[] = ['title' => $event->summary, 'status' => 'Failed (Could not parse start date)'];
            return false;
        }
        
        return false;
    }

    /**
     * Reliably gets the event end time as a PURE UTC timestamp.
     * @param object $event The iCal event object.
     * @return int|false The UNIX timestamp or false on failure.
     */
    private function get_event_end_time($event) {
         // FIX: Use dtend_unix for the same reasons as dtstart_unix.
        if (!empty($event->dtend_unix)) {
            return (int) $event->dtend_unix;
        }

        // Fallback just in case
        try {
            if (!empty($event->dtend_tz)) {
                $date = new DateTime($event->dtend_tz, new DateTimeZone('UTC'));
                 return $date->getTimestamp();
            } elseif (!empty($event->dtend)) {
                 $date = new DateTime($event->dtend, new DateTimeZone('UTC'));
                 return $date->getTimestamp();
            }
        } catch (Exception $e) {
             // Don't log failure for end date, we can create a default.
        }
        
        return false;
    }

    private function log_summary($message) {
        $this->log[] = ['title' => 'Summary', 'status' => $message];
    }

    private function save_cron_log() {
        $logs = get_option('kph_importer_cron_logs', []);
        $log_entry = [
            'timestamp' => time(),
            'summary'   => $this->get_summary_from_log(),
            'log'       => $this->log
        ];
        array_unshift($logs, $log_entry);
        update_option('kph_importer_cron_logs', array_slice($logs, 0, 10));
    }
    
    private function get_summary_from_log(){
        $imported = count(array_filter($this->log, fn($item) => $item['status'] === 'Imported'));
        $skipped = count(array_filter($this->log, fn($item) => $item['status'] === 'Skipped (Duplicate)'));
        $failed = count(array_filter($this->log, fn($item) => str_starts_with($item['status'], 'Failed')));
        $total_found = $imported + $skipped + $failed;

        return sprintf('Processed %d events. Imported: %d, Skipped: %d, Failed: %d.', $total_found, $imported, $skipped, $failed);
    }
    
    private function clean_event_title($title, $location_term_id){
         $cleanup_options = get_option('kph_event_cleanup_options', []);
         return KPH_Event_Cleanup::apply_cleanup_rules($title, $location_term_id, $cleanup_options);
    }
}