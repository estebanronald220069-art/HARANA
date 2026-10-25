<!DOCTYPE html>
<!--register.template.php-->
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Membership Application - Harana Financial System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/register.css">
</head>
<body>
    <div class="application-container">

        <div class="logo-section">
            <div class="logo-left">
                <img src="assets/images/harana-logo.png" alt="Harana Logo" onerror="this.style.display='none'; this.parentNode.querySelector('.logo-placeholder').style.display='flex';">
                <div class="logo-placeholder" style="display: none;">
                    <i class="fas fa-hand-holding-heart fa-3x"></i>
                </div>
                <div class="text-content">
                    <h2>NAGKAISANG HARANISTA</h2>
                    <h5>SA GINTONG LUZON, PHILS. INC. (NHGL, INC.)</h5>
                    <p class="small">(Formerly Nagkaisang Haranista Sa Gintong Luzon, Inc.)</p>
                    <p class="small">(Sec. REG No. CN 700172104)</p>
                    <p class="small">MF 2024<br>Bryg. Singalat, Palayan City<br>Province of Nueva Ecija<br>Tel. No. (044)940-6708</p>
                </div>
            </div>
        </div>

        <div class="form-section">
            <h4 class="form-title">APPLICATION FOR MEMBERSHIP</h4>

            <?php if ($error): ?>
                <div class="error-message"><i class="fas fa-exclamation-circle me-2"></i><?php echo $error; ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="success-message"><i class="fas fa-check-circle me-2"></i><?php echo $success; ?></div>
            <?php endif; ?>
            <?php if (!empty($rejection_note)): ?>
    <div style="background:#fef3c7;border-left:4px solid #d97706;border-radius:10px;padding:16px 20px;margin-bottom:20px;">
        <h6 style="color:#92400e;margin:0 0 8px;font-weight:700;">
            <i class="fas fa-exclamation-triangle me-2"></i>Your previous application was not approved
        </h6>
        <p style="color:#78350f;margin:0 0 6px;">
            <strong>Reason:</strong> <?php echo htmlspecialchars($rejection_note['reason_label'] ?? 'Not specified'); ?>
        </p>
        <?php if (!empty($rejection_note['reason_message'])): ?>
            <p style="color:#78350f;font-size:14px;margin:0 0 6px;">
                <?php echo nl2br(htmlspecialchars($rejection_note['reason_message'])); ?>
            </p>
        <?php endif; ?>
        <?php if (!empty($rejection_note['custom_reason'])): ?>
            <div style="background:#fff;padding:10px 14px;border-radius:8px;margin-top:8px;">
                <strong style="font-size:11px;text-transform:uppercase;color:#92400e;">Admin note:</strong>
                <div style="margin-top:4px;color:#4a4a4a;"><?php echo nl2br(htmlspecialchars($rejection_note['custom_reason'])); ?></div>
            </div>
        <?php endif; ?>
        <p style="color:#78350f;font-size:13px;margin:12px 0 0;">
            <i class="fas fa-info-circle me-1"></i>
            Your previous answers are pre-filled below. Please correct the issue and resubmit.
        </p>
    </div>
