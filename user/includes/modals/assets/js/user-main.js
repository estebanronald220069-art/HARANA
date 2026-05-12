// Initialize AOS if available
if (typeof AOS !== 'undefined') {
    AOS.init({
        duration: 1000,
        once: true,
        offset: 50
    });
}

// Sidebar toggle for mobile
document.addEventListener('DOMContentLoaded', function() {
    const sidebarToggle = document.getElementById('sidebarToggle');
    if (sidebarToggle) {
        sidebarToggle.addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('show');
        });
    }
    
    // Close sidebar when clicking outside on mobile
    document.addEventListener('click', function(event) {
        const sidebar = document.getElementById('sidebar');
        const toggle = document.getElementById('sidebarToggle');
        
        if (window.innerWidth < 768 && sidebar && sidebar.classList.contains('show') && 
            !sidebar.contains(event.target) && toggle && !toggle.contains(event.target)) {
            sidebar.classList.remove('show');
        }
    });
});

// Download receipt function
function downloadReceipt(receiptNumber) {
    if (!receiptNumber) {
        alert('No receipt number specified');
        return;
    }
    alert('Downloading receipt: ' + receiptNumber + '\n(In a real system, this would download the PDF receipt)');
    // In production: window.location.href = 'download_receipt.php?receipt=' + receiptNumber;
}

// Download all receipts
function downloadAllReceipts() {
    alert('Downloading all receipts as ZIP file\n(In a real system, this would download a ZIP file with all receipts)');
    // In production: window.location.href = 'download_receipt.php?all=1';
}

// Handle form submissions
document.querySelectorAll('form').forEach(form => {
    form.addEventListener('submit', function(e) {
        const submitBtn = this.querySelector('button[type="submit"]');
        if (submitBtn) {
            submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Processing...';
            submitBtn.disabled = true;
        }
    });
});

// Print function
function printCertificate() {
    window.print();
}

// Auto-hide alerts after 5 seconds
setTimeout(function() {
    document.querySelectorAll('.alert').forEach(function(alert) {
        var bsAlert = new bootstrap.Alert(alert);
        bsAlert.close();
    });
}, 5000);