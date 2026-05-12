<?php
// user/includes/modals/update_beneficiary.php
if (!isset($member)) {
    return;
}
?>
<!-- Update Beneficiary Modal -->
<div class="modal fade" id="updateBeneficiaryModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="fas fa-heart me-2"></i>Update Beneficiary</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="beneficiary.php">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    
                    <div class="mb-3">
                        <label class="form-label">Beneficiary Full Name</label>
                        <input type="text" class="form-control" name="beneficiary_name" 
                               value="<?php echo htmlspecialchars($member['beneficiary_name'] ?? ''); ?>" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Relationship</label>
                        <input type="text" class="form-control" name="beneficiary_relation" 
                               value="<?php echo htmlspecialchars($member['beneficiary_relation'] ?? ''); ?>" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Age</label>
                        <input type="number" class="form-control" name="beneficiary_age" 
                               value="<?php echo htmlspecialchars($member['beneficiary_age'] ?? ''); ?>">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Complete Address</label>
                        <input type="text" class="form-control" name="beneficiary_address" 
                               value="<?php echo htmlspecialchars($member['beneficiary_address'] ?? ''); ?>" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Contact Number</label>
                        <input type="text" class="form-control" name="beneficiary_contact" 
                               value="<?php echo htmlspecialchars($member['beneficiary_contact'] ?? ''); ?>">
                    </div>
                    
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        Beneficiary updates require admin approval and will be reviewed within 3-5 business days.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Submit for Approval</button>
                </div>
            </form>
        </div>
    </div>
</div>