<?php
// user/includes/beneficiary_display.php
if (!isset($member)) {
    return;
}
?>
<div class="row">
    <div class="col-md-8 mx-auto">
        <div class="dashboard-card">
            <div class="card-header" style="background: linear-gradient(135deg, #dc3545, #c82333);">
                <h5><i class="fas fa-heart me-2"></i>Current Beneficiary</h5>
            </div>
            <div class="card-body text-center">
                <?php if (empty($member['beneficiary_name'])): ?>
                    <div class="py-5">
                        <i class="fas fa-heart-broken fa-4x text-muted mb-3"></i>
                        <h5>No Beneficiary Set</h5>
                        <p class="text-muted">You haven't designated a beneficiary yet.</p>
                        <button class="btn btn-danger mt-3" data-bs-toggle="modal" data-bs-target="#updateBeneficiaryModal">
                            <i class="fas fa-plus me-2"></i>Add Beneficiary
                        </button>
                    </div>
                <?php else: ?>
                    <div class="beneficiary-card p-4" style="background: linear-gradient(135deg, #dc3545, #c82333); color: white; border-radius: 15px;">
                        <i class="fas fa-heart fa-4x mb-3"></i>
                        <h3 class="beneficiary-name"><?php echo htmlspecialchars($member['beneficiary_name']); ?></h3>
                        
                        <?php if (!empty($member['beneficiary_relation'])): ?>
                        <p class="mb-2">
                            <i class="fas fa-users me-2"></i>
                            <?php echo htmlspecialchars($member['beneficiary_relation']); ?>
                        </p>
                        <?php endif; ?>
                        
                        <?php if (!empty($member['beneficiary_age'])): ?>
                        <p class="mb-2">
                            <i class="fas fa-birthday-cake me-2"></i>
                            Age: <?php echo htmlspecialchars($member['beneficiary_age']); ?>
                        </p>
                        <?php endif; ?>
                        
                        <?php if (!empty($member['beneficiary_address'])): ?>
                        <p class="mb-2">
                            <i class="fas fa-map-marker-alt me-2"></i>
                            <?php echo htmlspecialchars($member['beneficiary_address']); ?>
                        </p>
                        <?php endif; ?>
                        
                        <?php if (!empty($member['beneficiary_contact'])): ?>
                        <p class="mb-3">
                            <i class="fas fa-phone-alt me-2"></i>
                            <?php echo htmlspecialchars($member['beneficiary_contact']); ?>
                        </p>
                        <?php endif; ?>
                        
                        <button class="btn btn-light mt-3" data-bs-toggle="modal" data-bs-target="#updateBeneficiaryModal">
                            <i class="fas fa-edit me-2"></i>Update Beneficiary
                        </button>
                    </div>
                    
                    <div class="alert alert-info mt-4">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>Note:</strong> Beneficiary updates require admin approval and will be reviewed within 3-5 business days.
                    </div>
                    
                    <div class="mt-4 text-start">
                        <h6><i class="fas fa-file-alt me-2"></i>Requirements for Beneficiary Designation:</h6>
                        <ul>
                            <li>Valid ID of beneficiary</li>
                            <li>Proof of relationship (birth certificate, marriage certificate, etc.)</li>
                            <li>Beneficiary's contact information</li>
                            <li>Notarized affidavit of designation (for non-immediate family)</li>
                        </ul>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

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