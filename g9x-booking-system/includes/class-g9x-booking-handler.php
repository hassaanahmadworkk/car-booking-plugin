<?php
/**
 * Class to handle booking submissions
 */
class G9X_Booking_Handler {
    
    /**
     * Constructor
     */
    public function __construct() {
        add_action('wp_ajax_g9x_process_booking', array($this, 'process_booking'));
        add_action('wp_ajax_nopriv_g9x_process_booking', array($this, 'process_booking'));
    }
    
   /**
 * Process booking submission - Refactored for Dynamic Timeslots & Two-Phase Booking
 */
public function process_booking() {
    // 1. Verify Nonce & Get Form Data
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'g9x_booking_nonce')) {
        wp_send_json_error('Security check failed');
    }
    
    $car_id = isset($_POST['car_id']) ? intval($_POST['car_id']) : 0;
    // Sanitize incoming IDs, allowing strings
    $original_timeslot_ids = isset($_POST['timeslot_ids']) && is_array($_POST['timeslot_ids']) 
                             ? array_map('sanitize_text_field', $_POST['timeslot_ids']) 
                             : array();
    $customer_name = isset($_POST['customer_name']) ? sanitize_text_field($_POST['customer_name']) : '';
    $customer_email = isset($_POST['customer_email']) ? sanitize_email($_POST['customer_email']) : '';
    $customer_phone = isset($_POST['customer_phone']) ? sanitize_text_field($_POST['customer_phone']) : '';
    
    // Validate required fields
    if (!$car_id || empty($original_timeslot_ids) || empty($customer_name) || empty($customer_email)) {
        wp_send_json_error('Please fill all required fields');
    }
    
    // 2. Instantiate Manager & Prepare Data Structures
    $timeslot_manager = new G9X_Timeslot_Manager();
    $validated_slots_data = []; // Will store validated slot objects/arrays
    global $wpdb;
    $table_name = $wpdb->prefix . 'g9x_timeslots';

    // 3. Validate Each Timeslot ID
    foreach ($original_timeslot_ids as $id) {
        $slot_data = null;
        $is_dynamic = false;

        if (is_numeric($id)) {
            // --- Handle Numeric DB ID ---
            $slot_object = $timeslot_manager->get_timeslot($id);
            
            if (!$slot_object) {
                wp_send_json_error("Invalid time slot ID: $id");
            }
            if ($slot_object->is_booked) {
                wp_send_json_error('One or more selected time slots are no longer available (already booked).');
            }
             if ($slot_object->car_id != $car_id) {
                 wp_send_json_error('Timeslot does not belong to the selected car.');
             }
            $slot_data = (array) $slot_object; // Convert to array for consistency

        } elseif (is_string($id) && strpos($id, 'dynamic_') === 0) { // Check for underscore
            // --- Handle Dynamic String ID ---
            $is_dynamic = true;
            $parsed_data = G9X_Timeslot_Manager::parse_dynamic_timeslot_id($id);

            if (!$parsed_data) {
                wp_send_json_error("Invalid dynamic time slot format: $id");
            }
            if ($parsed_data['car_id'] != $car_id) {
                 wp_send_json_error('Dynamic timeslot data does not match the selected car.');
            }

            // Check for race condition: Has this exact slot been booked since the form was loaded?
            $existing_booked = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table_name WHERE car_id = %d AND booking_date = %s AND time_start = %s AND time_end = %s AND is_booked = 1",
                $parsed_data['car_id'], $parsed_data['date'], $parsed_data['time_start'], $parsed_data['time_end']
            ));

            if ($existing_booked) {
                 wp_send_json_error('One or more selected time slots were booked while you were completing the form.');
            }

            // Construct a consistent data structure for the dynamic slot
            $slot_data = [
                'id'           => $id, // Keep original dynamic ID for now
                'car_id'       => $parsed_data['car_id'],
                'booking_date' => $parsed_data['date'],
                'time_start'   => $parsed_data['time_start'],
                'time_end'     => $parsed_data['time_end'],
                'is_booked'    => 0, // Mark as not booked yet
                'booking_id'   => 0,
                '_is_dynamic'  => true // Flag to easily identify later
            ];
            
        } else {
            wp_send_json_error("Invalid time slot identifier: $id");
        }

        // Add the validated data (as an array) to our list
        if ($slot_data) {
             // Ensure all required keys exist for validation consistency
             $slot_data['car_id'] = isset($slot_data['car_id']) ? $slot_data['car_id'] : null;
             $slot_data['booking_date'] = isset($slot_data['booking_date']) ? $slot_data['booking_date'] : null;
             $slot_data['time_start'] = isset($slot_data['time_start']) ? $slot_data['time_start'] : null;
             $slot_data['time_end'] = isset($slot_data['time_end']) ? $slot_data['time_end'] : null;
             $slot_data['_is_dynamic'] = isset($slot_data['_is_dynamic']) ? $slot_data['_is_dynamic'] : false;

            $validated_slots_data[] = $slot_data;
        }
    }

    // 4. Verify Consecutive Timeslots
    if (!$this->validate_consecutive_timeslots($validated_slots_data)) {
        // Error message is sent within the function
        return; 
    }
    
    // 5. Reserve Timeslots & Collect DB IDs
    $reserved_db_ids = []; // Store IDs of slots successfully reserved
    $reservation_failed = false;
    
    // Optional: Start transaction
    // $wpdb->query('START TRANSACTION');

    foreach ($validated_slots_data as $slot) {
        $db_id = false;
        if ($slot['_is_dynamic']) {
            // Pass all necessary data for potential insertion
            $db_id = $timeslot_manager->reserve_timeslot(
                $slot['id'], // The dynamic ID string
                $slot['car_id'],
                $slot['booking_date'],
                $slot['time_start'],
                $slot['time_end']
            );
        } else {
            // Pass only the numeric DB ID
            $db_id = $timeslot_manager->reserve_timeslot($slot['id']);
        }

        if ($db_id === false || $db_id === 0) {
            $reservation_failed = true; // Mark reservation as failed
            error_log("G9X Booking: Failed to reserve timeslot: " . print_r($slot, true));
            break; // Stop processing further slots
        }
        
        $reserved_db_ids[] = $db_id; // Collect the actual DB ID (new or existing)
    }

    // 6. Handle Reservation Failure (Rollback)
    if ($reservation_failed) {
        // Rollback: Unreserve any slots that were successfully reserved before the failure
        if (!empty($reserved_db_ids)) {
            error_log("G9X Booking: Reservation failed. Rolling back reserved slots: " . implode(',', $reserved_db_ids));
            foreach ($reserved_db_ids as $id_to_unreserve) {
                $timeslot_manager->unreserve_timeslot($id_to_unreserve);
            }
        }
        // Optional: Rollback transaction if started
        // $wpdb->query('ROLLBACK');
        wp_send_json_error('Failed to reserve one or more time slots. They may have been booked by someone else. Please refresh and try again.'); 
        return;
    }

    // 7. Create Booking Post (using reserved DB IDs)
    $booking_id = $this->create_booking(
        $car_id,
        $validated_slots_data, // Pass original validated data for details like date/time range
        $customer_name,
        $customer_email,
        $customer_phone,
        $reserved_db_ids // Pass the actual DB IDs to store in meta
    );
    
    if (!$booking_id || is_wp_error($booking_id)) {
         // Rollback: Unreserve the slots as booking post creation failed
         error_log("G9X Booking: Booking post creation failed. Rolling back reserved slots: " . implode(',', $reserved_db_ids));
         foreach ($reserved_db_ids as $id_to_unreserve) {
             $timeslot_manager->unreserve_timeslot($id_to_unreserve);
         }
         // Optional: Rollback transaction if started
         // $wpdb->query('ROLLBACK');
         error_log("G9X Booking: Failed to create booking post. DB IDs: " . implode(',', $reserved_db_ids) . " Error: " . ($booking_id instanceof WP_Error ? $booking_id->get_error_message() : 'Unknown'));
         wp_send_json_error('Failed to create booking record.');
         return;
    }

    // 8. Finalize Booking: Update reserved timeslots with the actual booking_id
    $finalized_count = $timeslot_manager->finalize_booking_on_slots($reserved_db_ids, $booking_id);

    if ($finalized_count === false || $finalized_count != count($reserved_db_ids)) {
         // Rollback: Finalization failed, potentially delete booking post and unreserve slots
         error_log("G9X Booking: Finalization failed or incomplete. Rolling back. Booking ID: $booking_id, Expected: " . count($reserved_db_ids) . ", Finalized: " . ($finalized_count === false ? 'Error' : $finalized_count));
         // Attempt to unreserve slots
         foreach ($reserved_db_ids as $id_to_unreserve) {
             $timeslot_manager->unreserve_timeslot($id_to_unreserve);
         }
         // Attempt to delete the booking post
         wp_delete_post($booking_id, true); // Force delete
         // Optional: Rollback transaction if started
         // $wpdb->query('ROLLBACK');

         wp_send_json_error('Failed to finalize booking details. Booking cancelled. Please contact support.');
         return;
    }


    // 9. Return Success
    // Optional: Commit transaction
    // $wpdb->query('COMMIT');
    
    wp_send_json_success(array(
        'message' => 'Booking successful!',
        'booking_id' => $booking_id
    ));
}
 
