<?php
// user/includes/modals/update_profile.php
if (!isset($member)) {
    return;
}
?>
<!-- Update Profile Modal -->
<div class="modal fade" id="updateProfileModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fas fa-user-edit me-2"></i>Update Profile</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="profile.php">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="update_profile" value="1">
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Contact Number</label>
                            <input type="text" class="form-control" name="contact_number" 
                                   value="<?php echo htmlspecialchars($member['contact_number'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Alternate Number</label>
                            <input type="text" class="form-control" name="alternate_number" 
                                   value="<?php echo htmlspecialchars($member['alternate_number'] ?? ''); ?>">
                        </div>
                        <div class="col-12 mb-3">
                            <label class="form-label">Email</label>
                            <input type="email" class="form-control" name="email" 
                                   value="<?php echo htmlspecialchars($member['email'] ?? ''); ?>">
                        </div>
                        <div class="col-12 mb-3">
                            <label class="form-label">Present Address</label>
                            <input type="text" class="form-control" name="present_address" 
                                   value="<?php echo htmlspecialchars($member['present_address'] ?? ''); ?>">
                        </div>
                        <div class="col-12 mb-3">
                            <label class="form-label">Permanent Address</label>
                            <input type="text" class="form-control" name="permanent_address" 
                                   value="<?php echo htmlspecialchars($member['permanent_address'] ?? ''); ?>">
                        </div>
                    </div>
                    
                    <div class="alert alert-warning">
                        <i class="fas fa-info-circle me-2"></i>
                        Other profile changes require admin approval. Please contact the office for changes to your name, birth date, or civil status.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>