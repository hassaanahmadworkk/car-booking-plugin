<?php
/**
 * Template for booking form
 */
?>
<div class="g9x-booking-form-container">
    <div id="g9x-booking-form-messages"></div>
    
    <form id="g9x-booking-form">
        <div class="g9x-form-group">
            <label for="g9x-car-select">Select Car:</label>
            <select id="g9x-car-select" name="car_id" required>
                <option value="">-- Select a Car --</option>
                <?php foreach ($cars as $car) : ?>
                    <option value="<?php echo esc_attr($car->ID); ?>"><?php echo esc_html($car->post_title); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div class="g9x-form-group" id="g9x-date-container" style="display: none;">
            <label for="g9x-date-picker">Select Date:</label>
            <input type="text" id="g9x-date-picker" name="booking_date" readonly required>
            <p class="description">Only dates with available slots are selectable</p>
        </div>
        
        <div class="g9x-form-group" id="g9x-timeslot-container" style="display: none;">
    <label for="g9x-timeslot-select">Select Time Slots:</label>
    <p class="description">Click on multiple consecutive time slots to book for longer durations</p>
    <div id="g9x-timeslot-options">
        <!-- Time slot options will be populated via AJAX -->
    </div>
</div>
        <div class="g9x-form-group" id="g9x-booking-summary" style="display: none;">
    <h3>Booking Summary</h3>
    <p>Time Range: <span id="g9x-booking-time-range"></span></p>
    <p>Total Hours: <span id="g9x-booking-hours"></span></p>
</div>
        
        <div id="g9x-customer-details" style="display: none;">
            <h3>Customer Details</h3>
            
            <div class="g9x-form-group">
                <label for="g9x-customer-name">Name:</label>
                <input type="text" id="g9x-customer-name" name="customer_name" required>
            </div>
            
            <div class="g9x-form-group">
                <label for="g9x-customer-email">Email:</label>
                <input type="email" id="g9x-customer-email" name="customer_email" required>
            </div>
            
            <div class="g9x-form-group">
                <label for="g9x-customer-phone">Phone:</label>
                <input type="tel" id="g9x-customer-phone" name="customer_phone">
            </div>
            
            <div class="g9x-form-group">
                <button type="submit" id="g9x-submit-booking">Book Now</button>
            </div>
        </div>
    </form>
</div>