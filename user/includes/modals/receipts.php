<?php
// user/includes/modals/receipts.php
if (!isset($all_payments)) {
    $db = Database::getInstance();
    if (isset($member)) {
        $all_payments = getUserPayments($db, $member['member_id'], 100, 0);
    } else {
        $all_payments = [];
    }
}
?>
<!-- Receipts Modal -->
<div class="modal fade" id="receiptsModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="fas fa-file-invoice me-2"></i>Download Receipts</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Select payment to download receipt:</p>
                <?php if (empty($all_payments)): ?>
                    <p class="text-muted text-center py-3">No receipts available.</p>
                <?php else: ?>
                    <div class="list-group">
                        <?php foreach ($all_payments as $payment): ?>
                        <a href="#" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center" 
                           onclick="downloadReceipt('<?php echo $payment['receipt_number']; ?>'); return false;">
                            <div>
                                <i class="fas fa-file-pdf me-2 text-danger"></i>
                                <?php echo date('M d, Y', strtotime($payment['payment_date'])); ?> - ₱<?php echo number_format($payment['amount'], 2); ?>
                            </div>
                            <i class="fas fa-download"></i>
                        </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <?php if (!empty($all_payments)): ?>
                <button type="button" class="btn btn-success" onclick="downloadAllReceipts()">
                    <i class="fas fa-download me-2"></i>Download All
                </button>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>