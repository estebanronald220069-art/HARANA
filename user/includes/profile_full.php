<?php
// user/includes/profile_full.php
if (!isset($member)) {
    return;
}
$initials = strtoupper(substr($member['first_name'] ?? '', 0, 1) . substr($member['last_name'] ?? '', 0, 1));
?>
<div class="dashboard-card">
    <div class="card-body">
        <div class="row">
            <div class="col-md-4 text-center mb-4">
                <div class="profile-avatar" style="width: 150px; height: 150px; font-size: 4rem; margin: 0 auto;">
                    <?php echo $initials ?: 'U'; ?>
                </div>
                <h4 class="mt-3"><?php echo htmlspecialchars(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? '')); ?></h4>
                <p class="text-muted">Member Code: <?php echo htmlspecialchars($member['member_code'] ?? 'N/A'); ?></p>
                <span class="badge bg-<?php echo ($member['status'] ?? 'active') == 'active' ? 'success' : 'warning'; ?>">
                    <?php echo ucfirst($member['status'] ?? 'active'); ?>
                </span>
            </div>
            <div class="col-md-8">
                <form method="POST" action="profile.php">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="update_profile" value="1">
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">First Name</label>
                            <input type="text" class="form-control" value="<?php echo htmlspecialchars($member['first_name'] ?? ''); ?>" readonly disabled>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Last Name</label>
                            <input type="text" class="form-control" value="<?php echo htmlspecialchars($member['last_name'] ?? ''); ?>" readonly disabled>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Middle Name</label>
                            <input type="text" class="form-control" value="<?php echo htmlspecialchars($member['middle_name'] ?? ''); ?>" readonly disabled>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Gender</label>
                            <input type="text" class="form-control" value="<?php echo htmlspecialchars($member['gender'] ?? ''); ?>" readonly disabled>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Birth Date</label>
                            <input type="text" class="form-control" value="<?php 
                                echo !empty($member['birth_date']) && $member['birth_date'] != '0000-00-00' ? 
                                    date('F j, Y', strtotime($member['birth_date'])) : 'N/A'; 
                            ?>" readonly disabled>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Age</label>
                            <input type="text" class="form-control" value="<?php echo $member['age'] ?? 'N/A'; ?>" readonly disabled>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Civil Status</label>
                            <input type="text" class="form-control" value="<?php echo htmlspecialchars($member['civil_status'] ?? ''); ?>" readonly disabled>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Religion</label>
                            <input type="text" class="form-control" value="<?php echo htmlspecialchars($member['religion'] ?? ''); ?>" readonly disabled>
                        </div>
                        <div class="col-12 mb-3">
                            <label class="form-label fw-bold">Address</label>
                            <input type="text" class="form-control" value="<?php 
                                $address_parts = [];
                                if (!empty($member['barangay'])) $address_parts[] = 'Brgy. ' . $member['barangay'];
                                if (!empty($member['city'])) $address_parts[] = $member['city'];
                                if (!empty($member['province'])) $address_parts[] = $member['province'];
                                echo htmlspecialchars(implode(', ', $address_parts) ?: 'N/A');
                            ?>" readonly disabled>
                        </div>
                        
                        <div class="col-12">
                            <hr>
                            <h5 class="mb-3">Contact Information (Editable)</h5>
                        </div>
                        
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
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Email</label>
                            <input type="email" class="form-control" name="email" 
                                   value="<?php echo htmlspecialchars($member['email'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6 mb-3">
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
                    
                    <div class="text-center">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Update Contact Information
                        </button>
                    </div>
                </form>
            </div>
        </div>
        
        <hr class="my-4">
        
        <div class="row">
            <div class="col-md-6">
                <h5 class="mb-3">Family Information</h5>
                <?php 
                $father_name = trim(($member['father_fname'] ?? '') . ' ' . ($member['father_mname'] ?? '') . ' ' . ($member['father_lname'] ?? ''));
                $mother_name = trim(($member['mother_fname'] ?? '') . ' ' . ($member['mother_mname'] ?? '') . ' ' . ($member['mother_lname'] ?? ''));
                $spouse_name = trim(($member['spouse_fname'] ?? '') . ' ' . ($member['spouse_mname'] ?? '') . ' ' . ($member['spouse_lname'] ?? ''));
                ?>
                
                <?php if (!empty($father_name)): ?>
                <p><strong>Father:</strong> <?php echo htmlspecialchars($father_name); ?></p>
                <?php endif; ?>
                
                <?php if (!empty($mother_name)): ?>
                <p><strong>Mother:</strong> <?php echo htmlspecialchars($mother_name); ?></p>
                <?php endif; ?>
                
                <?php if (!empty($spouse_name)): ?>
                <p><strong>Spouse:</strong> <?php echo htmlspecialchars($spouse_name); ?></p>
                <?php endif; ?>
            </div>
            
            <div class="col-md-6">
                <h5 class="mb-3">Chapter Information</h5>
                <p><strong>Chapter:</strong> <?php echo htmlspecialchars($member['chapter'] ?? 'N/A'); ?></p>
                <p><strong>Group:</strong> <?php echo htmlspecialchars($member['group_name'] ?? 'N/A'); ?></p>
                <p><strong>Leader:</strong> <?php echo htmlspecialchars($member['leader'] ?? 'N/A'); ?></p>
                <p><strong>Coordinator:</strong> <?php echo htmlspecialchars($member['coordinator'] ?? 'N/A'); ?></p>
            </div>
        </div>
    </div>
</div>