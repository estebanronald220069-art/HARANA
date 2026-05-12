<?php
// user/includes/financial_summary.php
if (!isset($member, $current_balance, $total_paid, $expected_total, $months_as_member, $payments, $next_due_date)) {
    return;
}
?>
<div class="dashboard-card">
    <div class="card-header" style="background: linear-gradient(135deg, #ff6b6b, #ee5a24);">
        <h5><i class="fas fa-chart-line me-2"></i>Financial Summary</h5>
        <i class="fas fa-wallet"></i>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-6">
                <div class="balance-card p-4 rounded text-white" style="background: linear-gradient(135deg, #667eea, #764ba2);">
                    <div class="balance-label small opacity-75">Current Balance</div>
                    <div class="balance-amount display-6 fw-bold">₱<?php echo number_format(abs($current_balance), 2); ?></div>
                    <div class="balance-status mt-2">
                        <?php if ($current_balance > 0): ?>
                            <span class="badge bg-warning text-dark"><i class="fas fa-exclamation-triangle me-1"></i> Balance Due</span>
                        <?php elseif ($current_balance < 0): ?>
                            <span class="badge bg-success"><i class="fas fa-check-circle me-1"></i> In Credit</span>
                        <?php else: ?>
                            <span class="badge bg-info"><i class="fas fa-check-circle me-1"></i> Fully Paid</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="row g-2">
                    <div class="col-6">
                        <div class="stat-item bg-light p-3 rounded text-center">
                            <div class="stat-value text-primary fw-bold">₱<?php echo number_format($member['monthly_contribution'] ?? 100, 2); ?></div>
                            <div class="stat-label small text-muted">Monthly</div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="stat-item bg-light p-3 rounded text-center">
                            <div class="stat-value text-success fw-bold">₱<?php echo number_format($total_paid, 2); ?></div>
                            <div class="stat-label small text-muted">Total Paid</div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="stat-item bg-light p-3 rounded text-center">
                            <div class="stat-value text-info fw-bold">₱<?php echo number_format($expected_total, 2); ?></div>
                            <div class="stat-label small text-muted">Expected</div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="stat-item bg-light p-3 rounded text-center">
                            <div class="stat-value text-warning fw-bold"><?php echo $months_as_member; ?></div>
                            <div class="stat-label small text-muted">Months</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Recent Payments -->
        <h6 class="mt-4 mb-3 fw-bold"><i class="fas fa-clock me-2 text-primary"></i>Recent Payments</h6>
        
        <?php if (empty($payments)): ?>
            <p class="text-muted text-center py-3">No payment history yet.</p>
        <?php else: ?>
            <?php foreach ($payments as $payment): ?>
            <div class="payment-item d-flex justify-content-between align-items-center py-2 border-bottom">
                <div>
                    <div class="payment-date fw-bold"><?php echo date('M d, Y', strtotime($payment['payment_date'])); ?></div>
                    <small class="text-muted"><?php echo $payment['receipt_number'] ?? 'No receipt'; ?></small>
                </div>
                <div class="text-end">
                    <div class="payment-amount text-success fw-bold">₱<?php echo number_format($payment['amount'], 2); ?></div>
                    <span class="badge bg-<?php echo $payment['payment_status'] == 'confirmed' ? 'success' : 'warning'; ?>">
                        <?php echo ucfirst($payment['payment_status']); ?>
                    </span>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
        
        <div class="text-center mt-3">
            <a href="payments.php" class="btn btn-outline-primary btn-sm">
                <i class="fas fa-list me-2"></i>View All Payments
            </a>
        </div>
        
        <!-- Next Due Date -->
        <div class="alert alert-warning mt-4 mb-0">
            <i class="fas fa-calendar-exclamation me-2"></i>
            <strong>Next Due Date:</strong> <?php echo date('F j, Y', strtotime($next_due_date ?? date('Y-m-d', strtotime('first day of next month')))); ?>
        </div>
    </div>
</div>