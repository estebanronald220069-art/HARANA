// assets/js/main.js
$(document).ready(function() {
    // Auto-hide alerts after 5 seconds
    setTimeout(function() {
        $('.alert').fadeOut('slow');
    }, 5000);
    
    // Confirm delete actions
    $('.delete-btn').click(function(e) {
        if (!confirm('Are you sure you want to delete this item?')) {
            e.preventDefault();
        }
    });
    
    // Format currency inputs
    $('.currency-input').on('input', function() {
        let value = $(this).val().replace(/[^0-9.]/g, '');
        $(this).val(value);
    });
});