<?php

if (!defined('WPINC')) {
    die;
}

/**
 * Class KPH_Calendar_Adapter
 *
 * This class acts as a bridge between the importer and the active calendar plugin.
 * It provides a single, consistent interface for getting/setting plugin-specific data,
 * such as post types, taxonomies, and meta field keys.
 */
class KPH_Calendar_Adapter {

    private $active_plugin = 'none';
    private $site_timezone_string;

    public function __construct() {
        if (class_exists('EventON')) {
            $this->active_plugin = 'eventon';
        } elseif (class_exists('Tribe__Events__Main')) {
            $this->active_plugin = 'tec';
        } elseif (class_exists('MEC')) {
            $this->active_plugin = 'mec';
        }
        $this->site_timezone_string = wp_timezone_string(); // eg. 'Asia/Bangkok'
    }

    public function is_supported_calendar_active() {
        return $this->active_plugin !== 'none';
    }

    public function get_post_type() {
        switch ($this->active_plugin) {
            case 'eventon':
                return 'ajde_events';
            case 'tec':
                return 'tribe_events';
            case 'mec':
                return 'mec-events';
            default:
                return 'post';
        }
    }

    public function get_location_taxonomy() {
        switch ($this->active_plugin) {
            case 'eventon':
                return 'event_location';
            case 'tec':
                // TEC uses 'tribe_venue' for its locations, which is a Post Type
                return 'tribe_venue';
            case 'mec':
                return 'mec_location';
            default:
                return 'category';
        }
    }

    public function get_organizer_taxonomy() {
        switch ($this->active_plugin) {
            case 'eventon':
                return 'event_organizer';
            case 'tec':
                // TEC uses 'tribe_organizer' for organizers, which is a Post Type
                return 'tribe_organizer';
            case 'mec':
                return 'mec_organizer';
            default:
                return 'category';
        }
    }
    
    /**
     * Gets the meta key for a specific piece of data.
     * This allows the importer to be generic, e.g., "get start_time key"
     */
    public function get_meta_key($key) {
        switch ($this->active_plugin) {
            case 'eventon':
                if ($key === 'start_time') return 'evcal_srow';
                if ($key === 'end_time') return 'evcal_erow';
                // EventON stores start/end time as local timestamp
                if ($key === 'start_time_utc') return 'evcal_srow'; 
                break;
            case 'tec':
                if ($key === 'start_time') return '_EventStartDate';
                if ($key === 'end_time') return '_EventEndDate';
                // TEC stores a separate UTC timestamp
                if ($key === 'start_time_utc') return '_EventStartDateUTC';
                break;
            case 'mec':
                 if ($key === 'start_time') return 'mec_start_timestamp';
                 if ($key === 'end_time') return 'mec_end_timestamp';
                 if ($key === 'start_time_utc') return 'mec_start_timestamp';
                 break;
        }
        return '';
    }

    /**
     * Saves event meta data in the correct format for the active plugin.
     * The Importer will ALWAYS pass pure UTC timestamps here.
     * This function is responsible for creating any local-time versions.
     */
    public function save_event_meta($post_id, $start_unix_utc, $end_unix_utc) {
        
        // Create DateTime objects from the pure UTC timestamps
        $start_utc_dt = new DateTime("@$start_unix_utc");
        $end_utc_dt = new DateTime("@$end_unix_utc");
        
        // Create local-time versions based on site's timezone
        $local_tz = new DateTimeZone($this->site_timezone_string);
        $start_local_dt = (new DateTime("@$start_unix_utc"))->setTimezone($local_tz);
        $end_local_dt = (new DateTime("@$end_unix_utc"))->setTimezone($local_tz);

        switch ($this->active_plugin) {
            case 'eventon':
                // EventON stores event time as a UNIX timestamp in the site's local timezone.
                // We will use the local DateTime object's timestamp.
                update_post_meta($post_id, 'evcal_srow', $start_local_dt->getTimestamp());
                update_post_meta($post_id, 'evcal_erow', $end_local_dt->getTimestamp());
                update_post_meta($post_id, 'evcal_allday', 'no');
                break;

            case 'tec':
                // TEC is smart. It stores both local time and UTC time.
                // 1. Local time as 'Y-m-d H:i:s' string
                update_post_meta($post_id, '_EventStartDate', $start_local_dt->format('Y-m-d H:i:s'));
                update_post_meta($post_id, '_EventEndDate', $end_local_dt->format('Y-m-d H:i:s'));
                
                // 2. UTC time as 'Y-m-d H:i:s' string
                update_post_meta($post_id, '_EventStartDateUTC', $start_utc_dt->format('Y-m-d H:i:s'));
                update_post_meta($post_id, '_EventEndDateUTC', $end_utc_dt->format('Y-m-d H:i:s'));
                
                // 3. The site's timezone string
                update_post_meta($post_id, '_EventTimezone', $this->site_timezone_string);
                update_post_meta($post_id, '_EventAllDay', 'no');
                break;

            case 'mec':
                // MEC seems to store timestamps. We'll give it the local timestamp.
                update_post_meta($post_id, 'mec_start_timestamp', $start_local_dt->getTimestamp());
                update_post_meta($post_id, 'mec_end_timestamp', $end_local_dt->getTimestamp());
                // It also stores date/time in specific formats
                update_post_meta($post_id, 'mec_start_date', $start_local_dt->format('Y-m-d'));
                update_post_meta($post_id, 'mec_start_time_hour', $start_local_dt->format('H'));
                update_post_meta($post_id, 'mec_start_time_minutes', $start_local_dt->format('i'));
                update_post_meta($post_id, 'mec_end_date', $end_local_dt->format('Y-m-d'));
                update_post_meta($post_id, 'mec_end_time_hour', $end_local_dt->format('H'));
                update_post_meta($post_id, 'mec_end_time_minutes', $end_local_dt->format('i'));
                update_post_meta($post_id, 'mec_allday', '0');
                break;
        }
    }
    
