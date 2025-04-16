<?php
/**
 * Class to manage time slots
 */
class G9X_Timeslot_Manager {
    
    /**
     * Constructor
     */
    public function __construct() {
        add_action('wp_ajax_g9x_get_available_dates', array($this, 'get_available_dates'));
        add_action('wp_ajax_nopriv_g9x_get_available_dates', array($this, 'get_available_dates'));
        
        add_action('wp_ajax_g9x_get_available_timeslots', array($this, 'get_available_timeslots'));
        add_action('wp_ajax_nopriv_g9x_get_available_timeslots', array($this, 'get_available_timeslots'));

        // Hooks to release slots when a booking is trashed or deleted
        add_action('wp_trash_post', array($this, 'release_booking_slots'));
        add_action('before_delete_post', array($this, 'release_booking_slots'));
        
        // Removed the hook that automatically creates timeslots on publish
        // add_action('publish_g9x_car', array($this, 'create_car_timeslots'), 10, 2); 
        // TODO: Implement an admin interface (e.g., meta boxes for 'g9x_car') 
        // to set '_g9x_timeslot_config' and '_g9x_unavailable_dates'.
    }
    
    /**
     * Create initial time slots for a car - Deprecated
     * Admin should manage availability and slot templates directly.
     */
    public function create_car_timeslots($post_id, $post) {
        // This function is no longer used for automatic generation.
        error_log("G9X_Timeslot_Manager::create_car_timeslots called for car ID: $post_id. Automatic slot generation is disabled.");
    }
    
  /**
 * Get available dates for a car
 */
public function get_available_dates() {
    // Verify nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'g9x_booking_nonce')) {
        wp_send_json_error('Invalid nonce');
    }
    
    $car_id = isset($_POST['car_id']) ? intval($_POST['car_id']) : 0;
    
    if (!$car_id) {
        wp_send_json_error('Invalid car ID');
    }
    
    global $wpdb;
    $table_name = $wpdb->prefix . 'g9x_timeslots';
    
    // Determine date range (e.g., next 60 days)
    $available_dates_raw = array();
    $start_date = new DateTime();
    $end_date = (new DateTime())->modify('+60 days'); // Look ahead 60 days
    $interval = new DateInterval('P1D');
    $period = new DatePeriod($start_date, $interval, $end_date);

    // Fetch dates marked as explicitly unavailable by the admin
    $unavailable_marked_dates = $this->get_unavailable_marked_dates($car_id); 

    // Fetch dates that are fully booked (all generated slots for that day are booked)
    // This requires knowing the potential slots for each day to compare against booked ones.
    // Simpler approach: Assume a date is available unless marked unavailable or has no bookable slots.
    // We will refine this by checking actual slot availability below.

    // Get the dynamic time ranges to know if *any* slots exist for a day
    $dynamic_ranges = $this->get_dynamic_time_ranges($car_id);
    if (empty($dynamic_ranges)) {
        wp_send_json_success([]); // No slots configured for this car
        return;
    }

    // Fetch all booked slots within the date range to check against generated slots
    $booked_slots_query = $wpdb->prepare(
        "SELECT booking_date, time_start, time_end 
         FROM $table_name 
         WHERE car_id = %d 
         AND booking_date BETWEEN %s AND %s 
         AND is_booked = 1",
        $car_id,
        $start_date->format('Y-m-d'),
        $end_date->format('Y-m-d')
    );
    $booked_slots_data = $wpdb->get_results($booked_slots_query, ARRAY_A);

    // Organize booked slots by date for quick lookup
    $booked_by_date = [];
    foreach ($booked_slots_data as $slot) {
        $booked_by_date[$slot['booking_date']][] = $slot['time_start'] . '-' . $slot['time_end'];
    }

    foreach ($period as $date) {
        $date_str = $date->format('Y-m-d');
        
        // Skip if date is explicitly marked as unavailable
        if (in_array($date_str, $unavailable_marked_dates)) {
            continue; 
        }

        // Check if there's at least one *bookable* slot for this date
        $has_available_slot = false;
        $booked_slots_today = isset($booked_by_date[$date_str]) ? $booked_by_date[$date_str] : [];

        foreach ($dynamic_ranges as $range) {
            $slot_key = $range[0] . '-' . $range[1];
            if (!in_array($slot_key, $booked_slots_today)) {
                $has_available_slot = true;
                break; // Found one available slot, no need to check further for this date
            }
        }

        if ($has_available_slot) {
             $available_dates_raw[] = $date_str;
        }
    }