<?php endif; ?>

            <div class="step-indicators" id="stepIndicators"></div>

            <form method="POST" action="" id="registrationForm" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">

                <!-- ===== STEP 1: PERSONAL INFORMATION ===== -->
                <div class="step active" data-step="1">
                    <div class="section-title">I. PERSONAL INFORMATION</div>

                    <input type="hidden" id="saved_birth_province" value="<?php echo htmlspecialchars($form_data['birth_province'] ?? ''); ?>">
                    <input type="hidden" id="saved_birth_city" value="<?php echo htmlspecialchars($form_data['birth_city'] ?? ''); ?>">

                    <div class="row mb-2">
                        <div class="col-md-3">
                            <label class="form-label">Member Code</label>
                            <input type="text" class="form-control" value="<?php echo $generated_member_code; ?>" readonly disabled>
                            <input type="hidden" name="member_code" value="<?php echo $generated_member_code; ?>">
                            <small class="text-muted">Auto-generated</small>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Date Joined *</label>
                            <input type="date" name="date_joined" class="form-control" value="<?php echo htmlspecialchars($form_data['date_joined'] ?? date('Y-m-d')); ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label required-field">Monthly Contribution *</label>
                            <input type="number" name="monthly_contribution" class="form-control"
                                   value="<?php echo htmlspecialchars($form_data['monthly_contribution'] ?? '100.00'); ?>"
                                   step="0.01" min="100" required>
                            <small class="text-muted">Minimum: ₱100.00</small>
                        </div>
                    </div>

                    <div class="row mb-2">
                        <div class="col-md-4">
                            <label class="form-label required-field">Last Name</label>
                            <input type="text" name="last_name" class="form-control" value="<?php echo htmlspecialchars($form_data['last_name'] ?? ''); ?>" maxlength="50" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label required-field">First Name</label>
                            <input type="text" name="first_name" class="form-control" value="<?php echo htmlspecialchars($form_data['first_name'] ?? ''); ?>" maxlength="50" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Middle Name</label>
                            <input type="text" name="middle_name" class="form-control" value="<?php echo htmlspecialchars($form_data['middle_name'] ?? ''); ?>" maxlength="50">
                        </div>
                    </div>

                    <div class="row mb-2">
                        <div class="col-md-2">
                            <label class="form-label">Date of Birth</label>
                            <input type="date" name="birth_date" id="birth_date" class="form-control" value="<?php echo htmlspecialchars($form_data['birth_date'] ?? ''); ?>" onchange="calculateAge()">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label required-field">Place of Birth - Province</label>
                            <select name="birth_province" id="birth_province" class="form-select" required>
                                <option value="">— Select Province —</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label required-field">Place of Birth - City</label>
                            <select name="birth_city" id="birth_city" class="form-select" required disabled>
                                <option value="">— Select City —</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Age</label>
                            <input type="number" name="age" id="age" class="form-control" value="<?php echo htmlspecialchars($form_data['age'] ?? ''); ?>" readonly>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Gender</label>
                            <select name="gender" class="form-select">
                                <option value="">Select</option>
                                <option value="Male" <?php echo ($form_data['gender'] ?? '') == 'Male' ? 'selected' : ''; ?>>Male</option>
                                <option value="Female" <?php echo ($form_data['gender'] ?? '') == 'Female' ? 'selected' : ''; ?>>Female</option>
                            </select>
                        </div>
                    </div>

                    <div class="row mb-2">
                        <div class="col-md-3">
                            <label class="form-label">Civil Status</label>
                            <select name="civil_status" class="form-select">
                                <option value="">Select</option>
                                <option value="Single" <?php echo ($form_data['civil_status'] ?? '') == 'Single' ? 'selected' : ''; ?>>Single</option>
                                <option value="Married" <?php echo ($form_data['civil_status'] ?? '') == 'Married' ? 'selected' : ''; ?>>Married</option>
                                <option value="Widowed" <?php echo ($form_data['civil_status'] ?? '') == 'Widowed' ? 'selected' : ''; ?>>Widowed</option>
                                <option value="Separated" <?php echo ($form_data['civil_status'] ?? '') == 'Separated' ? 'selected' : ''; ?>>Separated</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label required-field">Religion</label>
                            <select name="religion" class="form-select" required>
                                <option value="">— Select Religion —</option>
                                <option value="Roman Catholic" <?php echo ($form_data['religion'] ?? '') == 'Roman Catholic' ? 'selected' : ''; ?>>Roman Catholic</option>
                                <option value="Protestant / Evangelical Christian" <?php echo ($form_data['religion'] ?? '') == 'Protestant / Evangelical Christian' ? 'selected' : ''; ?>>Protestant / Evangelical Christian</option>
                                <option value="Iglesia ni Cristo (INC)" <?php echo ($form_data['religion'] ?? '') == 'Iglesia ni Cristo (INC)' ? 'selected' : ''; ?>>Iglesia ni Cristo (INC)</option>
                                <option value="Jehovah's Witnesses" <?php echo ($form_data['religion'] ?? '') == "Jehovah's Witnesses" ? 'selected' : ''; ?>>Jehovah's Witnesses</option>
                                <option value="Iglesia Filipina Independiente (Aglipayan)" <?php echo ($form_data['religion'] ?? '') == 'Iglesia Filipina Independiente (Aglipayan)' ? 'selected' : ''; ?>>Iglesia Filipina Independiente (Aglipayan)</option>
                                <option value="Seventh-day Adventist" <?php echo ($form_data['religion'] ?? '') == 'Seventh-day Adventist' ? 'selected' : ''; ?>>Seventh-day Adventist</option>
                                <option value="Islam" <?php echo ($form_data['religion'] ?? '') == 'Islam' ? 'selected' : ''; ?>>Islam</option>
                                <option value="Buddhism" <?php echo ($form_data['religion'] ?? '') == 'Buddhism' ? 'selected' : ''; ?>>Buddhism</option>
                                <option value="Hinduism" <?php echo ($form_data['religion'] ?? '') == 'Hinduism' ? 'selected' : ''; ?>>Hinduism</option>
                                <option value="Indigenous / Traditional Religion" <?php echo ($form_data['religion'] ?? '') == 'Indigenous / Traditional Religion' ? 'selected' : ''; ?>>Indigenous / Traditional Religion</option>
                                <option value="Other" <?php echo ($form_data['religion'] ?? '') == 'Other' ? 'selected' : ''; ?>>Other</option>
                                <option value="No Religion" <?php echo ($form_data['religion'] ?? '') == 'No Religion' ? 'selected' : ''; ?>>No Religion</option>
                                <option value="Prefer not to say" <?php echo ($form_data['religion'] ?? '') == 'Prefer not to say' ? 'selected' : ''; ?>>Prefer not to say</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- ===== STEP 2: ADDRESS INFORMATION ===== -->
                <div class="step" data-step="2">
                    <div class="section-title">II. ADDRESS INFORMATION</div>

                    <input type="hidden" id="saved_present_province" value="<?php echo htmlspecialchars($form_data['province'] ?? ''); ?>">
                    <input type="hidden" id="saved_present_city" value="<?php echo htmlspecialchars($form_data['city'] ?? ''); ?>">
                    <input type="hidden" id="saved_present_barangay" value="<?php echo htmlspecialchars($form_data['barangay'] ?? ''); ?>">
                    <input type="hidden" id="saved_permanent_province" value="<?php echo htmlspecialchars($form_data['permanent_province'] ?? ''); ?>">
                    <input type="hidden" id="saved_permanent_city" value="<?php echo htmlspecialchars($form_data['permanent_city'] ?? ''); ?>">
                    <input type="hidden" id="saved_permanent_barangay" value="<?php echo htmlspecialchars($form_data['permanent_barangay'] ?? ''); ?>">

                    <div class="row mb-3">
                        <div class="col-12">
                            <h5 class="text-primary"><i class="fas fa-home me-2"></i>Present Address</h5>
                            <hr class="mt-0">
                        </div>
                    </div>
                    <div class="row mb-2">
                        <div class="col-md-3">
                            <label class="form-label required-field">Province</label>
                            <select name="province" id="present_province" class="form-select" required>
                                <option value="">— Select Province —</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label required-field">City/Municipality</label>
                            <select name="city" id="present_city" class="form-select" required disabled>
                                <option value="">— Select City —</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label required-field">Barangay</label>
                            <select name="barangay" id="present_barangay" class="form-select" required disabled>
                                <option value="">— Select Barangay —</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Street/Purok/Sitio</label>
                            <input type="text" name="present_street" id="present_street" class="form-control" value="<?php echo htmlspecialchars($form_data['present_street'] ?? ''); ?>" placeholder="Street/Purok/Sitio">
                        </div>
                    </div>

                    <div class="row mb-3 mt-4">
                        <div class="col-12">
                            <h5 class="text-success"><i class="fas fa-map-pin me-2"></i>Permanent Address</h5>
                            <hr class="mt-0">
                        </div>
                    </div>
                    <div class="row mb-2">
                        <div class="col-md-3">
                            <label class="form-label required-field">Province</label>
                            <select name="permanent_province" id="permanent_province" class="form-select" required>
                                <option value="">— Select Province —</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label required-field">City/Municipality</label>
                            <select name="permanent_city" id="permanent_city" class="form-select" required disabled>
                                <option value="">— Select City —</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label required-field">Barangay</label>
                            <select name="permanent_barangay" id="permanent_barangay" class="form-select" required disabled>
                                <option value="">— Select Barangay —</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Street/Purok/Sitio</label>
                            <div class="d-flex gap-2">
                                <input type="text" name="permanent_street" id="permanent_street" class="form-control" value="<?php echo htmlspecialchars($form_data['permanent_street'] ?? ''); ?>" placeholder="Street/Purok/Sitio" style="flex:1;">
                                <button type="button" id="sameAsPresentBtn" class="btn btn-outline-primary btn-sm" style="white-space:nowrap; padding:6px 12px;">
                                    <i class="fas fa-copy me-1"></i> Same as Present
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ===== STEP 3: CONTACT INFORMATION ===== -->
                <div class="step" data-step="3">
                    <div class="section-title">III. CONTACT INFORMATION</div>
                    <div class="row mb-2">
                        <div class="col-md-4">
                            <label class="form-label required-field">Contact Number</label>
                            <input type="text" name="contact_number" class="form-control" value="<?php echo htmlspecialchars($form_data['contact_number'] ?? ''); ?>" maxlength="20" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Alternate Number</label>
                            <input type="text" name="alternate_number" class="form-control" value="<?php echo htmlspecialchars($form_data['alternate_number'] ?? ''); ?>" maxlength="20">
                        </div>
                        <div class="col-md-4">
    <label class="form-label required-field">Email Address</label>
    <input type="email" name="email" class="form-control"
           value="<?php echo htmlspecialchars(!empty($existing_account) ? $prefill_email : ($form_data['email'] ?? '')); ?>"
           maxlength="100"
           <?php echo !empty($existing_account) ? 'readonly' : 'required'; ?>>
    <?php if (!empty($existing_account)): ?>
        <small class="text-muted">From your account. Cannot be changed here.</small>
    <?php endif; ?>