/**
 * Validate that timeslots are consecutive.
 * Accepts an array of slot data arrays/objects.
 */
private function validate_consecutive_timeslots($timeslots_data) {
    if (empty($timeslots_data)) {
        wp_send_json_error('No timeslots selected');
        return false;
    }
    
    // Convert to objects if they are arrays for consistent access
    $timeslots = array_map(function($item) {
        return is_object($item) ? $item : (object)$item;
    }, $timeslots_data);

    // Check that all slots are for the same car and date
    $car_id = $timeslots[0]->car_id;
    $date = $timeslots[0]->booking_date;
    
    foreach ($timeslots as $slot) {
         if (!isset($slot->car_id, $slot->booking_date, $slot->time_start, $slot->time_end)) {
              error_log("G9X Booking: Inconsistent slot data in validate_consecutive_timeslots: " . print_r($slot, true));
              wp_send_json_error('Internal error: Inconsistent timeslot data.');
              return false;
         }
        if ($slot->car_id != $car_id || $slot->booking_date != $date) {
            wp_send_json_error('Selected timeslots must be for the same car and date');
            return false;
        }
    }
    
    // Sort timeslots by start time
    usort($timeslots, function($a, $b) {
        return strtotime($a->time_start) - strtotime($b->time_start);
    });
    
    // Check that slots are consecutive
    for ($i = 0; $i < count($timeslots) - 1; $i++) {
        $current_end = strtotime($timeslots[$i]->time_end);
        $next_start = strtotime($timeslots[$i+1]->time_start);
        
        if ($current_end === false || $next_start === false || $current_end != $next_start) {
            wp_send_json_error('Selected timeslots must be consecutive');
            return false;
        }
    }
    
    return true;
}
 