    // Format dates for calendar
    $available_dates = array();
    foreach ($available_dates_raw as $date) {
        $available_dates[] = array(
            'date' => $date,
            'formatted' => date('Y-m-d', strtotime($date))
        );
    }
    
    wp_send_json_success($available_dates);
}

    /**
     * Get dates marked as unavailable from car's post meta.
     */
    private function get_unavailable_marked_dates($car_id) {
        $unavailable = get_post_meta($car_id, '_g9x_unavailable_dates', true);
        // Expecting an array of 'Y-m-d' strings
        return is_array($unavailable) ? $unavailable : [];
    }

    /**
     * Get dynamic time ranges from car's post meta or return defaults. (Made public for admin use)
     */
    public function get_dynamic_time_ranges($car_id) { // Changed from private to public
        $config = get_post_meta($car_id, '_g9x_timeslot_config', true);
        
        // Default configuration
        $defaults = [
            'start' => '09:00:00',
            'end' => '17:00:00',
            'interval' => 60 // minutes
        ];

        $config = wp_parse_args($config, $defaults);

        // Validate config values
        $start = preg_match('/^([01]\d|2[0-3]):([0-5]\d):([0-5]\d)$/', $config['start']) ? $config['start'] : $defaults['start'];
        $end = preg_match('/^([01]\d|2[0-3]):([0-5]\d):([0-5]\d)$/', $config['end']) ? $config['end'] : $defaults['end'];
        $interval = is_numeric($config['interval']) && $config['interval'] > 0 ? intval($config['interval']) : $defaults['interval'];

        if (strtotime($start) >= strtotime($end)) {
             error_log("G9X Booking: Invalid time range configuration for car ID $car_id (start >= end). Using defaults.");
             $start = $defaults['start'];
             $end = $defaults['end'];
        }

        return $this->generate_ranges($start, $end, $interval);
    }

    /**
     * Helper to generate time ranges based on start, end, and interval.
     */
    private function generate_ranges($start, $end, $interval_minutes) {
        $ranges = [];
        $current = strtotime($start);
        $end_time = strtotime($end);
        
        if ($current === false || $end_time === false || $interval_minutes <= 0) {
             error_log("G9X Booking: Invalid parameters for generate_ranges ($start, $end, $interval_minutes).");
            return [];
        }
        

        while ($current < $end_time) {
            $next = strtotime("+$interval_minutes minutes", $current);
            if ($next === false || $next > $end_time) break; // Don't exceed end time
            
            $ranges[] = [date('H:i:s', $current), date('H:i:s', $next)];
            $current = $next;
        }
        return $ranges;
    }

    /**
     * Release time slots associated with a booking when it's trashed or deleted.
     *
     * @param int $post_id The ID of the post being trashed or deleted.
     */
    public function release_booking_slots($post_id) {
        // Check if the post being acted upon is a booking
        if (get_post_type($post_id) !== 'g9x_booking') {
            return; // Not a booking, do nothing
        }

        // Get the timeslot IDs associated with this booking
        $timeslot_ids = get_post_meta($post_id, 'g9x_timeslot_ids', true);

        if (!empty($timeslot_ids) && is_array($timeslot_ids)) {
            global $wpdb;
            $table_name = $wpdb->prefix . 'g9x_timeslots';

            // Sanitize IDs just in case
            $sanitized_ids = array_map('intval', $timeslot_ids);
            $ids_placeholder = implode(',', array_fill(0, count($sanitized_ids), '%d'));

            // Prepare the SQL query to update the slots
            $sql = $wpdb->prepare(
                "UPDATE $table_name
                 SET is_booked = 0, booking_id = 0
                 WHERE id IN ($ids_placeholder)",
                $sanitized_ids
            );

            // Execute the update query
            $wpdb->query($sql);
            
            // Optional: Log this action
            // error_log("G9X Booking: Released slots for trashed/deleted booking ID $post_id. Slots: " . implode(', ', $sanitized_ids));
        }
    }

    /**
     * Get available time slots for a car on a specific date (Dynamically Generated)
     */
    public function get_available_timeslots() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'g9x_booking_nonce')) {
            wp_send_json_error('Invalid nonce');
        }
        
        $car_id = isset($_POST['car_id']) ? intval($_POST['car_id']) : 0;
        $date = isset($_POST['date']) ? sanitize_text_field($_POST['date']) : '';
        
        if (!$car_id || !$date || !wp_checkdate(intval(substr($date, 5, 2)), intval(substr($date, 8, 2)), intval(substr($date, 0, 4)), $date)) {
            wp_send_json_error('Invalid parameters');
        }

        // Check if the selected date is marked as unavailable
        if (in_array($date, $this->get_unavailable_marked_dates($car_id))) {
             wp_send_json_success([]); // Send empty array if date is unavailable
             return;
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'g9x_timeslots';

        // 1. Generate potential slots based on dynamic config
        $dynamic_ranges = $this->get_dynamic_time_ranges($car_id);
        if (empty($dynamic_ranges)) {
             wp_send_json_success([]); // No slots configured for this car
             return;
        }

        // 2. Get *booked* slots from the database for this car and date
        $booked_slots_info = $wpdb->get_results($wpdb->prepare(
            "SELECT id, time_start, time_end
             FROM $table_name
             WHERE car_id = %d AND booking_date = %s AND is_booked = 1",
            $car_id, $date
        )); // Get as array of objects

        // 3. Get specifically unavailable slots from admin settings
        $specifically_unavailable_slots_all = get_post_meta($car_id, '_g9x_unavailable_slots', true);
        $unavailable_today = [];
        if (is_array($specifically_unavailable_slots_all) && isset($specifically_unavailable_slots_all[$date])) {
            $unavailable_today = $specifically_unavailable_slots_all[$date]; // Array of 'HH:MM:SS-HH:MM:SS' strings
        }

        // 4. Create the list of slots, marking booked or specifically unavailable ones
        $formatted_slots = [];
        // Temporary ID generation for slots that don't exist in DB yet.
        // Format: "dynamic_<car_id>_<date>_<H:i:s_start>_<H:i:s_end>" (Using underscores)
        // This ID needs to be parsed later during booking if the slot needs to be inserted.
        
        foreach ($dynamic_ranges as $range) {
            $time_start = $range[0];
            $time_end = $range[1];
            $is_booked_in_db = false;
            $is_marked_unavailable = false;
            $slot_id = "dynamic_{$car_id}_{$date}_{$time_start}_{$time_end}"; // Default dynamic ID

            // Check if this generated slot matches a booked slot in the DB
            foreach ($booked_slots_info as $booked_slot) {
                if ($booked_slot->time_start == $time_start && $booked_slot->time_end == $time_end) {
                    $is_booked_in_db = true;
                    $slot_id = $booked_slot->id; // Use the actual DB ID if booked
                    break;
                }
            }

            // Check if this generated slot is marked as specifically unavailable
            $slot_key = $time_start . '-' . $time_end;
            if (in_array($slot_key, $unavailable_today)) {
                $is_marked_unavailable = true;
            }

            // Determine final availability status
            $is_available = !$is_booked_in_db && !$is_marked_unavailable;
            
            $formatted_time = date('h:i A', strtotime($time_start)) . ' - ' . date('h:i A', strtotime($time_end));
            $formatted_slots[] = array(
                'id' => $slot_id, // Can be integer (DB ID) or string (dynamic ID)
                'time' => $formatted_time,
                'is_booked' => !$is_available, // Slot is considered "booked" if it's booked in DB OR marked unavailable
                'is_available' => $is_available // Add explicit availability flag for potential frontend use
            );
        }
        
        wp_send_json_success($formatted_slots);
    }
    
    /**
     * Reserve a time slot - Sets is_booked=1, booking_id=0. Handles potential insertion.
     */
    public function reserve_timeslot($timeslot_id, $car_id = null, $date = null, $time_start = null, $time_end = null) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'g9x_timeslots';

        // Check if timeslot_id is a dynamic ID string or a database ID integer
        if (is_numeric($timeslot_id)) {
            // Assume it's a database ID, try to update
            $updated = $wpdb->update(
                $table_name,
                array( // Data to update
                    'is_booked' => 1,
                    'booking_id' => 0 // Ensure booking_id is 0 on reservation
                ), // End data array
                array(
                    'id' => $timeslot_id, 
                    'is_booked' => 0 // Prevent double booking race condition
                ),
                array('%d', '%d'), // Format for SET
                array('%d', '%d')  // Format for WHERE
            );
            
            if ($updated) {
                return $timeslot_id; // Return the ID on successful update
            } else {
                 // Update failed. Maybe already booked, or ID doesn't exist?
                 $existing = $this->get_timeslot($timeslot_id);
                 if ($existing && $existing->is_booked) {
                      error_log("G9X Booking: Attempted to double book timeslot ID: $timeslot_id");
                      return false; // Indicate failure (already booked)
                 }
                 // If it doesn't exist, maybe it should have been an insert? Fall through possibility.
                 error_log("G9X Booking: Failed to update timeslot ID: $timeslot_id. Might not exist or already booked.");
                 // Consider falling through to insert logic if data is available, but safer to error for now.
                 return false; 
            }

        } elseif (is_string($timeslot_id) && strpos($timeslot_id, 'dynamic_') === 0) { // Check for underscore
            // It's a dynamic ID. We need to INSERT this slot into the database.
            // We require the extra parameters passed to this function.
            
            // Basic validation of extra parameters
            if (empty($car_id) || empty($date) || empty($time_start) || empty($time_end)) {
                 error_log("G9X Booking: Missing data required to insert dynamic timeslot. ID: $timeslot_id");
                 return false;
            }
            if (!wp_checkdate(intval(substr($date, 5, 2)), intval(substr($date, 8, 2)), intval(substr($date, 0, 4)), $date) ||
                 !preg_match('/^([01]\d|2[0-3]):([0-5]\d):([0-5]\d)$/', $time_start) ||
                 !preg_match('/^([01]\d|2[0-3]):([0-5]\d):([0-5]\d)$/', $time_end)) {
                 error_log("G9X Booking: Invalid data format for dynamic timeslot insertion. Date: $date, Start: $time_start, End: $time_end");
                 return false;
             }


            // Check if this exact slot was somehow booked and inserted by a concurrent request
            $already_exists = $wpdb->get_row($wpdb->prepare(
                "SELECT id, is_booked FROM $table_name WHERE car_id = %d AND booking_date = %s AND time_start = %s AND time_end = %s",
                $car_id, $date, $time_start, $time_end
            ));

            if ($already_exists) {
                if ($already_exists->is_booked == 0) {
                    // Exists but is not booked, try to update it
                     error_log("G9X Booking: Dynamic slot $timeslot_id already existed in DB (ID: {$already_exists->id}), attempting update.");
                    return $this->reserve_timeslot($already_exists->id); // Correct recursive call
                } else {
                    // Exists and is already booked
                    error_log("G9X Booking: Dynamic slot $timeslot_id corresponds to an already booked slot in DB (ID: {$already_exists->id}).");
                    return false; // Already booked
                }
            }

            // Insert the new timeslot record as booked
            $inserted = $wpdb->insert(
                $table_name,
                array(
                    'car_id' => $car_id,
                    'booking_date' => $date,
                    'time_start' => $time_start,
                    'time_end' => $time_end,
                    'is_booked' => 1,
                    'booking_id' => 0 // Ensure booking_id is 0 on initial insert
                ),
                array('%d', '%s', '%s', '%s', '%d', '%d') // Data formats
            );

            if ($inserted) {
                return $wpdb->insert_id; // Return the new database ID
            } else {
                error_log("G9X Booking: Failed to insert dynamic timeslot into database. Car: $car_id, Date: $date, Time: $time_start-$time_end. DB Error: " . $wpdb->last_error);
                return false; // Indicate failure
            }
        } else {
             error_log("G9X Booking: Invalid timeslot ID format received in book_timeslot: " . print_r($timeslot_id, true));
            return false; // Invalid ID format
        }
    }
    
    /**
     * Get time slot information from the database.
     * Note: This won't return info for purely dynamic (not yet booked) slots.
     */
    public function get_timeslot($timeslot_id) {
        // Only fetch numeric IDs from the database
        if (!is_numeric($timeslot_id) || $timeslot_id <= 0) {
            return null;
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'g9x_timeslots';
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE id = %d",
            $timeslot_id
        ));
    }

     /**
      * Parses a dynamic timeslot ID string using underscores as separators.
      * Format: "dynamic_<car_id>_<date>_<H:i:s_start>_<H:i:s_end>"
      * Returns an array with keys [car_id, date, time_start, time_end] or null if invalid.
      */
     public static function parse_dynamic_timeslot_id($dynamic_id) {
         if (!is_string($dynamic_id) || strpos($dynamic_id, 'dynamic_') !== 0) { // Check for underscore
             return null;
         }
         
         $parts = explode('_', $dynamic_id, 5); // Explode by underscore, limit 5 parts
         if (count($parts) !== 5 || $parts[0] !== 'dynamic') { // Expect 5 parts
              error_log("G9X Booking: Failed to parse dynamic ID structure (expecting 5 parts separated by _): $dynamic_id");
             return null;
         }
         
         // Assign parts
         $car_id = $parts[1];
         $date = $parts[2]; // Should be YYYY-MM-DD
         $time_start = $parts[3]; // Should be HH:MM:SS
         $time_end = $parts[4]; // Should be HH:MM:SS

         // Basic validation
         if (!is_numeric($car_id) ||
             !wp_checkdate(intval(substr($date, 5, 2)), intval(substr($date, 8, 2)), intval(substr($date, 0, 4)), $date) ||
             !preg_match('/^([01]\d|2[0-3]):([0-5]\d):([0-5]\d)$/', $time_start) ||
             !preg_match('/^([01]\d|2[0-3]):([0-5]\d):([0-5]\d)$/', $time_end)) {
              error_log("G9X Booking: Invalid data found after parsing dynamic ID: $dynamic_id");
             return null;
         }

         return [
             'car_id' => intval($car_id),
             'date' => $date,
             'time_start' => $time_start,
             'time_end' => $time_end,
         ];
     } // Added missing closing brace
    
        /**
         * Updates the booking_id for a list of successfully reserved timeslot DB IDs.
         *
         * @param array $db_ids Array of timeslot database IDs.
         * @param int $booking_id The final booking post ID.
         * @return bool|int Number of rows updated or false on error.
         */
        public function finalize_booking_on_slots($db_ids, $booking_id) {
            if (empty($db_ids) || !is_array($db_ids) || empty($booking_id)) {
                return false;
            }
            global $wpdb;
            $table_name = $wpdb->prefix . 'g9x_timeslots';
    
            // Ensure all IDs are integers
            $db_ids = array_map('intval', $db_ids);
            $ids_placeholder = implode(',', array_fill(0, count($db_ids), '%d'));
    
            $sql = $wpdb->prepare(
                "UPDATE $table_name SET booking_id = %d WHERE id IN ($ids_placeholder) AND is_booked = 1 AND booking_id = 0", // Extra safety checks
                array_merge([$booking_id], $db_ids)
            );
            
            $updated_count = $wpdb->query($sql);
    
            // Check if the number of updated rows matches the number of IDs provided
            if ($updated_count === false) {
                 error_log("G9X Booking: Database error finalizing booking ID $booking_id for slots: " . implode(',', $db_ids) . ". DB Error: " . $wpdb->last_error);
                 return false; // Indicate database error
            } elseif ($updated_count != count($db_ids)) {
                 error_log("G9X Booking: Mismatch finalizing booking ID $booking_id. Expected " . count($db_ids) . " updates, got $updated_count. Slot IDs: " . implode(',', $db_ids));
                 // This indicates a potential problem (e.g., some slots weren't in the expected state), but we return the count for now.
            }
            
            return $updated_count;
        }
    
        /**
         * Unreserves a timeslot (sets is_booked=0, booking_id=0). Used for rollback.
         *
         * @param int $db_id The database ID of the timeslot to unreserve.
         * @return bool True on success, false on failure.
         */
         public function unreserve_timeslot($db_id) {
             if (empty($db_id) || !is_numeric($db_id)) {
                 return false;
             }
             global $wpdb;
             $table_name = $wpdb->prefix . 'g9x_timeslots';
    
             $updated = $wpdb->update(
                 $table_name,
                 ['is_booked' => 0, 'booking_id' => 0], // Data to set
                 ['id' => intval($db_id)], // WHERE clause
                 ['%d', '%d'], // Format for data
                 ['%d'] // Format for WHERE
             );
    
             if ($updated === false) {
                  error_log("G9X Booking: Failed to unreserve timeslot ID: $db_id. DB Error: " . $wpdb->last_error);
                  return false;
             }
             // Returns number of rows updated (should be 1 or 0 if already unreserved)
             return $updated >= 0; // Return true if query executed without error
         }
}