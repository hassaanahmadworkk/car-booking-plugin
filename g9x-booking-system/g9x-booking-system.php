<?php
/**
 * Plugin Name: G9X Booking System
 * Description: A custom booking system for cars with time slots
 * Version: 1.0.0
 * Author: Grow9x
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('G9X_BOOKING_PATH', plugin_dir_path(__FILE__));
define('G9X_BOOKING_URL', plugin_dir_url(__FILE__));
define('G9X_BOOKING_VERSION', '1.0.0');

// Include required files
require_once G9X_BOOKING_PATH . 'includes/class-g9x-post-types.php';
require_once G9X_BOOKING_PATH . 'includes/class-g9x-booking-handler.php';
require_once G9X_BOOKING_PATH . 'includes/class-g9x-timeslot-manager.php';
require_once G9X_BOOKING_PATH . 'includes/class-g9x-form-renderer.php';
require_once G9X_BOOKING_PATH . 'includes/class-g9x-admin-meta-boxes.php'; // Include the new admin class
/**
 * Initialize plugin classes
 */
function g9x_booking_init() {
    $post_types = new G9X_Post_Types();
    $post_types->register();

    $timeslot_manager = new G9X_Timeslot_Manager();
    $booking_handler = new G9X_Booking_Handler();
    $form_renderer = new G9X_Form_Renderer();

    add_shortcode('g9x_booking_form', array($form_renderer, 'render_booking_form'));

    // Initialize admin features only if in admin area
    if (is_admin()) {
        $admin_meta_boxes = new G9X_Admin_Meta_Boxes();
    }
}
add_action('init', 'g9x_booking_init');

/**
 * Plugin activation hook
 */
function g9x_booking_activate() {
    // Create required database tables
    g9x_create_tables();
    
    // Register post types
    $post_types = new G9X_Post_Types();
    $post_types->register();
    
    // Flush rewrite rules
    flush_rewrite_rules();
}
register_activation_hook(__FILE__, 'g9x_booking_activate');

/**
 * Create custom database tables
 */
function g9x_create_tables() {
    global $wpdb;
    
    $charset_collate = $wpdb->get_charset_collate();
    
    $table_name = $wpdb->prefix . 'g9x_timeslots';
    
    $sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        car_id mediumint(9) NOT NULL,
        booking_date date NOT NULL,
        time_start time NOT NULL,
        time_end time NOT NULL,
        is_booked tinyint(1) DEFAULT 0 NOT NULL,
        booking_id mediumint(9) DEFAULT 0,
        PRIMARY KEY  (id)
    ) $charset_collate;";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}
/**
 * Enqueue scripts and styles
 */
function g9x_enqueue_scripts() {
    // Enqueue jQuery UI
    wp_enqueue_script('jquery-ui-datepicker');
    wp_enqueue_style('jquery-ui', 'https://code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css');
    
    // Plugin scripts and styles
    wp_enqueue_style('g9x-booking-style', G9X_BOOKING_URL . 'assets/css/g9x-booking-style.css', array(), G9X_BOOKING_VERSION);
    wp_enqueue_script('g9x-booking-script', G9X_BOOKING_URL . 'assets/js/g9x-booking-script.js', array('jquery', 'jquery-ui-datepicker'), G9X_BOOKING_VERSION, true);
    
    // Add localized script data
    wp_localize_script('g9x-booking-script', 'g9x_booking', array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('g9x_booking_nonce')
    ));
}
add_action('wp_enqueue_scripts', 'g9x_enqueue_scripts');