/**
 * Create booking post
 * @param int $car_id
 * @param array $validated_slots_data Array of validated slot data (arrays/objects)
 * @param string $customer_name
 * @param string $customer_email
 * @param string $customer_phone
 * @param array $final_db_ids Array of actual database IDs for the booked slots
 * @return int|WP_Error Booking Post ID or WP_Error on failure
 */
private function create_booking($car_id, $validated_slots_data, $customer_name, $customer_email, $customer_phone, $final_db_ids) {
    $car = get_post($car_id);
    if (!$car) {
        return new WP_Error('invalid_car', 'Invalid Car ID provided for booking.');
    }
    
    // Ensure slots data is not empty and sort by time to get range
    if (empty($validated_slots_data)) {
         return new WP_Error('no_slots', 'No timeslot data provided for booking creation.');
    }

    // Convert to objects if needed and sort
     $timeslots = array_map(function($item) { return is_object($item) ? $item : (object)$item; }, $validated_slots_data);
     usort($timeslots, function($a, $b) { return strtotime($a->time_start) - strtotime($b->time_start); });

    
    $booking_date = date('Y-m-d', strtotime($timeslots[0]->booking_date));
    $time_start = date('h:i A', strtotime($timeslots[0]->time_start));
    $time_end = date('h:i A', strtotime(end($timeslots)->time_end));
    
    // Create title for the booking
    $booking_title = "Booking: {$car->post_title} - {$booking_date} ({$time_start} - {$time_end})";
    
    // Create booking post
    $booking_data = array(
        'post_title'    => $booking_title,
        'post_status'   => 'publish', // Or 'pending' for review?
        'post_type'     => 'g9x_booking',
    );
    
    $booking_id = wp_insert_post($booking_data, true); // Pass true to return WP_Error on failure
    
    if (is_wp_error($booking_id)) {
        error_log("G9X Booking: wp_insert_post failed: " . $booking_id->get_error_message());
        return $booking_id; // Return the error object
    }
    
    if ($booking_id) {
        // Add booking meta data
        update_post_meta($booking_id, 'g9x_car_id', $car_id);
        
        // Store the FINAL database timeslot IDs
        update_post_meta($booking_id, 'g9x_timeslot_ids', $final_db_ids); 
        
        update_post_meta($booking_id, 'g9x_booking_date', $booking_date);
        update_post_meta($booking_id, 'g9x_time_start', $time_start);
        update_post_meta($booking_id, 'g9x_time_end', $time_end);
        update_post_meta($booking_id, 'g9x_customer_name', $customer_name);
        update_post_meta($booking_id, 'g9x_customer_email', $customer_email);
        update_post_meta($booking_id, 'g9x_customer_phone', $customer_phone);
        
        // Also add booking reference to the car post for easy lookup (optional)
        // Consider if this meta field could become very large.
        // $car_bookings = get_post_meta($car_id, 'g9x_bookings', true);
        // if (!is_array($car_bookings)) {
        //     $car_bookings = array();
        // }
        // $car_bookings[] = $booking_id;
        // update_post_meta($car_id, 'g9x_bookings', $car_bookings);
    }
    
    return $booking_id; // Return the integer ID
}
}