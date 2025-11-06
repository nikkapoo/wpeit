<?php

if (!defined('WPINC')) {
    die;
}

class KPH_Admin_Columns {

    private $adapter;

    public function __construct($adapter) {
        $this->adapter = $adapter;
        $post_type = $this->adapter->get_post_type();

        // Add the custom column to the events list
        add_filter("manage_{$post_type}_posts_columns", [$this, 'add_import_date_column']);
        // Populate the custom column with data
        add_action("manage_{$post_type}_posts_custom_column", [$this, 'render_import_date_column'], 10, 2);
        // Make the column sortable
        add_filter("manage_edit-{$post_type}_sortable_columns", [$this, 'make_import_date_column_sortable']);
        // Handle the sorting logic
        add_action('pre_get_posts', [$this, 'import_date_orderby']);
    }

    /**
     * Adds the "Import Date" column to the event list table.
     */
    public function add_import_date_column($columns) {
        // Add the column before the 'Date' column
        $new_columns = [];
        foreach ($columns as $key => $title) {
            if ($key === 'date') {
                $new_columns['import_date'] = 'Import Date';
            }
            $new_columns[$key] = $title;
        }
        return $new_columns;
    }

    /**
     * Renders the content for the "Import Date" column.
     */
    public function render_import_date_column($column, $post_id) {
        if ($column === 'import_date') {
            $import_timestamp = get_post_meta($post_id, '_kph_import_date', true);
            if ($import_timestamp) {
                // Display in the site's configured timezone
                echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $import_timestamp));
            } else {
                echo '—';
            }
        }
    }

    /**
     * Registers the "Import Date" column as sortable.
     */
    public function make_import_date_column_sortable($columns) {
        $columns['import_date'] = 'import_date';
        return $columns;
    }

    /**
     * Modifies the main query to handle sorting by the "Import Date" column.
     */
    public function import_date_orderby($query) {
        // Check if we are on the correct admin page and the main query
        if (!is_admin() || !$query->is_main_query() || $query->get('post_type') !== $this->adapter->get_post_type()) {
            return;
        }

        $orderby = $query->get('orderby');

        if ('import_date' === $orderby) {
            $query->set('meta_key', '_kph_import_date');
            $query->set('orderby', 'meta_value_num');
        }
    }
}