    /**
     * Finds a location term by its name or alias.
     * This is complex because TEC uses Venues (which are posts) and EventON uses a taxonomy.
     */
    public function find_location_term_by_name_or_alias($location_name) {
        $taxonomy = $this->get_location_taxonomy();
        $normalized_name = strtolower(trim($location_name));

        if ($this->active_plugin === 'tec') {
            // For TEC, 'tribe_venue' is a post type, not a taxonomy.
            $args = [
                'post_type' => 'tribe_venue',
                'name' => sanitize_title($location_name), // More reliable than title
                'posts_per_page' => 1,
            ];
            $query = new WP_Query($args);
            if ($query->have_posts()) {
                return $query->posts[0]; // Returns a WP_Post object
            }
            // Check aliases
            $args_alias = [
                'post_type' => 'tribe_venue',
                'posts_per_page' => 1,
                'meta_query' => [
                    [
                        'key' => '_kph_location_aliases',
                        'value' => $normalized_name,
                        'compare' => 'LIKE',
                    ],
                ]
            ];
            $query_alias = new WP_Query($args_alias);
            if ($query_alias->have_posts()) {
                 return $query_alias->posts[0];
            }
            return null;

        } else {
            // For EventON and MEC, it's a standard taxonomy.
            $term = get_term_by('name', $location_name, $taxonomy);
            if ($term) {
                return $term; // Returns a WP_Term object
            }
            
            // Check aliases in term meta
            $args = [
                'taxonomy'   => $taxonomy,
                'hide_empty' => false,
                'meta_query' => [
                    [
                        'key'     => '_kph_location_aliases',
                        'value'   => $normalized_name,
                        'compare' => 'LIKE', 
                    ],
                ],
            ];
            $existing_terms = get_terms($args);
            
            foreach($existing_terms as $term) {
                $aliases = get_term_meta($term->term_id, '_kph_location_aliases', true);
                if (is_array($aliases) && in_array($normalized_name, $aliases)) {
                    return $term; // Found a perfect match!
                }
            }
            return null;
        }
    }
    
    /**
     * Creates a new location term (or Venue post for TEC).
     */
    public function create_location_term($location_name) {
        if ($this->active_plugin === 'tec') {
            // Create a new Venue post
            $post_data = [
                'post_title' => $location_name,
                'post_status' => 'publish',
                'post_type' => 'tribe_venue',
            ];
            $post_id = wp_insert_post($post_data);
            return (is_wp_error($post_id) || $post_id === 0) ? null : $post_id; // Return the new post ID

        } else {
            // Create a new taxonomy term
            $new_term_data = wp_insert_term($location_name, $this->get_location_taxonomy());
            if (is_wp_error($new_term_data)) {
                return null;
            }
            return $new_term_data['term_id']; // Return the new term ID
        }
    }

    /**
     * Ensures an alias is saved for a location.
     */
    public function ensure_location_alias($location_id, $alias_name) {
        $normalized_name = strtolower(trim($alias_name));
        $meta_key = '_kph_location_aliases';

        if ($this->active_plugin === 'tec') {
            // 'tribe_venue' uses post meta
            $aliases = get_post_meta($location_id, $meta_key, true);
            if (!is_array($aliases)) $aliases = [];
            if (!in_array($normalized_name, $aliases)) {
                $aliases[] = $normalized_name;
                update_post_meta($location_id, $meta_key, array_unique($aliases));
            }
        } else {
            // Taxonomies use term meta
            $aliases = get_term_meta($location_id, $meta_key, true);
            if (!is_array($aliases)) $aliases = [];
            if (!in_array($normalized_name, $aliases)) {
                $aliases[] = $normalized_name;
                update_term_meta($location_id, $meta_key, array_unique($aliases));
            }
        }
    }
    
    /**
     * Finds the primary location associated with an organizer.
     */
    public function find_location_for_organizer($organizer_term_id) {
         $args = [
            'post_type' => $this->get_post_type(),
            'posts_per_page' => 1,
            'tax_query' => [
                [
                    'taxonomy' => $this->get_organizer_taxonomy(),
                    'field' => 'term_id',
                    'terms' => $organizer_term_id,
                ],
            ],
            'orderby' => 'date',
            'order' => 'DESC'
        ];
        $query = new WP_Query($args);

        if($query->have_posts()){
            $event_id = $query->posts[0]->ID;
            $location_terms = get_the_terms($event_id, $this->get_location_taxonomy());
            if(!empty($location_terms) && !is_wp_error($location_terms)){
                // For TEC, this returns a WP_Post object for the Venue
                // For EventON, this returns a WP_Term object
                return $location_terms[0];
            }
        }
        return null;
    }
}