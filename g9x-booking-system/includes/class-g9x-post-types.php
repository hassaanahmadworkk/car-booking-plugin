<?php
/**
 * Class to handle custom post types
 */
class G9X_Post_Types {
    
    /**
     * Register custom post types
     */
    public function register() {
        $this->register_car_post_type();
        $this->register_booking_post_type();
    }
    
    /**
     * Register Car post type
     */
    private function register_car_post_type() {
        $labels = array(
            'name'               => 'Cars',
            'singular_name'      => 'Car',
            'menu_name'          => 'Cars',
            'add_new'            => 'Add New',
            'add_new_item'       => 'Add New Car',
            'edit_item'          => 'Edit Car',
            'new_item'           => 'New Car',
            'view_item'          => 'View Car',
            'search_items'       => 'Search Cars',
            'not_found'          => 'No cars found',
            'not_found_in_trash' => 'No cars found in Trash',
        );
        
        $args = array(
            'labels'             => $labels,
            'public'             => true,
            'publicly_queryable' => true,
            'show_ui'            => true,
            'show_in_menu'       => true,
            'query_var'          => true,
            'rewrite'            => array('slug' => 'car'),
            'capability_type'    => 'post',
            'has_archive'        => true,
            'hierarchical'       => false,
            'menu_position'      => null,
            'supports'           => array('title', 'editor', 'thumbnail'),
            'menu_icon'          => 'dashicons-car',
        );
        
        register_post_type('g9x_car', $args);
    }
    
    /**
     * Register Booking post type
     */
    private function register_booking_post_type() {
        $labels = array(
            'name'               => 'Bookings',
            'singular_name'      => 'Booking',
            'menu_name'          => 'Bookings',
            'add_new'            => 'Add New',
            'add_new_item'       => 'Add New Booking',
            'edit_item'          => 'Edit Booking',
            'new_item'           => 'New Booking',
            'view_item'          => 'View Booking',
            'search_items'       => 'Search Bookings',
            'not_found'          => 'No bookings found',
            'not_found_in_trash' => 'No bookings found in Trash',
        );
        
        $args = array(
            'labels'             => $labels,
            'public'             => true,
            'publicly_queryable' => true,
            'show_ui'            => true,
            'show_in_menu'       => true,
            'query_var'          => true,
            'rewrite'            => array('slug' => 'booking'),
            'capability_type'    => 'post',
            'has_archive'        => true,
            'hierarchical'       => false,
            'menu_position'      => null,
            'supports'           => array('title'),
            'menu_icon'          => 'dashicons-calendar-alt',
        );
        
        register_post_type('g9x_booking', $args);
    }
}