<?php
// user/includes/modals/payment_history.php
if (!isset($all_payments)) {
    $db = Database::getInstance();
    if (isset($member)) {
        $all_payments = getUserPayments($db, $member['member_id'], 100, 0);
    } else {
        $all_payments = [];
    }
}
?>
<!-- Payment History Modal -->
<div class="modal fade" id="paymentHistoryModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fas fa-history me-2"></i>Payment History</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <?php if (empty($all_payments)): ?>
                    <p class="text-muted text-center py-3">No payment history found.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Receipt #</th>
                                    <th>Amount</th>
                                    <th>Method</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($all_payments as $payment): ?>
                                <tr>
                                    <td><?php echo date('M d, Y', strtotime($payment['payment_date'])); ?></td>
                                    <td><?php echo htmlspecialchars($payment['receipt_number'] ?? 'N/A'); ?></td>
                                    <td class="fw-bold text-success">₱<?php echo number_format($payment['amount'], 2); ?></td>
                                    <td><?php echo ucfirst($payment['payment_method'] ?? 'cash'); ?></td>
                                    <td>
                                        <span class="badge bg-<?php 
                                            echo $payment['payment_status'] == 'confirmed' ? 'success' : 
                                                ($payment['payment_status'] == 'pending' ? 'warning' : 'danger'); 
                                        ?>">
                                            <?php echo ucfirst($payment['payment_status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <button class="btn btn-sm btn-outline-primary" onclick="downloadReceipt('<?php echo $payment['receipt_number']; ?>')">
                                            <i class="fas fa-download"></i>
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
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