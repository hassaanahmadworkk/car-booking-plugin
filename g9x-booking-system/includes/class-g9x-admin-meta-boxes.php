<?php
/**
 * Class to handle admin meta boxes for G9X Booking System CPTs
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class G9X_Admin_Meta_Boxes {

    /**
     * Constructor
     */
    public function __construct() {
        add_action('add_meta_boxes', array($this, 'add_meta_boxes'));
        add_action('save_post_g9x_car', array($this, 'save_car_meta_data'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets')); // Hook asset enqueueing
        add_action('wp_ajax_g9x_get_admin_slots_for_date', array($this, 'ajax_get_admin_slots_for_date')); // Add AJAX action
    }

    /**
     * Add meta boxes to the 'g9x_car' post type edit screen
     */
    public function add_meta_boxes() {
        add_meta_box(
            'g9x_car_timeslot_config',      // ID
            'Timeslot Configuration',       // Title
            array($this, 'render_timeslot_config_meta_box'), // Callback function
            'g9x_car',                      // Post type
            'normal',                       // Context (normal, side, advanced)
            'high'                          // Priority (high, core, default, low)
        );

        add_meta_box(
            'g9x_car_unavailable_dates',    // ID
            'Unavailable Dates & Slots',    // Title (Updated)
            array($this, 'render_unavailable_dates_meta_box'), // Callback function
            'g9x_car',                      // Post type
            'normal',                       // Context
            'high'                          // Priority
        );
    }

    /**
     * Render the Timeslot Configuration meta box content
     */
    public function render_timeslot_config_meta_box($post) {
        // Add a nonce field for security
        wp_nonce_field('g9x_save_car_meta', 'g9x_car_meta_nonce');

        // Get existing config or defaults
        $config = get_post_meta($post->ID, '_g9x_timeslot_config', true);
        $defaults = [
            'start' => '09:00:00',
            'end' => '17:00:00',
            'interval' => 60
        ];
        $config = wp_parse_args($config, $defaults);

        ?>
        <table class="form-table">
            <tbody>
                <tr>
                    <th scope="row"><label for="g9x_start_time">Start Time</label></th>
                    <td>
                        <input type="time" id="g9x_start_time" name="_g9x_timeslot_config[start]" value="<?php echo esc_attr($config['start']); ?>" step="60">
                        <p class="description">Time when the first slot starts (e.g., 09:00).</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="g9x_end_time">End Time</label></th>
                    <td>
                        <input type="time" id="g9x_end_time" name="_g9x_timeslot_config[end]" value="<?php echo esc_attr($config['end']); ?>" step="60">
                         <p class="description">Time when the last slot must END (e.g., 17:00 means the last slot is 16:00-17:00 if interval is 60 mins).</p>
                   </td>
                </tr>
                <tr>
                    <th scope="row"><label for="g9x_interval">Interval (minutes)</label></th>
                    <td>
                        <input type="number" id="g9x_interval" name="_g9x_timeslot_config[interval]" value="<?php echo esc_attr($config['interval']); ?>" min="1" step="1">
                        <p class="description">Duration of each time slot in minutes (e.g., 60 for hourly slots).</p>
                    </td>
                </tr>
            </tbody>
        </table>
        <?php
    }

    /**
     * Render the Unavailable Dates meta box content
     */
    public function render_unavailable_dates_meta_box($post) {
        // Nonce field is already added in the other meta box callback

        // Get existing unavailable dates (full days)
        $unavailable_dates = get_post_meta($post->ID, '_g9x_unavailable_dates', true);
        $unavailable_dates_str = is_array($unavailable_dates) ? implode(', ', $unavailable_dates) : '';

        ?>
        <p>Use the calendar below to manage unavailability. Select a date to view its status and time slots.</p>
        <!-- Remove the H4 and paragraph -->
        <p>
            <label for="g9x_unavailability_datepicker">Select Date:</label><br>
            <input type="text" id="g9x_unavailability_datepicker" readonly style="width: 200px; margin-bottom: 10px;">
        </p>
        <div id="g9x_day_slot_manager" style="display: none; margin-top: 10px; padding: 15px; border: 1px solid #ccd0d4; background-color: #f6f7f7;">
             <p style="margin-top: 0;">
                 <label>
                     <input type="checkbox" id="g9x_unavailable_whole_day">
                     <strong>Make entire day <span id="g9x_selected_date_label"></span> unavailable?</strong>
                 </label>
             </p>
             <div id="g9x_specific_slots_container" style="border-top: 1px dashed #ccd0d4; padding-top: 10px; margin-top: 10px;">
                 Select a date above to load slots...
             </div>
        </div>
        <?php
            // Hidden input for full unavailable days (managed by JS)
            $unavailable_dates_hidden_value = is_array($unavailable_dates) ? implode(', ', $unavailable_dates) : '';
        ?>
         <input type="hidden" id="g9x_unavailable_dates_hidden" name="_g9x_unavailable_dates_list" value="<?php echo esc_attr($unavailable_dates_hidden_value); ?>">

        <?php
            // Hidden input for specific unavailable slots (managed by JS)
            $unavailable_slots_processed = get_post_meta($post->ID, '_g9x_unavailable_slots', true);
            $unavailable_slots_flat_list = [];
            if (is_array($unavailable_slots_processed)) {
                foreach ($unavailable_slots_processed as $date => $slots) {
                    if (is_array($slots)) {
                        foreach ($slots as $slot_key) {
                             $unavailable_slots_flat_list[] = $date . ':' . $slot_key;
                        }
                    }
                }
            }
            sort($unavailable_slots_flat_list);
            $unavailable_slots_hidden_value = implode(', ', $unavailable_slots_flat_list);
        ?>
        <input type="hidden" id="g9x_unavailable_slots_hidden" name="_g9x_unavailable_slots_list" value="<?php echo esc_attr($unavailable_slots_hidden_value); ?>">
        <p class="description" style="margin-top: 15px;">
            Use the calendar to select a date. Then, either check the box to make the whole day unavailable, or click individual time slots below to make only those specific slots unavailable (highlighted red). Changes are saved when you update the car post.
        </p>
        
        <hr style="margin-top: 20px; margin-bottom: 15px;">
        <h4>Current Unavailability Summary</h4>
        <div id="g9x_unavailability_summary" style="max-height: 200px; overflow-y: auto; background: #fff; border: 1px solid #ddd; padding: 10px;">
            <?php
            // Display initial summary based on saved meta
            $summary_html = '';
            // Full days
            if (!empty($unavailable_dates)) { // Use the variable already fetched
                 $summary_html .= '<h5>Full Days Unavailable:</h5><dl>';
                 // Sort dates for display
                 $sorted_full_days = $unavailable_dates;
                 sort($sorted_full_days);
                 foreach ($sorted_full_days as $full_date) {
                     // Using dt for date, dd for status (could be just dt)
                     $summary_html .= '<dt>' . esc_html($full_date) . '</dt>';
                     // $summary_html .= '<dd>Unavailable</dd>'; // Optional detail
                 }
                 $summary_html .= '</dl>';
            } else {
                 $summary_html .= '<p><em>No full days marked as unavailable.</em></p>';
            }

            // Specific slots
            if (!empty($unavailable_slots_processed)) { // Use the variable already fetched
                 $summary_html .= '<h5 style="margin-top: 15px;">Specific Slots Unavailable:</h5><dl>';
                 // Sort by date first
                 ksort($unavailable_slots_processed);
                 foreach ($unavailable_slots_processed as $spec_date => $slots) {
                     if (!empty($slots)) {
                         $summary_html .= '<dt>' . esc_html($spec_date) . '</dt>'; // Date as term
                         // Sort slots by start time for display
                         usort($slots, function($a, $b) {
                             return strtotime(explode('-', $a)[0]) - strtotime(explode('-', $b)[0]);
                         });
                         foreach ($slots as $slot_key) {
                              list($start, $end) = explode('-', $slot_key);
                              // Format time without seconds for display
                              $formatted_time = date('g:i A', strtotime($start)) . ' - ' . date('g:i A', strtotime($end));
                              $summary_html .= '<dd>' . esc_html($formatted_time) . '</dd>'; // Slot as definition
                         }
                     }
                 }
                 $summary_html .= '</dl>';
            } else {
                 $summary_html .= '<p style="margin-top: 15px;"><em>No specific time slots marked as unavailable.</em></p>';
            }
            
            echo $summary_html; // Output the initial summary
            ?>
        </div>
        <?php
    }

    /**
     * Save meta data when the 'g9x_car' post is saved
     */
    public function save_car_meta_data($post_id) {
        // 1. Check nonce
        if (!isset($_POST['g9x_car_meta_nonce']) || !wp_verify_nonce($_POST['g9x_car_meta_nonce'], 'g9x_save_car_meta')) {
            return;
        }

        // 2. Check if this is an autosave
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        // 3. Check the user's permissions
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        // 4. Check if the post type is correct
        if ('g9x_car' !== get_post_type($post_id)) {
            return;
        }

        // --- Save Timeslot Configuration ---
        if (isset($_POST['_g9x_timeslot_config']) && is_array($_POST['_g9x_timeslot_config'])) {
            $config_data = $_POST['_g9x_timeslot_config'];
            $sanitized_config = [];

            // Sanitize start time (HH:MM or HH:MM:SS)
            if (isset($config_data['start']) && preg_match('/^([01]\d|2[0-3]):([0-5]\d)(:([0-5]\d))?$/', $config_data['start'], $matches)) {
                 $sanitized_config['start'] = $matches[1] . ':' . $matches[2] . ':' . (isset($matches[4]) ? $matches[4] : '00');
            } else {
                $sanitized_config['start'] = '09:00:00'; // Default
            }

            // Sanitize end time (HH:MM or HH:MM:SS)
            if (isset($config_data['end']) && preg_match('/^([01]\d|2[0-3]):([0-5]\d)(:([0-5]\d))?$/', $config_data['end'], $matches)) {
                 $sanitized_config['end'] = $matches[1] . ':' . $matches[2] . ':' . (isset($matches[4]) ? $matches[4] : '00');
            } else {
                $sanitized_config['end'] = '17:00:00'; // Default
            }

            // Sanitize interval (positive integer)
            if (isset($config_data['interval']) && is_numeric($config_data['interval']) && intval($config_data['interval']) > 0) {
                $sanitized_config['interval'] = intval($config_data['interval']);
            } else {
                $sanitized_config['interval'] = 60; // Default
            }

            if (strtotime($sanitized_config['start']) >= strtotime($sanitized_config['end'])) {
                 error_log("G9X Booking: Invalid time configuration saved for post $post_id - Start time is not before end time.");
            }

            update_post_meta($post_id, '_g9x_timeslot_config', $sanitized_config);
        }

        // --- Save Unavailable Dates (Full Days) --- From Hidden Input ---
        $full_days_unavailable = [];
        if (isset($_POST['_g9x_unavailable_dates_list'])) { // Read from the correct hidden input
            $dates_str = sanitize_text_field($_POST['_g9x_unavailable_dates_list']);
            if (!empty($dates_str)) {
                $potential_dates = array_filter(array_map('trim', explode(',', $dates_str)));
                foreach ($potential_dates as $date) {
                    // Validate YYYY-MM-DD format
                    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                         $d = DateTime::createFromFormat('Y-m-d', $date);
                         if ($d && $d->format('Y-m-d') === $date) {
                             $full_days_unavailable[] = $date;
                         }
                    }
                }
                $full_days_unavailable = array_unique($full_days_unavailable);
                sort($full_days_unavailable);
            }
        }
        // Update meta, deleting if the array is empty
        if (!empty($full_days_unavailable)) {
             update_post_meta($post_id, '_g9x_unavailable_dates', $full_days_unavailable);
        } else {
             delete_post_meta($post_id, '_g9x_unavailable_dates');
        }

        // --- Save Specifically Unavailable Slots (From Hidden Input) ---
        $processed_unavailable_slots = []; // Rebuild [date => [slot_key, ...]] structure
        if (isset($_POST['_g9x_unavailable_slots_list'])) { // Read from the correct hidden input
            $flat_list_str = sanitize_text_field($_POST['_g9x_unavailable_slots_list']);
            if (!empty($flat_list_str)) {
                $items = array_filter(array_map('trim', explode(',', $flat_list_str)));
                foreach ($items as $item) {
                     // Match format YYYY-MM-DD:HH:MM:SS-HH:MM:SS
                     if (preg_match('/^(\d{4}-\d{2}-\d{2}):(\d{2}:\d{2}:\d{2}-\d{2}:\d{2}:\d{2})$/', $item, $matches)) {
                         $date = $matches[1];
                         $slot_key = $matches[2];
                         
                         // Basic validation of date part
                         $d = DateTime::createFromFormat('Y-m-d', $date);
                         if ($d && $d->format('Y-m-d') === $date) {
                              // Ensure this date is NOT marked as a full unavailable day
                              if (!in_array($date, $full_days_unavailable)) {
                                   if (!isset($processed_unavailable_slots[$date])) {
                                       $processed_unavailable_slots[$date] = [];
                                   }
                                   // Avoid duplicates just in case JS allows it somehow
                                   if (!in_array($slot_key, $processed_unavailable_slots[$date])) {
                                        $processed_unavailable_slots[$date][] = $slot_key;
                                   }
                              } // else: ignore specific slot if whole day is unavailable
                         }
                     }
                }
                // Sort slots within each date and dates themselves
                foreach ($processed_unavailable_slots as $date => &$slots) {
                    sort($slots);
                }
                unset($slots);
                ksort($processed_unavailable_slots);
            }
        }
        // Update meta, deleting if the array is empty
        if (!empty($processed_unavailable_slots)) {
             update_post_meta($post_id, '_g9x_unavailable_slots', $processed_unavailable_slots);
        } else {
             delete_post_meta($post_id, '_g9x_unavailable_slots');
        }
        // We don't need to save the raw textarea anymore
        delete_post_meta($post_id, '_g9x_unavailable_slots_raw');

    } // End save_car_meta_data


    /**
     * AJAX handler to get potential slots for a date for admin UI.
     */
    public function ajax_get_admin_slots_for_date() {
        // 1. Check nonce and permissions
        check_ajax_referer('g9x_admin_slot_nonce', 'nonce');
        if (!current_user_can('edit_posts')) { // Adjust capability check if needed
            wp_send_json_error('Permission denied.', 403);
        }

        // 2. Get parameters
        $date = isset($_POST['date']) ? sanitize_text_field($_POST['date']) : null;
        $car_id = isset($_POST['car_id']) ? intval($_POST['car_id']) : 0;

        if (!$date || !$car_id || !wp_checkdate(intval(substr($date, 5, 2)), intval(substr($date, 8, 2)), intval(substr($date, 0, 4)), $date)) {
            wp_send_json_error('Invalid parameters.', 400);
        }

        // 3. Get data
        // Need G9X_Timeslot_Manager class, ensure it's loaded or require it here if necessary
        if (!class_exists('G9X_Timeslot_Manager')) {
             require_once G9X_BOOKING_PATH . 'includes/class-g9x-timeslot-manager.php';
        }
        $timeslot_manager = new G9X_Timeslot_Manager();
        $potential_slots = $timeslot_manager->get_dynamic_time_ranges($car_id);
        
        // Get specific unavailable slots for the date
        $unavailable_slots_processed = get_post_meta($car_id, '_g9x_unavailable_slots', true);
        $unavailable_slots_today = [];
        if (is_array($unavailable_slots_processed) && isset($unavailable_slots_processed[$date])) {
            $unavailable_slots_today = $unavailable_slots_processed[$date];
        }
        
        // Check if the entire day is marked as unavailable
        $unavailable_full_days = get_post_meta($car_id, '_g9x_unavailable_dates', true);
        $is_whole_day_unavailable = is_array($unavailable_full_days) && in_array($date, $unavailable_full_days);

        // 4. Format response
        $response_slots = [];
        if (empty($potential_slots)) {
             wp_send_json_success(['slots' => [], 'message' => 'No slots configured for this car.']);
             return;
        }
        
        foreach ($potential_slots as $range) {
            $start_time = $range[0];
            $end_time = $range[1];
            $slot_key = $start_time . '-' . $end_time;
            $is_unavailable = in_array($slot_key, $unavailable_slots_today);

            $response_slots[] = [
                'time_start' => $start_time,
                'time_end' => $end_time,
                'formatted' => date('h:i A', strtotime($start_time)) . ' - ' . date('h:i A', strtotime($end_time)),
                'slot_key' => $slot_key, // HH:MM:SS-HH:MM:SS
                'is_unavailable' => $is_unavailable,
            ];
        }

        wp_send_json_success([
            'slots' => $response_slots,
            'is_whole_day_unavailable' => $is_whole_day_unavailable // Add this flag to the response
        ]);
    } // End ajax_get_admin_slots_for_date

    /**
     * Enqueue admin scripts and styles for the datepicker.
     */
    public function enqueue_admin_assets($hook_suffix) {
        global $post_type, $post; // Need $post to get current post ID

        // Only load on the 'add new' and 'edit' pages for 'g9x_car' post type
        if ($post_type === 'g9x_car' && ($hook_suffix === 'post.php' || $hook_suffix === 'post-new.php')) {
            
            // Enqueue jQuery UI datepicker (core WordPress script)
            wp_enqueue_script('jquery-ui-datepicker');
            
            // Enqueue a standard jQuery UI theme CSS from CDN as WP isn't loading it automatically
            wp_enqueue_style(
                'jquery-ui-theme',
                'https://ajax.googleapis.com/ajax/libs/jqueryui/1.12.1/themes/smoothness/jquery-ui.css', // Using Smoothness theme from Google CDN
                false,
                '1.12.1' // Version
            );
            
            // Enqueue our custom admin script
            wp_enqueue_script(
                'g9x-admin-script', 
                G9X_BOOKING_URL . 'assets/js/g9x-admin-script.js', 
                array('jquery', 'jquery-ui-datepicker'), // Dependencies - Remove jquery-ui-style again
                G9X_BOOKING_VERSION, 
                true // Load in footer
            );

            // Localize script to pass necessary data (AJAX URL, nonce, post ID)
            wp_localize_script('g9x-admin-script', 'g9x_admin_data', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('g9x_admin_slot_nonce'), // Create nonce for AJAX
                'car_id' => isset($post->ID) ? $post->ID : 0 // Pass current car ID
            ));

            // Enqueue the dedicated admin stylesheet
            wp_enqueue_style(
                'g9x-admin-style', // Our custom style handle
                G9X_BOOKING_URL . 'assets/css/g9x-admin-style.css',
                array(), // Dependencies - Remove dependency here
                G9X_BOOKING_VERSION
            );
        }
    } // End enqueue_admin_assets

} // End class G9X_Admin_Meta_Boxes