</div>
                    </div>
                </div>

                <!-- ===== STEP 4: FAMILY BACKGROUND ===== -->
                <div class="step" data-step="4">
                    <div class="section-title">IV. FAMILY BACKGROUND</div>
                    <div class="row mb-2">
                        <div class="col-md-12"><label class="form-label">Father's Full Name:</label></div>
                        <div class="col-md-4"><input type="text" name="father_fname" class="form-control" placeholder="First Name" value="<?php echo htmlspecialchars($form_data['father_fname'] ?? ''); ?>"></div>
                        <div class="col-md-4"><input type="text" name="father_mname" class="form-control" placeholder="Middle Name" value="<?php echo htmlspecialchars($form_data['father_mname'] ?? ''); ?>"></div>
                        <div class="col-md-4"><input type="text" name="father_lname" class="form-control" placeholder="Last Name" value="<?php echo htmlspecialchars($form_data['father_lname'] ?? ''); ?>"></div>
                    </div>
                    <div class="row mb-2">
                        <div class="col-md-12"><label class="form-label">Mother's Full Name (Maiden Name):</label></div>
                        <div class="col-md-4"><input type="text" name="mother_fname" class="form-control" placeholder="First Name" value="<?php echo htmlspecialchars($form_data['mother_fname'] ?? ''); ?>"></div>
                        <div class="col-md-4"><input type="text" name="mother_mname" class="form-control" placeholder="Middle Name" value="<?php echo htmlspecialchars($form_data['mother_mname'] ?? ''); ?>"></div>
                        <div class="col-md-4"><input type="text" name="mother_lname" class="form-control" placeholder="Last Name" value="<?php echo htmlspecialchars($form_data['mother_lname'] ?? ''); ?>"></div>
                    </div>
                    <div class="row mb-2">
                        <div class="col-md-12"><label class="form-label">Spouse's Full Name (If Married):</label></div>
                        <div class="col-md-3"><input type="text" name="spouse_fname" class="form-control" placeholder="First Name" value="<?php echo htmlspecialchars($form_data['spouse_fname'] ?? ''); ?>"></div>
                        <div class="col-md-3"><input type="text" name="spouse_mname" class="form-control" placeholder="Middle Name" value="<?php echo htmlspecialchars($form_data['spouse_mname'] ?? ''); ?>"></div>
                        <div class="col-md-3"><input type="text" name="spouse_lname" class="form-control" placeholder="Last Name" value="<?php echo htmlspecialchars($form_data['spouse_lname'] ?? ''); ?>"></div>
                        <div class="col-md-3"><input type="number" name="spouse_age" class="form-control" placeholder="Age" value="<?php echo htmlspecialchars($form_data['spouse_age'] ?? ''); ?>"></div>
                    </div>
                </div>

                <!-- ===== STEP 5: CHILDREN ===== -->
                <div class="step" data-step="5">
                    <div class="section-title">V. CHILDREN (below 18 years old)</div>
                    <p class="small-text mb-2">List all children (including those below 18 years old)</p>
                    <?php for ($i = 1; $i <= 4; $i++): ?>
                    <div class="row mb-2">
                        <div class="col-md-1"><label class="form-label"><?php echo $i; ?>.</label></div>
                        <div class="col-md-3"><input type="text" name="child<?php echo $i; ?>_fname" class="form-control" placeholder="First Name" value="<?php echo htmlspecialchars($form_data["child{$i}_fname"] ?? ''); ?>"></div>
                        <div class="col-md-3"><input type="text" name="child<?php echo $i; ?>_mname" class="form-control" placeholder="Middle Name" value="<?php echo htmlspecialchars($form_data["child{$i}_mname"] ?? ''); ?>"></div>
                        <div class="col-md-3"><input type="text" name="child<?php echo $i; ?>_lname" class="form-control" placeholder="Last Name" value="<?php echo htmlspecialchars($form_data["child{$i}_lname"] ?? ''); ?>"></div>
                        <div class="col-md-2"><input type="number" name="child<?php echo $i; ?>_age" class="form-control" placeholder="Age" value="<?php echo htmlspecialchars($form_data["child{$i}_age"] ?? ''); ?>"></div>
                    </div>
                    <?php endfor; ?>
                </div>

                <!-- ===== STEP 6: CHARACTER REFERENCES ===== -->
                <div class="step" data-step="6">
                    <div class="section-title">VI. CHARACTER REFERENCES</div>
                    <div class="row mb-2">
                        <div class="col-md-5"><label class="form-label">Name of Reference 1</label><input type="text" name="ref1_name" class="form-control" value="<?php echo htmlspecialchars($form_data['ref1_name'] ?? ''); ?>"></div>
                        <div class="col-md-7"><label class="form-label">Contact Number</label><input type="text" name="ref1_contact" class="form-control" value="<?php echo htmlspecialchars($form_data['ref1_contact'] ?? ''); ?>"></div>
                    </div>
                    <div class="row mb-2">
                        <div class="col-md-5"><label class="form-label">Name of Reference 2</label><input type="text" name="ref2_name" class="form-control" value="<?php echo htmlspecialchars($form_data['ref2_name'] ?? ''); ?>"></div>
                        <div class="col-md-7"><label class="form-label">Contact Number</label><input type="text" name="ref2_contact" class="form-control" value="<?php echo htmlspecialchars($form_data['ref2_contact'] ?? ''); ?>"></div>
                    </div>
                </div>

                <!-- ===== STEP 7: BENEFICIARY INFORMATION ===== -->
                <div class="step" data-step="7">
                    <div class="section-title">VII. BENEFICIARY INFORMATION</div>
                    <div class="row mb-2">
                        <div class="col-md-4"><label class="form-label required-field">Full Name of Beneficiary</label><input type="text" name="beneficiary_name" class="form-control" value="<?php echo htmlspecialchars($form_data['beneficiary_name'] ?? ''); ?>" required></div>
                        <div class="col-md-4"><label class="form-label required-field">Complete Address</label><input type="text" name="beneficiary_address" class="form-control" value="<?php echo htmlspecialchars($form_data['beneficiary_address'] ?? ''); ?>" required></div>
                        <div class="col-md-2"><label class="form-label required-field">Relationship</label><input type="text" name="beneficiary_relation" class="form-control" value="<?php echo htmlspecialchars($form_data['beneficiary_relation'] ?? ''); ?>" required></div>
                        <div class="col-md-2"><label class="form-label">Age</label><input type="number" name="beneficiary_age" class="form-control" value="<?php echo htmlspecialchars($form_data['beneficiary_age'] ?? ''); ?>"></div>
                    </div>
                    <div class="row mb-2">
                        <div class="col-md-4"><label class="form-label">Contact Number of Beneficiary</label><input type="text" name="beneficiary_contact" class="form-control" value="<?php echo htmlspecialchars($form_data['beneficiary_contact'] ?? ''); ?>"></div>
                    </div>
                </div>

                <!-- ===== STEP 8: CHAPTER INFORMATION ===== -->
                <div class="step" data-step="8">
                    <div class="section-title">VIII. CHAPTER INFORMATION</div>
                    <div class="row mb-2">
                        <div class="col-md-3">
                            <label class="form-label required-field">Chapter</label>
                            <select name="chapter" id="reg_chapter" class="form-select" required onchange="updateGroupOptions()">
                                <option value="">Select Chapter</option>
                                <?php foreach ($chapters as $ch): ?>
                                    <option value="<?php echo htmlspecialchars($ch['chapter_name']); ?>"
                                            data-chapter-id="<?php echo (int)$ch['chapter_id']; ?>"
                                            <?php echo (isset($form_data['chapter']) && $form_data['chapter'] === $ch['chapter_name']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($ch['chapter_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Group Name</label>
                            <select name="group_name" id="reg_group" class="form-select">
                                <option value="">— Select chapter first —</option>
                            </select>
                            <small class="text-muted d-block mt-1" style="font-size:.7rem;">Options depend on the chapter you pick.</small>
                        </div>
                        <div class="col-md-3"><label class="form-label">Leader</label><input type="text" name="leader" class="form-control" value="<?php echo htmlspecialchars($form_data['leader'] ?? ''); ?>"></div>
                        <div class="col-md-3"><label class="form-label">Coordinator</label><input type="text" name="coordinator" class="form-control" value="<?php echo htmlspecialchars($form_data['coordinator'] ?? ''); ?>"></div>
                    </div>
                    <div class="row mb-2">
                        <div class="col-md-3"><label class="form-label">Chairman</label><input type="text" name="chairman" class="form-control" value="<?php echo htmlspecialchars($form_data['chairman'] ?? ''); ?>"></div>
                        <div class="col-md-3"><label class="form-label">Screening Officer</label><input type="text" name="screening_officer" class="form-control" value="<?php echo htmlspecialchars($form_data['screening_officer'] ?? ''); ?>"></div>
                        <div class="col-md-3"><label class="form-label">Screening Date</label><input type="date" name="screening_date" class="form-control" value="<?php echo htmlspecialchars($form_data['screening_date'] ?? ''); ?>"></div>
                        <div class="col-md-3"><label class="form-label">Approved By</label><input type="text" name="approved_by" class="form-control" value="<?php echo htmlspecialchars($form_data['approved_by'] ?? ''); ?>"></div>
                    </div>
                    <div class="row mb-2">
                        <div class="col-md-3"><label class="form-label">Date Registered</label><input type="date" name="date_registered" class="form-control" value="<?php echo htmlspecialchars($form_data['date_registered'] ?? date('Y-m-d')); ?>"></div>
                    </div>
                </div>

               <!-- ===== STEP 9: ACCOUNT INFORMATION ===== -->
<div class="step" data-step="9">
    <div class="section-title">IX. ACCOUNT INFORMATION</div>
    <?php if (!empty($existing_account)): ?>
        <div style="background:#e8f0f8;border-left:4px solid #375a7f;border-radius:10px;padding:14px 18px;margin-bottom:16px;">
            <i class="fas fa-lock me-2"></i>
            <strong>Your account is already set up.</strong>
            You'll use this email and your existing password to log in.
        </div>
        <div class="row mb-2">
            <div class="col-md-6">
                <label class="form-label">Email (login identifier)</label>
                <input type="text" class="form-control" value="<?php echo htmlspecialchars($prefill_email); ?>" readonly>
            </div>
            <div class="col-md-6">
                <label class="form-label">Password</label>
                <input type="text" class="form-control" value="••••••••" readonly>
                <small class="text-muted">
                    Use your existing password. <a href="forgot_password.php">Forgot password?</a>
                </small>
            </div>
        </div>
    <?php else: ?>
        <div class="row mb-2">
            <div class="col-md-4">
                <label class="form-label required-field">Username</label>
                <input type="text" name="username" class="form-control" value="<?php echo htmlspecialchars($form_data['username'] ?? ''); ?>" maxlength="50" required>
            </div>
            <div class="col-md-4">
                <label class="form-label required-field">Password</label>
                <input type="password" name="password" class="form-control" minlength="6" required>
                <small class="text-muted">Minimum 6 characters</small>
            </div>
            <div class="col-md-4">
                <label class="form-label required-field">Confirm Password</label>
                <input type="password" name="confirm_password" class="form-control" required>
            </div>
        </div>
    <?php endif; ?>
</div>

                <!-- ===== STEP 10: UPLOAD 2x2 ID PHOTO ===== -->
                <div class="step" data-step="10">
                    <div class="section-title">X. UPLOAD 2x2 ID PICTURE</div>
                    <div class="photo-upload-section">
                        <div class="registration-photo" id="registrationPhoto" onclick="document.getElementById('photoInput').click()">
                            <div class="photo-placeholder" id="photoPlaceholder">
                                <i class="fas fa-camera"></i>
                                <p>2x2 ID Picture</p>
                            </div>
                            <img id="photoPreview" style="display: none;">
                        </div>
                        <input type="file"
                               id="photoInput"
                               name="registration_photo"
                               accept="image/jpeg,image/png,image/gif,image/webp"
                               style="display: none;">
                        <div class="photo-requirements"><i class="fas fa-info-circle"></i> 2x2 ID • Min 300x300px • Square • JPG/PNG</div>
                        <div id="photoError" class="text-danger small mt-2" style="display:none;"></div>
                    </div>
                </div>

                <!-- ===== STEP 11: DOCUMENTS ATTACHED ===== -->
                <div class="step" data-step="11">
                    <div class="section-title">XI. DOCUMENTS ATTACHED</div>

                    <div class="documents-section">
                        <div class="checkbox-group mb-3">
                            <div class="checkbox-item">
                                <input type="checkbox" name="medical_certificate" id="medical_certificate" value="1"
                                       <?php echo !empty($form_data['medical_certificate']) ? 'checked' : ''; ?>>
                                <label for="medical_certificate">Medical Certificate</label>
                            </div>
                            <div class="checkbox-item">
                                <input type="checkbox" name="birth_certificate" id="birth_certificate" value="1"
                                       <?php echo !empty($form_data['birth_certificate']) ? 'checked' : ''; ?>>
                                <label for="birth_certificate">Birth Certificate</label>
                            </div>
                        </div>

                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">Upload Medical Certificate</label>
                                <input type="file"
                                       id="medicalInput"
                                       name="medical_certificate_file"
                                       accept="image/jpeg,image/png,image/webp,application/pdf"
                                       class="form-control form-control-sm">
                                <div id="medical_status" class="small mt-1"></div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">Upload Birth Certificate</label>
                                <input type="file"
                                       id="birthInput"
                                       name="birth_certificate_file"
                                       accept="image/jpeg,image/png,image/webp,application/pdf"
                                       class="form-control form-control-sm">
                                <div id="birth_status" class="small mt-1"></div>
                            </div>
                        </div>

                        <p class="text-muted small mb-0">* You can upload these later, but submitting them now speeds up approval. Accepted: JPG, PNG, WEBP, PDF (max 10MB).</p>
                    </div>
                </div>

                <!-- ===== NAVIGATION ===== -->
                <div class="step-nav">
                    <button type="button" class="btn btn-step btn-step-prev" id="prevBtn" disabled>
                        <i class="fas fa-arrow-left me-1"></i> Previous
                    </button>
                    <button type="button" class="btn btn-step btn-step-next" id="nextBtn">
                        Next <i class="fas fa-arrow-right ms-1"></i>
                    </button>
                </div>

                <div class="row mt-3">
                    <div class="col-12 text-end">
                        <button type="button" class="btn-fill" onclick="fillSampleData()">
                            <i class="fas fa-magic me-1"></i>Fill Sample Data
                        </button>
                    </div>
                </div>

                <div class="text-center mt-3">
                    <p>Already have an account? <a href="index.php" style="color: #667eea;">Login here</a></p>
                </div>
            </form>
        </div>

        <div class="footer">
            <p class="mb-0">© <?php echo date('Y'); ?> Harana Financial System. All rights reserved.</p>
        </div>
    </div>

    <script>
        function calculateAge() {
            const birthDate = document.getElementById('birth_date').value;
            if (birthDate) {
                const today = new Date();
                const birth = new Date(birthDate);
                let age = today.getFullYear() - birth.getFullYear();
                const monthDiff = today.getMonth() - birth.getMonth();
                if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < birth.getDate())) age--;
                document.getElementById('age').value = age >= 0 ? age : '';
            }
        }

        function fillSampleData() {
            document.querySelector('[name="first_name"]').value = 'Juan';
            document.querySelector('[name="last_name"]').value = 'Dela Cruz';
            document.querySelector('[name="middle_name"]').value = 'Santos';
            document.querySelector('[name="present_street"]').value = 'Purok 3';
            document.querySelector('[name="permanent_street"]').value = 'Purok 3';
            document.querySelector('[name="contact_number"]').value = '09123456789';
            document.querySelector('[name="alternate_number"]').value = '09987654321';
            document.querySelector('[name="email"]').value = 'juan.delacruz@example.com';
            document.querySelector('[name="birth_date"]').value = '1990-01-15';
            calculateAge();
            document.querySelector('[name="gender"]').value = 'Male';
            document.querySelector('[name="civil_status"]').value = 'Married';
            document.querySelector('[name="religion"]').value = 'Roman Catholic';
            document.querySelector('[name="monthly_contribution"]').value = '100.00';
            document.querySelector('[name="father_fname"]').value = 'Pedro';
            document.querySelector('[name="father_mname"]').value = 'D';
            document.querySelector('[name="father_lname"]').value = 'Dela Cruz';
            document.querySelector('[name="mother_fname"]').value = 'Maria';
            document.querySelector('[name="mother_mname"]').value = 'S';
            document.querySelector('[name="mother_lname"]').value = 'Santos';
            document.querySelector('[name="spouse_fname"]').value = 'Juana';
            document.querySelector('[name="spouse_mname"]').value = 'R';
            document.querySelector('[name="spouse_lname"]').value = 'Dela Cruz';
            document.querySelector('[name="spouse_age"]').value = '30';
            document.querySelector('[name="child1_fname"]').value = 'Jose';
            document.querySelector('[name="child1_mname"]').value = 'J';
            document.querySelector('[name="child1_lname"]').value = 'Dela Cruz';
            document.querySelector('[name="child1_age"]').value = '5';
            document.querySelector('[name="child2_fname"]').value = 'Maria';
            document.querySelector('[name="child2_mname"]').value = 'J';
            document.querySelector('[name="child2_lname"]').value = 'Dela Cruz';
            document.querySelector('[name="child2_age"]').value = '3';
            document.querySelector('[name="ref1_name"]').value = 'Jose Rizal';
            document.querySelector('[name="ref1_contact"]').value = '09221112233';
            document.querySelector('[name="ref2_name"]').value = 'Andres Bonifacio';
            document.querySelector('[name="ref2_contact"]').value = '09332223344';
            document.querySelector('[name="beneficiary_name"]').value = 'Juana Dela Cruz';
            document.querySelector('[name="beneficiary_address"]').value = 'Palayan City';
            document.querySelector('[name="beneficiary_relation"]').value = 'Spouse';
            document.querySelector('[name="beneficiary_age"]').value = '30';
            document.querySelector('[name="beneficiary_contact"]').value = '09123456789';
            document.querySelector('[name="chapter"]').value = 'GUIMBA';
            updateGroupOptions();
            setTimeout(() => {
                const gs = document.getElementById('reg_group');
                if (gs) gs.value = 'Group A';
            }, 50);
            document.querySelector('[name="leader"]').value = 'John Doe';
            document.querySelector('[name="coordinator"]').value = 'Jane Smith';
            document.querySelector('[name="chairman"]').value = 'Mike Johnson';
            document.querySelector('[name="screening_officer"]').value = 'Sarah Wilson';
            document.querySelector('[name="screening_date"]').value = '2026-02-28';
            document.querySelector('[name="approved_by"]').value = 'Admin';
            document.querySelector('[name="date_joined"]').value = '2026-02-28';
            document.querySelector('[name="username"]').value = 'juan_delacruz';
            document.querySelector('[name="password"]').value = 'password123';
            document.querySelector('[name="confirm_password"]').value = 'password123';
            document.querySelector('[name="medical_certificate"]').checked = true;
            document.querySelector('[name="birth_certificate"]').checked = true;
            alert('Sample data filled! Please note: You still need to select Province, City, Barangay manually. You also need to manually choose the 3 files (ID photo, medical cert, birth cert).');
        }

        document.addEventListener('DOMContentLoaded', function() {
            const monthlyContribution = document.querySelector('[name="monthly_contribution"]');
            if (monthlyContribution) {
                monthlyContribution.addEventListener('input', function() {
                    const amount = parseFloat(this.value);
                    if (isNaN(amount) || amount < 100) this.classList.add('is-invalid');
                    else this.classList.remove('is-invalid');
                });
            }

            <?php if (!empty($form_data['birth_date'])): ?>
            calculateAge();
            <?php endif; ?>

            const logo = document.querySelector('.logo-section img');
            if (logo && logo.complete && logo.naturalWidth === 0) {
                logo.style.display = 'none';
                const placeholder = logo.parentNode.querySelector('.logo-placeholder');
                if (placeholder) placeholder.style.display = 'flex';
            }

            // STEP WIZARD
            const totalSteps = 11;
            let currentStep = 1;

            const indicators = document.getElementById('stepIndicators');
            for (let i = 1; i <= totalSteps; i++) {
                const dot = document.createElement('span');
                dot.className = 'dot' + (i === 1 ? ' active' : '');
                dot.dataset.step = i;
                indicators.appendChild(dot);
                if (i < totalSteps) {
                    const conn = document.createElement('span');
                    conn.className = 'connector';
                    indicators.appendChild(conn);
                }
            }

            const dots = document.querySelectorAll('.dot');
            const steps = document.querySelectorAll('.step');
            const prevBtn = document.getElementById('prevBtn');
            const nextBtn = document.getElementById('nextBtn');

            function updateUI(stepNum) {
                steps.forEach(s => {
                    s.classList.toggle('active', parseInt(s.dataset.step) === stepNum);
                });
                dots.forEach(d => {
                    const ds = parseInt(d.dataset.step);
                    d.classList.remove('active', 'completed');
                    if (ds === stepNum) d.classList.add('active');
                    else if (ds < stepNum) d.classList.add('completed');
                });
                prevBtn.disabled = (stepNum === 1);
                if (stepNum === totalSteps) {
                    nextBtn.innerHTML = 'Submit <i class="fas fa-check ms-1"></i>';
                    nextBtn.classList.remove('btn-step-next');
                    nextBtn.classList.add('btn-step-submit');
                } else {
                    nextBtn.innerHTML = 'Next <i class="fas fa-arrow-right ms-1"></i>';
                    nextBtn.classList.remove('btn-step-submit');
                    nextBtn.classList.add('btn-step-next');
                }
                window.scrollTo({ top: 0, behavior: 'smooth' });
            }

            function validateStep(stepNum) {
                const el = document.querySelector(`.step[data-step="${stepNum}"]`);
                if (!el) return true;
                const requiredInputs = el.querySelectorAll('[required]');
                let valid = true;

                requiredInputs.forEach(input => {
                    if (!input.checkValidity()) {
                        valid = false;
                        input.classList.add('is-invalid');
                    } else {
                        input.classList.remove('is-invalid');
                    }
                });

                if (stepNum === 1) {
                    const mc = document.querySelector('[name="monthly_contribution"]');
                    if (mc) {
                        const amount = parseFloat(mc.value);
                        if (isNaN(amount) || amount < 100) {
                            valid = false;
                            mc.classList.add('is-invalid');
                            alert('Monthly contribution must be at least ₱100.00.');
                        }
                    }
                }

                if (stepNum === 9) {
                    const pw = document.querySelector('[name="password"]');
                    const cf = document.querySelector('[name="confirm_password"]');
                    if (pw.value !== cf.value) {
                        valid = false;
                        cf.classList.add('is-invalid');
                        alert('Passwords do not match.');
                    } else {
                        cf.classList.remove('is-invalid');
                    }
                }

                if (!valid) alert('Please fill in all required fields in this step.');
                return valid;
            }

            function goToStep(n) {
                if (n < 1 || n > totalSteps) return;
                if (n > currentStep && !validateStep(currentStep)) return;
                currentStep = n;
                updateUI(currentStep);
            }

            prevBtn.addEventListener('click', () => goToStep(currentStep - 1));
            nextBtn.addEventListener('click', function() {
                if (currentStep === totalSteps) {
                    if (validateStep(currentStep)) {
                        document.getElementById('registrationForm').submit();
                    }
                } else {
                    goToStep(currentStep + 1);
                }
            });

            updateUI(1);

            const style = document.createElement('style');
            style.textContent = '.is-invalid { border-color: #dc3545 !important; }';
            document.head.appendChild(style);
        });

        // ─── ID Photo preview (plain input, no AJAX) ───
        const photoInput = document.getElementById('photoInput');
        const photoPreview = document.getElementById('photoPreview');
        const photoPlaceholder = document.getElementById('photoPlaceholder');
        const photoError = document.getElementById('photoError');

        if (photoInput) {
            photoInput.addEventListener('change', function() {
                photoError.style.display = 'none';
                photoError.textContent = '';

                const file = this.files[0];
                if (!file) return;

                const validTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                if (!validTypes.includes(file.type)) {
                    photoError.textContent = 'Invalid file type. Please use JPG, PNG, GIF, or WEBP.';
                    photoError.style.display = 'block';
                    this.value = '';
                    photoPreview.style.display = 'none';
                    photoPlaceholder.style.display = 'block';
                    return;
                }
                if (file.size > 10 * 1024 * 1024) {
                    photoError.textContent = 'File must be under 10MB.';
                    photoError.style.display = 'block';
                    this.value = '';
                    photoPreview.style.display = 'none';
                    photoPlaceholder.style.display = 'block';
                    return;
                }

                const reader = new FileReader();
                reader.onload = function(e) {
                    const img = new Image();
                    img.onload = function() {
                        if (this.width < 300 || this.height < 300) {
                            photoError.textContent = 'Image must be at least 300x300 pixels.';
                            photoError.style.display = 'block';
                            photoInput.value = '';
                            photoPreview.style.display = 'none';
                            photoPlaceholder.style.display = 'block';
                            return;
                        }
                        const ratio = this.width / this.height;
                        if (ratio < 0.9 || ratio > 1.1) {
                            photoError.textContent = 'Image must be roughly square (2x2).';
                            photoError.style.display = 'block';
                            photoInput.value = '';
                            photoPreview.style.display = 'none';
                            photoPlaceholder.style.display = 'block';
                            return;
                        }

                        photoPreview.src = e.target.result;
                        photoPreview.style.display = 'block';
                        photoPlaceholder.style.display = 'none';
                    };
                    img.src = e.target.result;
                };
                reader.readAsDataURL(file);
            });
        }

        // ─── Certificate inputs (plain inputs, no AJAX) ───
        const medicalInput = document.getElementById('medicalInput');
        const birthInput   = document.getElementById('birthInput');
        const medicalStatus = document.getElementById('medical_status');
        const birthStatus   = document.getElementById('birth_status');

        function showFileInfo(input, statusEl, checkboxId) {
            if (!input || !input.files || !input.files[0]) {
                if (statusEl) statusEl.innerHTML = '';
                return;
            }
            const file = input.files[0];
            if (file.size > 10 * 1024 * 1024) {
                statusEl.innerHTML = '<span class="text-danger"><i class="fas fa-times-circle"></i> File must be under 10MB</span>';
                input.value = '';
                return;
            }
            statusEl.innerHTML = '<span class="text-success"><i class="fas fa-check-circle"></i> ' + file.name + '</span>';
            const cb = document.getElementById(checkboxId);
            if (cb) cb.checked = true;
        }

        if (medicalInput) {
            medicalInput.addEventListener('change', function() {
                showFileInfo(this, medicalStatus, 'medical_certificate');
            });
        }
        if (birthInput) {
            birthInput.addEventListener('change', function() {
                showFileInfo(this, birthStatus, 'birth_certificate');
            });
        }
    </script>

    <script src="assets/js/ph-address-selector.js"></script>
    <script>
        const groupsByChapter = <?php echo json_encode($groupsByChapter); ?>;
        const chapterIdByName = <?php
            $map = [];
            foreach ($chapters as $ch) {
                $map[$ch['chapter_name']] = (int)$ch['chapter_id'];
            }
            echo json_encode($map);
        ?>;

        function updateGroupOptions() {
            const chapterSelect = document.getElementById('reg_chapter');
            const groupSelect   = document.getElementById('reg_group');
            if (!chapterSelect || !groupSelect) return;

            const previousGroup = groupSelect.value;
            groupSelect.innerHTML = '';

            const chapterName = chapterSelect.value;
            if (!chapterName) {
                const opt = document.createElement('option');
                opt.value = '';
                opt.textContent = '— Select chapter first —';
                groupSelect.appendChild(opt);
                groupSelect.disabled = true;
                return;
            }

            const chapterId = chapterIdByName[chapterName];
            const groups = groupsByChapter[chapterId] || [];

            if (groups.length === 0) {
                const opt = document.createElement('option');
                opt.value = '';
                opt.textContent = '— No groups defined for this chapter —';
                groupSelect.appendChild(opt);
                groupSelect.disabled = true;
                return;
            }

            const placeholder = document.createElement('option');
            placeholder.value = '';
            placeholder.textContent = 'Select Group';
            groupSelect.appendChild(placeholder);

            groups.forEach(g => {
                const opt = document.createElement('option');
                opt.value = g.group_name;
                opt.textContent = g.group_name;
                if (g.group_name === previousGroup) opt.selected = true;
                groupSelect.appendChild(opt);
            });

            groupSelect.disabled = false;
        }

        document.addEventListener('DOMContentLoaded', updateGroupOptions);
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>