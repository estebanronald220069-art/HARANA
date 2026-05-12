<?php
// user/includes/payment_list.php
if (!isset($payments)) {
    $payments = [];
}
?>
<div class="dashboard-card">
    <div class="card-body">
        <?php if (empty($payments)): ?>
            <div class="text-center py-5">
                <i class="fas fa-receipt fa-4x text-muted mb-3"></i>
                <h5>No Payment History</h5>
                <p class="text-muted">You haven't made any payments yet.</p>
            </div>
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
                        <?php foreach ($payments as $payment): ?>
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
            
            <?php if (isset($total_pages) && $total_pages > 1): ?>
            <nav class="mt-4">
                <ul class="pagination justify-content-center">
                    <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                        <a class="page-link" href="?page=<?php echo $page-1; ?>">Previous</a>
                    </li>
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                        <a class="page-link" href="?page=<?php echo $i; ?>"><?php echo $i; ?></a>
                    </li>
                    <?php endfor; ?>
                    <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                        <a class="page-link" href="?page=<?php echo $page+1; ?>">Next</a>
                    </li>
                </ul>
            </nav>
            <?php endif; ?>
            
            <div class="text-muted small mt-2">
                Showing <?php echo count($payments); ?> of <?php echo $total_payments ?? count($payments); ?> payments
            </div>
        <?php endif; ?>
    </div>
</div>