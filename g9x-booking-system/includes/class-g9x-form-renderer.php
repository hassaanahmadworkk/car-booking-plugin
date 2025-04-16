<?php
/**
 * Class to render booking form
 */
class G9X_Form_Renderer {
    
    /**
     * Render booking form shortcode
     */
    public function render_booking_form() {
        ob_start();
        
        // Get all cars
        $args = array(
            'post_type' => 'g9x_car',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'orderby' => 'title',
            'order' => 'ASC',
        );
        
        $cars = get_posts($args);
        
        // Include template
        include G9X_BOOKING_PATH . 'templates/booking-form.php';
        
        return ob_get_clean();
    }
}