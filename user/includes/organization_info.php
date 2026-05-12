<?php
// user/includes/organization_info.php
if (!isset($council_members)) {
    $council_members = [];
}
if (!isset($chapter_officials)) {
    $chapter_officials = [];
}
?>
<div class="row">
    <div class="col-md-6">
        <div class="dashboard-card">
            <div class="card-header" style="background: linear-gradient(135deg, #6f42c1, #6610f2);">
                <h5><i class="fas fa-info-circle me-2"></i>About NHGL, Inc.</h5>
            </div>
            <div class="card-body">
                <h4 class="mb-3">Nagkaisang Haranista sa Gintong Luzon Phils, Inc.</h4>
                <p><strong>NAGKAISANG HIRANISTA SA GINTONG LUZON, PHILS. INC. (NHGL, INC.)</strong></p>
                <p><small>(Formerly Nagkaisang Hiranista Sa Gintong Luzon, Inc.)</small></p>
                <p><small>Sec. REG No. CN 700172104</small></p>
                
                <p class="mt-3">We are a community-based financial assistance organization dedicated to providing our members with financial security, support, and opportunities for growth.</p>
                
                <h5 class="mt-4">Our Mission</h5>
                <p>To provide accessible financial assistance and support to our members, fostering a community of mutual help and financial wellness.</p>
                
                <h5 class="mt-3">Our Vision</h5>
                <p>A community where every member has access to financial security and opportunities for growth.</p>
            </div>
        </div>
    </div>
    
    <div class="col-md-6">
        <div class="dashboard-card">
            <div class="card-header" style="background: linear-gradient(135deg, #6f42c1, #6610f2);">
                <h5><i class="fas fa-user-tie me-2"></i>Council Members</h5>
            </div>
            <div class="card-body">
                <?php if (empty($council_members)): ?>
                    <p class="text-muted text-center py-3">No council members listed.</p>
                <?php else: ?>
                    <?php foreach ($council_members as $council): ?>
                    <div class="council-item d-flex align-items-center mb-3 pb-2 border-bottom">
                        <div class="council-avatar me-3">
                            <?php 
                            $council_initials = '';
                            $name_parts = explode(' ', $council['full_name'] ?? '');
                            foreach ($name_parts as $part) {
                                if (!empty($part)) {
                                    $council_initials .= strtoupper(substr($part, 0, 1));
                                }
                            }
                            echo $council_initials ?: 'CM';
                            ?>
                        </div>
                        <div class="council-info flex-grow-1">
                            <div class="council-name fw-bold"><?php echo htmlspecialchars($council['full_name'] ?? 'Council Member'); ?></div>
                            <div class="council-position small text-muted"><?php echo htmlspecialchars($council['position'] ?? ''); ?></div>
                            <?php if (!empty($council['contact_number'])): ?>
                            <a href="tel:<?php echo $council['contact_number']; ?>" class="council-contact small">
                                <i class="fas fa-phone-alt"></i> <?php echo htmlspecialchars($council['contact_number']); ?>
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="dashboard-card mt-3">
            <div class="card-header" style="background: linear-gradient(135deg, #6f42c1, #6610f2);">
                <h5><i class="fas fa-users me-2"></i>Chapter Officials</h5>
            </div>
            <div class="card-body">
                <?php if (empty($chapter_officials)): ?>
                    <p class="text-muted text-center py-3">No chapter officials listed.</p>
                <?php else: ?>
                    <?php foreach ($chapter_officials as $official): ?>
                    <div class="council-item d-flex align-items-center mb-3 pb-2 border-bottom">
                        <div class="council-avatar me-3" style="background: linear-gradient(135deg, #28a745, #20c997);">
                            <?php 
                            $official_initials = '';
                            $name_parts = explode(' ', $official['full_name'] ?? '');
                            foreach ($name_parts as $part) {
                                if (!empty($part)) {
                                    $official_initials .= strtoupper(substr($part, 0, 1));
                                }
                            }
                            echo $official_initials ?: 'CO';
                            ?>
                        </div>
                        <div class="council-info flex-grow-1">
                            <div class="council-name fw-bold"><?php echo htmlspecialchars($official['full_name'] ?? 'Official'); ?></div>
                            <div class="council-position small text-muted"><?php echo htmlspecialchars($official['position'] ?? ''); ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="row mt-4">
    <div class="col-12">
        <div class="dashboard-card">
            <div class="card-header" style="background: linear-gradient(135deg, #6f42c1, #6610f2);">
                <h5><i class="fas fa-address-card me-2"></i>Contact Information</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4">
                        <h6><i class="fas fa-map-marker-alt me-2 text-primary"></i>Address</h6>
                        <p>MF 2024<br>Brgy. Singalat, Palayan City<br>Province of Nueva Ecija</p>
                    </div>
                    <div class="col-md-4">
                        <h6><i class="fas fa-phone-alt me-2 text-primary"></i>Contact</h6>
                        <p>Tel. No. (044) 940-6708<br>Email: info@harana.com</p>
                    </div>
                    <div class="col-md-4">
                        <h6><i class="fas fa-clock me-2 text-primary"></i>Office Hours</h6>
                        <p>Monday - Friday: 8:00 AM - 5:00 PM<br>Saturday: 8:00 AM - 12:00 PM</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>