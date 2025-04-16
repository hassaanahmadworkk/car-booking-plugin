jQuery(document).ready(function($) {

    // --- Unified Unavailability Management ---
    const datePickerInput = $('#g9x_unavailability_datepicker');
    const daySlotManager = $('#g9x_day_slot_manager');
    const wholeDayCheckbox = $('#g9x_unavailable_whole_day');
    const selectedDateLabel = $('#g9x_selected_date_label');
    const slotsContainer = $('#g9x_specific_slots_container');
    const hiddenFullDaysInput = $('#g9x_unavailable_dates_hidden');
    const hiddenSpecificSlotsInput = $('#g9x_unavailable_slots_hidden');
    const summaryContainer = $('#g9x_unavailability_summary'); // Get summary container

    // --- State Management ---
    let currentFullDaysUnavailable = hiddenFullDaysInput.val() ? hiddenFullDaysInput.val().split(', ').filter(Boolean) : []; // Filter empty strings
    let currentSpecificSlotsUnavailable = hiddenSpecificSlotsInput.val() ? hiddenSpecificSlotsInput.val().split(', ').filter(Boolean) : []; // Filter empty strings
    let currentlySelectedDate = null;

    // --- Helper Functions ---
    function updateFullDaysHiddenInput() {
        currentFullDaysUnavailable.sort();
        hiddenFullDaysInput.val(currentFullDaysUnavailable.join(', '));
        datePickerInput.datepicker('refresh'); // Refresh datepicker highlights
        updateSummary(); // Update summary display
    }

    function updateSpecificSlotsHiddenInput() {
        currentSpecificSlotsUnavailable.sort();
        hiddenSpecificSlotsInput.val(currentSpecificSlotsUnavailable.join(', '));
        datePickerInput.datepicker('refresh'); // Refresh datepicker highlights
        updateSummary(); // Update summary display
    }

    // --- Datepicker Initialization ---
    if (datePickerInput.length) {
        datePickerInput.datepicker({
            dateFormat: 'yy-mm-dd',
            minDate: 0,
            beforeShowDay: function(date) {
                const dateString = $.datepicker.formatDate('yy-mm-dd', date);
                const isFullDayUnavailable = currentFullDaysUnavailable.includes(dateString);
                const hasSpecificUnavailable = currentSpecificSlotsUnavailable.some(slot => slot.startsWith(dateString + ':'));
                
                let cssClass = '';
                if (isFullDayUnavailable) {
                    cssClass = 'g9x-admin-fullday-unavailable'; 
                } else if (hasSpecificUnavailable) {
                    cssClass = 'g9x-admin-partial-unavailable'; 
                }
                let tooltip = isFullDayUnavailable ? 'Whole day unavailable' : (hasSpecificUnavailable ? 'Some slots unavailable' : 'Available');
                
                return [true, cssClass, tooltip]; 
            },
            onSelect: function(dateText) {
                currentlySelectedDate = dateText;
                selectedDateLabel.text(dateText); 
                daySlotManager.show(); 
                loadSlotsForDate(dateText);
            }
        });
        
         // Note: CSS moved to g9x-admin-style.css
    }

    // --- AJAX Slot Loading ---
    function loadSlotsForDate(date) {
        slotsContainer.html('<p class="loading">Loading slots...</p>');
        wholeDayCheckbox.prop('disabled', true); 

        $.ajax({
            url: g9x_admin_data.ajax_url,
            type: 'POST',
            data: {
                action: 'g9x_get_admin_slots_for_date',
                nonce: g9x_admin_data.nonce,
                date: date,
                car_id: g9x_admin_data.car_id
            },
            success: function(response) {
                wholeDayCheckbox.prop('disabled', false); 
                if (response.success && response.data) {
                    wholeDayCheckbox.prop('checked', response.data.is_whole_day_unavailable);
                    renderSlots(response.data.slots, date);
                    toggleSlotContainerState(response.data.is_whole_day_unavailable);
                } else {
                    const message = response.data && response.data.message ? response.data.message : 'Error loading slots.';
                    slotsContainer.html('<p class="error">' + message + '</p>');
                }
            },
            error: function() {
                wholeDayCheckbox.prop('disabled', false);
                slotsContainer.html('<p class="error">Server error loading slots.</p>');
            }
        });
    }

    // --- Slot Rendering ---
    function renderSlots(slots, date) {
        if (slots.length === 0) {
             slotsContainer.html('<p>No time slots are configured for this car.</p>');
             return;
        }
        
        let html = '<p>Click slots to mark them as unavailable:</p>';
        slots.forEach(function(slot) {
            const slotIdentifier = date + ':' + slot.slot_key; 
            const isMarkedUnavailable = currentSpecificSlotsUnavailable.includes(slotIdentifier);
            const unavailableClass = isMarkedUnavailable ? ' unavailable' : '';
            
            html += `<div class="g9x-admin-slot${unavailableClass}" data-slot-identifier="${slotIdentifier}">
                        ${slot.formatted}
                     </div>`;
        });
        slotsContainer.html(html);
    }

    // --- Event Handlers ---

    // Toggle whole day availability
    wholeDayCheckbox.on('change', function() {
        const isChecked = $(this).prop('checked');
        const date = currentlySelectedDate;
        if (!date) return; 

        toggleSlotContainerState(isChecked); 
        const index = currentFullDaysUnavailable.indexOf(date);

        if (isChecked) {
            if (index === -1) currentFullDaysUnavailable.push(date);
            // Remove specific slots for this date
            currentSpecificSlotsUnavailable = currentSpecificSlotsUnavailable.filter(slot => !slot.startsWith(date + ':'));
            updateSpecificSlotsHiddenInput(); // Update hidden specific slots (which also calls updateSummary)
            slotsContainer.find('.g9x-admin-slot').removeClass('unavailable'); 
        } else {
            if (index > -1) currentFullDaysUnavailable.splice(index, 1);
        }
        updateFullDaysHiddenInput(); // Update hidden full days (which also calls updateSummary)
    });

    // Toggle specific slot availability
    slotsContainer.on('click', '.g9x-admin-slot', function() {
        if (wholeDayCheckbox.prop('checked')) return; 

        const $slot = $(this);
        const slotIdentifier = $slot.data('slot-identifier');

        if ($slot.hasClass('unavailable')) {
            $slot.removeClass('unavailable');
            const index = currentSpecificSlotsUnavailable.indexOf(slotIdentifier);
            if (index > -1) currentSpecificSlotsUnavailable.splice(index, 1);
        } else {
            $slot.addClass('unavailable');
            if (!currentSpecificSlotsUnavailable.includes(slotIdentifier)) currentSpecificSlotsUnavailable.push(slotIdentifier);
        }
        updateSpecificSlotsHiddenInput(); // Update hidden specific slots (which also calls updateSummary)
    });
    
    // Enable/disable specific slot container
    function toggleSlotContainerState(isWholeDayUnavailable) {
         if (isWholeDayUnavailable) {
             slotsContainer.css({'opacity': '0.5', 'pointer-events': 'none'}).attr('title', 'Specific slots cannot be managed when the whole day is unavailable.');
         } else {
             slotsContainer.css({'opacity': '1', 'pointer-events': 'auto'}).removeAttr('title');
         }
    }

    // --- Summary Update ---
    function updateSummary() {
        let summaryHtml = '';
        // Full days
        if (currentFullDaysUnavailable.length > 0) {
            summaryHtml += '<h5>Full Days Unavailable:</h5><dl>';
            let sortedFullDays = [...currentFullDaysUnavailable].sort(); 
            sortedFullDays.forEach(date => {
                summaryHtml += '<dt>' + date + '</dt>';
            });
            summaryHtml += '</dl>';
        } else {
            summaryHtml += '<p><em>No full days marked as unavailable.</em></p>';
        }

        // Specific slots
        if (currentSpecificSlotsUnavailable && currentSpecificSlotsUnavailable.length > 0) { // Add null/undefined check just in case
            summaryHtml += '<h5 style="margin-top: 15px;">Specific Slots Unavailable:</h5><dl>';
            let groupedSlots = {};
            let sortedSpecificSlots = [...currentSpecificSlotsUnavailable].sort();
            
            
            sortedSpecificSlots.forEach(item => {
                 const firstColonIndex = item.indexOf(':');
                 if (firstColonIndex > -1) { // Ensure colon exists
                     let date = item.substring(0, firstColonIndex);
                     let slot_key = item.substring(firstColonIndex + 1); // Get the rest of the string

                     // Basic validation of extracted parts (optional but good)
                     if (date.match(/^\d{4}-\d{2}-\d{2}$/) && slot_key.match(/^\d{2}:\d{2}:\d{2}-\d{2}:\d{2}:\d{2}$/)) {
                         if (!groupedSlots[date]) {
                             groupedSlots[date] = [];
                         }
                         groupedSlots[date].push(slot_key);
                     } else {
                          // console.error("Skipping item due to invalid date/slot_key format after split:", date, slot_key); // Keep commented
                     }
                 } else {
                     // console.error("Skipping item due to missing ':' separator:", item); // Keep commented
                 }
            });

            // Display grouped slots
            // Check if groupedSlots has any keys before proceeding
            if (Object.keys(groupedSlots).length > 0) {
                 Object.keys(groupedSlots).sort().forEach(date => {
                 summaryHtml += '<dt>' + date + '</dt>';
                 groupedSlots[date].sort((a, b) => { 
                      // Extract start times (HH:MM:SS) for comparison
                      let startA = a.split('-')[0];
                      let startB = b.split('-')[0];
                      return strtotime(startA) - strtotime(startB);
                 }).forEach(slot_key => {
                      let times = slot_key.split('-');
                      if (times.length === 2) {
                          let formatted_time = formatTime(times[0]) + ' - ' + formatTime(times[1]);
                          summaryHtml += '<dd>' + formatted_time + '</dd>';
                      } else {
                           // console.error("Skipping slot key due to incorrect format (expected 2 parts separated by '-'):", slot_key); // Keep commented
                      }
                 });
                 }); // End Object.keys loop
                 summaryHtml += '</dl>';
            } else {
                 // This case should ideally not happen if the initial check passed and grouping worked,
                 // but adding it for completeness.
                 // console.log("Grouped slots object is empty, cannot display specific slots."); // Keep commented for potential future debug
                 summaryHtml += '<p style="margin-top: 15px;"><em>Error processing specific slots data.</em></p>'; // Indicate potential processing error
            }
        } else {
            summaryHtml += '<p style="margin-top: 15px;"><em>No specific time slots marked as unavailable.</em></p>';
        }

        summaryContainer.html(summaryHtml);
    }
    
    // Helper to format HH:MM:SS to h:i A
    function formatTime(timeString) {
         if (!timeString) return '';
         let parts = timeString.split(':');
         if (parts.length < 2) return timeString; 
         let hours = parseInt(parts[0], 10);
         let minutes = parts[1];
         let ampm = hours >= 12 ? 'PM' : 'AM';
         hours = hours % 12;
         hours = hours ? hours : 12; 
         // Pad minutes if needed (though HH:MM:SS format usually has it)
         // minutes = minutes.length < 2 ? '0' + minutes : minutes; 
         return hours + ':' + minutes + ' ' + ampm;
    }
    
    // Helper to mimic PHP's strtotime for basic HH:MM:SS comparison
    function strtotime(timeString) {
        if (!timeString) return 0;
        let parts = timeString.split(':');
        if (parts.length < 3) return 0;
        return parseInt(parts[0], 10) * 3600 + parseInt(parts[1], 10) * 60 + parseInt(parts[2], 10);
    }

    // Initial summary load
    updateSummary(); 

});