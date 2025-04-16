jQuery(document).ready(function($) {
    // Variables to store selected values
    let selectedCarId = null;
    let selectedDate = null;
    let selectedTimeslotId = null;
    let availableDates = [];
    let selectedTimeslots = [];
    
    // Handle car selection
    $('#g9x-car-select').on('change', function() {
        selectedCarId = $(this).val();
        selectedDate = null;
        selectedTimeslotId = null;
        
        if (selectedCarId) {
            loadAvailableDates(selectedCarId);
            $('#g9x-date-container').show();
        } else {
            $('#g9x-date-container').hide();
            $('#g9x-timeslot-container').hide();
            $('#g9x-customer-details').hide();
        }
    });
    
    // Initialize datepicker (will be configured with available dates later)
    $('#g9x-date-picker').datepicker({
        dateFormat: 'yy-mm-dd',
        minDate: 0,
        beforeShowDay: function(date) {
            const dateString = $.datepicker.formatDate('yy-mm-dd', date);
            const available = $.inArray(dateString, availableDates) !== -1;
            return [available, available ? 'available' : ''];
        },
        onSelect: function(dateText) {
            selectedDate = dateText;
            if (selectedDate) {
                loadAvailableTimeslots(selectedCarId, selectedDate);
                $('#g9x-timeslot-container').show();
            } else {
                $('#g9x-timeslot-container').hide();
                $('#g9x-customer-details').hide();
            }
        }
    });
    
  // Replace the time slot selection handler with this
$(document).on('click', '.g9x-timeslot-option.available', function() {
    const slotId = $(this).data('id');
    
    if ($(this).hasClass('selected')) {
        // Deselect this slot
        $(this).removeClass('selected');
        
        // Remove it from selected timeslots
        const index = selectedTimeslots.indexOf(slotId);
        if (index > -1) {
            selectedTimeslots.splice(index, 1);
        }
    } else {
        // Add this slot to selection
        $(this).addClass('selected');
        selectedTimeslots.push(slotId);
    }
    
    // Sort selected timeslots by ID (which corresponds to chronological order)
    selectedTimeslots.sort(function(a, b) { return a - b; });
    
    // Show customer details section if at least one slot is selected
    if (selectedTimeslots.length > 0) {
        $('#g9x-customer-details').show();
        
        // Update booking summary
        updateBookingSummary();
    } else {
        $('#g9x-customer-details').hide();
    }
});


    // Handle form submission
    $('#g9x-booking-form').on('submit', function(e) {
        e.preventDefault();
        
        if (!selectedCarId || !selectedDate || selectedTimeslots.length === 0) {
            showMessage('Please complete your selection', 'error');
            return;
        }
        
        const customerName = $('#g9x-customer-name').val();
        const customerEmail = $('#g9x-customer-email').val();
        const customerPhone = $('#g9x-customer-phone').val();
        
        if (!customerName || !customerEmail) {
            showMessage('Please fill in all required fields', 'error');
            return;
        }
        
        processBooking(selectedCarId, selectedTimeslots, customerName, customerEmail, customerPhone);
    });
    
    // Add this function to update the booking summary
    function updateBookingSummary() {
        if (selectedTimeslots.length === 0) {
            $('#g9x-booking-summary').hide();
            return;
        }
        
        // Get time information for all selected slots
        let slotElements = selectedTimeslots.map(id => $(`.g9x-timeslot-option[data-id="${id}"]`));
        let firstSlot = slotElements[0].text().trim().split('-')[0].trim();
        let lastSlot = slotElements[slotElements.length-1].text().trim().split('-')[1].trim();
        
        // Display the time range
        $('#g9x-booking-time-range').text(`${firstSlot} to ${lastSlot}`);
        $('#g9x-booking-hours').text(selectedTimeslots.length);
        $('#g9x-booking-summary').show();
    }
    
    
    /**
     * Load available dates for the selected car
     */
    function loadAvailableDates(carId) {
        $.ajax({
            url: g9x_booking.ajax_url,
            type: 'POST',
            data: {
                action: 'g9x_get_available_dates',
                nonce: g9x_booking.nonce,
                car_id: carId
            },
            beforeSend: function() {
                // Reset datepicker
                $('#g9x-date-picker').val('').datepicker('refresh');
                availableDates = [];
            },
            success: function(response) {
                if (response.success) {
                    const dates = response.data;
                    
                    // Store available dates in format needed for datepicker
                    availableDates = dates.map(function(dateObj) {
                        return dateObj.formatted;
                    });
                    
                    // Refresh datepicker with new available dates
                    $('#g9x-date-picker').datepicker('refresh');
                    
                    if (availableDates.length === 0) {
                        showMessage('No available dates for this car', 'error');
                    }
                } else {
                    showMessage('Error loading dates: ' + response.data, 'error');
                }
            },
            error: function() {
                showMessage('Server error while loading dates', 'error');
            }
        });
    }
    
    /**
     * Load available time slots for the selected date
     */
    function loadAvailableTimeslots(carId, date) {
        $.ajax({
            url: g9x_booking.ajax_url,
            type: 'POST',
            data: {
                action: 'g9x_get_available_timeslots',
                nonce: g9x_booking.nonce,
                car_id: carId,
                date: date
            },
            beforeSend: function() {
                $('#g9x-timeslot-options').html('<p>Loading time slots...</p>');
            },
            success: function(response) {
                if (response.success) {
                    const timeslots = response.data;
                    let timeslotHtml = '';
                    
                    timeslots.forEach(function(slot) {
                        const statusClass = slot.is_booked ? 'booked' : 'available';
                        timeslotHtml += `
                            <div class="g9x-timeslot-option ${statusClass}" data-id="${slot.id}">
                                ${slot.time}
                                <span class="g9x-slot-status">${slot.is_booked ? '(Booked)' : ''}</span>
                            </div>
                        `;
                    });
                    
                    $('#g9x-timeslot-options').html(timeslotHtml);
                } else {
                    showMessage('Error loading time slots: ' + response.data, 'error');
                }
            },
            error: function() {
                showMessage('Server error while loading time slots', 'error');
            }
        });
    }
    
    /**
     * Process booking submission
     */
    function processBooking(carId, timeslotIds, customerName, customerEmail, customerPhone) {
        $.ajax({
            url: g9x_booking.ajax_url,
            type: 'POST',
            data: {
                action: 'g9x_process_booking',
                nonce: g9x_booking.nonce,
                car_id: carId,
                timeslot_ids: timeslotIds, // Now passing an array of IDs
                customer_name: customerName,
                customer_email: customerEmail,
                customer_phone: customerPhone
            },
            beforeSend: function() {
                $('#g9x-submit-booking').prop('disabled', true).text('Processing...');
            },
            success: function(response) {
                if (response.success) {
                    showMessage('Booking successful! Booking ID: ' + response.data.booking_id, 'success');
                    resetForm();
                } else {
                    showMessage('Error: ' + response.data, 'error');
                }
                $('#g9x-submit-booking').prop('disabled', false).text('Book Now');
            },
            error: function() {
                showMessage('Server error while processing booking', 'error');
                $('#g9x-submit-booking').prop('disabled', false).text('Book Now');
            }
        });
    }
    
    
    /**
     * Display message to user
     */
    function showMessage(message, type) {
        const messageContainer = $('#g9x-booking-form-messages');
        messageContainer.html(message).removeClass('success error').addClass(type);
    }
    
    /**
     * Reset form after successful booking
     */
    function resetForm() {
        $('#g9x-car-select').val('').trigger('change');
        $('#g9x-date-picker').val('');
        $('#g9x-customer-name').val('');
        $('#g9x-customer-email').val('');
        $('#g9x-customer-phone').val('');
        
        selectedCarId = null;
        selectedDate = null;
        selectedTimeslots = []; // Clear selected timeslots array
        availableDates = [];
        
        $('#g9x-date-container').hide();
        $('#g9x-timeslot-container').hide();
        $('#g9x-customer-details').hide();
        $('#g9x-booking-summary').hide();
    }
});