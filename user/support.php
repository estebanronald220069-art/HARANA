<?php
// user/support.php
require_once '../includes/config.php';
require_once '../includes/db_connection.php';
require_once '../includes/security.php';
require_once '../includes/auth.php';
require_once '../includes/user_functions.php';

$auth->requireLogin();
$current_user = $auth->getCurrentUser();

$db = Database::getInstance();
$member = getUserMemberData($db, $current_user);

if (!$member || empty($member['member_code'])) {
    $member_code = null;
    $member_name = $current_user['full_name'] ?? '';
    $member_email = $current_user['email'] ?? '';
} else {
    $member_code = $member['member_code'];
    $member_name = ($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? '');
    $member_email = $member['email'] ?? $current_user['email'] ?? '';
}

$message = '';
$error = '';

// Handle contact form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message'])) {
    if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token';
    } else {
        $name = Security::sanitize($_POST['name'] ?? '');
        $email = Security::sanitize($_POST['email'] ?? '');
        $subject = Security::sanitize($_POST['subject'] ?? '');
        $category = Security::sanitize($_POST['category'] ?? '');
        $user_message = Security::sanitize($_POST['message'] ?? '');
        
        if (empty($name) || empty($email) || empty($subject) || empty($category) || empty($user_message)) {
            $error = 'Please fill in all required fields.';
        } else {
            // Save to database
            $insert = $db->execute(
                "INSERT INTO support_messages (user_id, member_code, name, email, subject, category, message, status, created_at) 
                 VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', NOW())",
                [$current_user['user_id'], $member_code, $name, $email, $subject, $category, $user_message],
                'issssss'
            );
            
            if ($insert) {
                $message = 'Your message has been sent successfully! We will respond within 24-48 hours.';
                
                // Create notification for admin (optional - can be added later)
                
                // Clear form data
                $_POST = [];
            } else {
                $error = 'Failed to send message. Please try again.';
            }
        }
    }
}

// Get user's previous messages
$user_messages = $db->getAll(
    "SELECT * FROM support_messages 
     WHERE user_id = ? 
     ORDER BY created_at DESC LIMIT 10",
    [$current_user['user_id']], 'i'
);

// Get FAQs from database
$faqs = $db->getAll(
    "SELECT * FROM faqs 
     WHERE is_active = 1 
     ORDER BY display_order ASC, faq_id ASC"
);

// If no FAQs in database, seed with default ones
if (empty($faqs)) {
    $default_faqs = [
        ['category' => 'Membership', 'question' => 'How do I become a member?', 'answer' => 'To become a member, you need to fill out the membership application form, submit a medical certificate (original copy), birth certificate copy (if 45 years and above), and pay the membership fee of ₱100.00 plus ID fee of ₱100.00.'],
        ['category' => 'Membership', 'question' => 'What is the age requirement for membership?', 'answer' => 'Regular members must be between 10-60 years old. Beneficiaries who become members must be 10-60 years old and are limited to legitimate family members of deceased members.'],
        ['category' => 'Payments', 'question' => 'How do I make my monthly contribution?', 'answer' => 'You can pay your monthly contribution through: 1) Cash payments at our office (MF 2024, Brgy. Singalat, Palayan City), 2) GCash (0917 123 4567), or 3) Bank Transfer (BDO Account # 1234-5678-90).'],
        ['category' => 'Payments', 'question' => 'What is the monthly contribution amount?', 'answer' => 'The monthly contribution is ₱100.00. You also pay a monthly "butaw" (due) of ₱10.00, and ₱20.00 for death benefit fund per deceased member.'],
        ['category' => 'Benefits', 'question' => 'What are the death benefits?', 'answer' => 'Death benefits vary by membership duration: 1-2 years: ₱80,000, 3-4 years: ₱90,000, 5-6 years: ₱100,000, up to ₱150,000 for 16+ years. Additional ₱10,000 for accidental death and ₱5,000 for good payers (5+ years no missed payments).'],
        ['category' => 'Benefits', 'question' => 'What is the Damayan program?', 'answer' => 'Damayan is our mutual aid program providing emergency financial assistance for medical emergencies (₱10,000), hospitalization (₱15,000), calamity assistance (₱5,000), and burial assistance (₱20,000) for active members.'],
        ['category' => 'Beneficiary', 'question' => 'How do I update my beneficiary?', 'answer' => 'To update your beneficiary, go to the Beneficiary page in your dashboard, fill out the form with the new beneficiary details, upload supporting documents (birth certificate, marriage certificate, valid ID), and submit. Updates require admin approval within 3-5 business days.'],
        ['category' => 'Beneficiary', 'question' => 'Who can be my beneficiary?', 'answer' => 'Beneficiaries must be legitimate family members (spouse, child, parent, or legal dependent) aged 10-60 years old. Only ONE beneficiary can be designated at a time.'],
        ['category' => 'General', 'question' => 'What happens if I miss a payment?', 'answer' => 'If you miss payments, penalties apply: 1-10 missed payments: deduct ₱2,500 from benefits, 11-25: deduct ₱5,000, 26-50: deduct ₱10,000, 51-70: half of death benefit, 71+: donation based on membership duration.'],
        ['category' => 'General', 'question' => 'What is dual membership?', 'answer' => 'Dual membership means being a member of other organizations besides NHGL. Dual members are not eligible for full benefits and do not have the same privileges as loyal members.'],
    ];
    
    foreach ($default_faqs as $faq) {
        $db->execute(
            "INSERT INTO faqs (category, question, answer, is_active) VALUES (?, ?, ?, 1)",
            [$faq['category'], $faq['question'], $faq['answer']],
            'sss'
        );
    }
    
    // Reload FAQs
    $faqs = $db->getAll(
        "SELECT * FROM faqs WHERE is_active = 1 ORDER BY display_order ASC, faq_id ASC"
    );
}

// Group FAQs by category
$faqs_by_category = [];
foreach ($faqs as $faq) {
    $faqs_by_category[$faq['category']][] = $faq;
}

$csrf_token = Security::generateCSRFToken();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Support Center - <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #375a7f;
            --secondary-color: #2c4a6b;
            --success-color: #28a745;
            --warning-color: #ffc107;
            --danger-color: #dc3545;
            --info-color: #17a2b8;
        }

        * {
            box-sizing: border-box;
        }

        body {
            background: #f4f7fc;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            overflow: hidden;
            margin: 0;
            padding: 0;
        }

        #wrapper {
            display: flex;
            width: 100%;
            height: 100vh;
            overflow: hidden;
        }

        /* Sidebar Styles */
        #sidebar-wrapper {
            background: #375a7f;
            color: #fff;
            width: 250px;
            height: 100vh;
            overflow-y: auto;
            transition: width 0.3s ease;
            box-shadow: 4px 0 10px rgba(0,0,0,0.1);
            white-space: nowrap;
        }

        #sidebar-wrapper.collapsed {
            width: 70px;
        }

        #sidebar-wrapper.collapsed .sidebar-heading span {
            display: none;
        }

        #sidebar-wrapper.collapsed .list-group-item span {
            display: none;
        }

        #sidebar-wrapper.collapsed .list-group-item i {
            margin-right: 0;
            width: 100%;
            text-align: center;
            font-size: 1.2rem;
        }

        #sidebar-wrapper.collapsed .list-group-item {
            padding: 15px 0;
            text-align: center;
        }

        #sidebar-wrapper.collapsed .badge {
            display: none;
        }

        #sidebar-wrapper.collapsed .sidebar-heading img {
            display: none;
        }

        #sidebar-wrapper .sidebar-heading {
            padding: 1.2rem 1rem;
            font-size: 1.4rem;
            font-weight: 600;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            background: #375a7f;
            color: white;
            text-align: left;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        #sidebar-wrapper.collapsed .sidebar-heading {
            justify-content: center;
            padding: 1.2rem 0;
        }

        #sidebar-wrapper .sidebar-heading img {
            height: 30px;
            width: auto;
            margin-right: 10px;
            vertical-align: middle;
        }

        .menu-toggle {
            background: transparent;
            border: none;
            font-size: 1.5rem;
            color: white;
            cursor: pointer;
            padding: 0 10px;
            transition: all 0.2s;
        }

        .menu-toggle:hover {
            color: #fff;
            transform: scale(1.1);
        }

        .header-logo {
            height: 30px;
            width: auto;
            margin-right: 10px;
            vertical-align: middle;
            transition: all 0.3s ease;
            display: none;
        }

        #sidebar-wrapper.collapsed ~ #page-content-wrapper .header-logo {
            display: inline-block;
        }

        #sidebar-wrapper .list-group-item {
            background: transparent;
            border: none;
            color: rgba(255,255,255,0.9);
            padding: 0.8rem 1.2rem;
            font-weight: 500;
            transition: all 0.2s;
            font-size: 0.95rem;
            text-align: left;
        }

        #sidebar-wrapper.collapsed .list-group-item {
            padding: 15px 0;
            text-align: center;
        }

        #sidebar-wrapper .list-group-item:hover,
        #sidebar-wrapper .list-group-item.active {
            background: rgba(255,255,255,0.15);
            color: #fff;
            border-left: 4px solid #fff;
        }

        #sidebar-wrapper.collapsed .list-group-item:hover,
        #sidebar-wrapper.collapsed .list-group-item.active {
            border-left: none;
            border-bottom: 2px solid #fff;
        }

        #sidebar-wrapper .list-group-item i {
            width: 24px;
            text-align: center;
            margin-right: 10px;
            font-size: 1rem;
            transition: all 0.3s ease;
        }

        #sidebar-wrapper.collapsed .list-group-item i {
            margin-right: 0;
            width: 100%;
            font-size: 1.2rem;
        }

        #page-content-wrapper {
            flex: 1;
            background: #f4f7fc;
            height: 100vh;
            overflow-y: auto;
            padding: 0;
        }

        /* Navbar */
        .navbar {
            background: #fff !important;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            padding: 0.7rem 1.2rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .navbar-left {
            display: flex;
            align-items: center;
        }

        .navbar-brand {
            font-size: 1.2rem;
            font-weight: 500;
            color: #375a7f !important;
            display: flex;
            align-items: center;
        }

        .navbar-brand i {
            color: #375a7f;
        }

        .navbar-right {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        /* Container */
        .support-container {
            padding: 20px;
            max-width: 1400px;
            margin: 0 auto;
        }

        /* Grid Layout */
        .support-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
        }

        @media (max-width: 992px) {
            .support-grid {
                grid-template-columns: 1fr;
            }
        }

        /* Cards */
        .support-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            overflow: hidden;
            margin-bottom: 20px;
        }

        .support-card-header {
            padding: 15px 20px;
            background: #f8f9fa;
            border-bottom: 1px solid #e9ecef;
        }

        .support-card-header h5 {
            margin: 0;
            font-weight: 600;
            color: #2c3e50;
            font-size: 1rem;
        }

        .support-card-header i {
            margin-right: 8px;
            color: #375a7f;
        }

        .support-card-body {
            padding: 20px;
        }

        /* Contact Cards */
        .contact-item {
            display: flex;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid #e9ecef;
        }

        .contact-item:last-child {
            border-bottom: none;
        }

        .contact-icon {
            width: 45px;
            height: 45px;
            background: rgba(55,90,127,0.1);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
        }

        .contact-icon i {
            font-size: 1.2rem;
            color: #375a7f;
        }

        .contact-info h6 {
            margin: 0;
            font-weight: 600;
            font-size: 0.9rem;
        }

        .contact-info p {
            margin: 0;
            font-size: 0.85rem;
            color: #6c757d;
        }

        /* FAQ Accordion */
        .faq-item {
            border-bottom: 1px solid #e9ecef;
        }

        .faq-question {
            padding: 15px 0;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-weight: 600;
            color: #2c3e50;
        }

        .faq-question:hover {
            color: #375a7f;
        }

        .faq-answer {
            padding: 0 0 15px 0;
            display: none;
            color: #6c757d;
            font-size: 0.85rem;
            line-height: 1.6;
        }

        .faq-answer.show {
            display: block;
        }

        /* Category Tags */
        .category-tag {
            display: inline-block;
            padding: 4px 12px;
            background: rgba(55,90,127,0.1);
            color: #375a7f;
            border-radius: 20px;
            font-size: 0.7rem;
            margin-bottom: 10px;
        }

        /* Form Styles */
        .form-control, .form-select {
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 10px 12px;
            font-size: 0.9rem;
        }

        .form-control:focus, .form-select:focus {
            border-color: #375a7f;
            box-shadow: 0 0 0 0.2rem rgba(55,90,127,0.1);
        }

        .btn-submit {
            background: #375a7f;
            color: white;
            border: none;
            padding: 12px;
            border-radius: 8px;
            font-weight: 600;
            width: 100%;
            transition: all 0.3s;
        }

        .btn-submit:hover {
            background: #2c4a6b;
            transform: translateY(-2px);
        }

        /* Message History */
        .message-item {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 12px;
            margin-bottom: 10px;
        }

        .message-subject {
            font-weight: 600;
            margin-bottom: 5px;
        }

        .message-meta {
            font-size: 0.7rem;
            color: #6c757d;
            margin-bottom: 8px;
        }

        .message-status {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 0.65rem;
            font-weight: 600;
        }

        .status-pending {
            background: #fff3cd;
            color: #856404;
        }

        .status-read {
            background: #cce5ff;
            color: #004085;
        }

        .status-replied {
            background: #d4edda;
            color: #155724;
        }

        /* Emergency Banner */
        .emergency-banner {
            background: linear-gradient(135deg, #dc3545, #c82333);
            border-radius: 12px;
            padding: 20px;
            color: white;
            text-align: center;
            margin-bottom: 20px;
        }

        .emergency-banner h4 {
            margin: 0;
            font-size: 1.2rem;
        }

        .emergency-banner p {
            margin: 5px 0 0;
            font-size: 0.85rem;
            opacity: 0.9;
        }

        .emergency-number {
            font-size: 1.5rem;
            font-weight: 700;
            margin-top: 10px;
        }

        /* Social Links */
        .social-links {
            display: flex;
            gap: 15px;
            margin-top: 15px;
        }

        .social-link {
            width: 40px;
            height: 40px;
            background: rgba(55,90,127,0.1);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #375a7f;
            transition: all 0.3s;
        }

        .social-link:hover {
            background: #375a7f;
            color: white;
            transform: translateY(-3px);
        }

        /* Responsive */
        @media (max-width: 768px) {
            .emergency-number {
                font-size: 1.2rem;
            }
        }
    </style>
</head>
<body>
    <div id="wrapper">
        <!-- Sidebar -->
        <div id="sidebar-wrapper">
            <div class="sidebar-heading">
                <img src="../assets/images/harana-logo.png" alt="Harana" onerror="this.src=''; this.onerror=null; this.innerHTML='Harana';">
                <span>Harana</span>
                <button class="menu-toggle" id="menuToggle">
                    <i class="fas fa-bars"></i>
                </button>
            </div>
            <div class="list-group list-group-flush mt-2">
                <a href="dashboard.php" class="list-group-item"><i class="fas fa-tachometer-alt"></i><span>Dashboard</span></a>
                <a href="profile.php" class="list-group-item"><i class="fas fa-user"></i><span>My Profile</span></a>
                <a href="payments.php" class="list-group-item"><i class="fas fa-credit-card"></i><span>Payment History</span></a>
                <a href="beneficiary.php" class="list-group-item"><i class="fas fa-heart"></i><span>Beneficiary</span></a>
                <a href="notifications.php" class="list-group-item"><i class="fas fa-bell"></i><span>Notifications</span></a>
                <a href="organization.php" class="list-group-item"><i class="fas fa-building"></i><span>Organization</span></a>
                <a href="support.php" class="list-group-item active"><i class="fas fa-life-ring"></i><span>Support</span></a>
                <a href="../logout.php" class="list-group-item" onclick="return confirm('Are you sure?');"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a>
            </div>
        </div>

        <!-- Main Content -->
        <div id="page-content-wrapper">
            <?php 
            $page_title = 'Support Center';
            include '../includes/header.php'; 
            ?>

            <div class="support-container">
                <?php if ($message): ?>
                    <div class="alert alert-success alert-dismissible fade show mb-3">
                        <i class="fas fa-check-circle me-2"></i><?php echo $message; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show mb-3">
                        <i class="fas fa-exclamation-circle me-2"></i><?php echo $error; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Emergency Banner -->
                <div class="emergency-banner">
                    <h4><i class="fas fa-phone-alt me-2"></i>Emergency Hotline</h4>
                    <p>For urgent concerns and emergencies</p>
                    <div class="emergency-number">
                        <i class="fas fa-mobile-alt me-2"></i>(044) 940-6708 | 0917 123 4567
                    </div>
                </div>

                <div class="support-grid">
                    <!-- Left Column - Contact Info & FAQ -->
                    <div>
                        <!-- Contact Information -->
                        <div class="support-card">
                            <div class="support-card-header">
                                <h5><i class="fas fa-address-card"></i> Contact Information</h5>
                            </div>
                            <div class="support-card-body">
                                <div class="contact-item">
                                    <div class="contact-icon">
                                        <i class="fas fa-map-marker-alt"></i>
                                    </div>
                                    <div class="contact-info">
                                        <h6>Office Address</h6>
                                        <p>MF 2024, Brgy. Singalat, Palayan City, Nueva Ecija</p>
                                    </div>
                                </div>
                                <div class="contact-item">
                                    <div class="contact-icon">
                                        <i class="fas fa-phone-alt"></i>
                                    </div>
                                    <div class="contact-info">
                                        <h6>Phone Numbers</h6>
                                        <p>Tel: (044) 940-6708<br>Mobile: 0917 123 4567</p>
                                    </div>
                                </div>
                                <div class="contact-item">
                                    <div class="contact-icon">
                                        <i class="fas fa-envelope"></i>
                                    </div>
                                    <div class="contact-info">
                                        <h6>Email Addresses</h6>
                                        <p>info@harana.org.ph<br>support@harana.org.ph</p>
                                    </div>
                                </div>
                                <div class="contact-item">
                                    <div class="contact-icon">
                                        <i class="fas fa-clock"></i>
                                    </div>
                                    <div class="contact-info">
                                        <h6>Office Hours</h6>
                                        <p>Monday - Friday: 8:00 AM - 5:00 PM<br>Saturday: 8:00 AM - 12:00 PM<br>Sunday: Closed</p>
                                    </div>
                                </div>
                                <div class="social-links">
                                    <a href="#" class="social-link"><i class="fab fa-facebook-f"></i></a>
                                    <a href="#" class="social-link"><i class="fab fa-twitter"></i></a>
                                    <a href="#" class="social-link"><i class="fab fa-instagram"></i></a>
                                    <a href="#" class="social-link"><i class="fab fa-youtube"></i></a>
                                </div>
                            </div>
                        </div>

                        <!-- Frequently Asked Questions -->
                        <div class="support-card">
                            <div class="support-card-header">
                                <h5><i class="fas fa-question-circle"></i> Frequently Asked Questions</h5>
                            </div>
                            <div class="support-card-body">
                                <?php foreach ($faqs_by_category as $category => $category_faqs): ?>
                                    <div class="mb-4">
                                        <div class="category-tag"><?php echo htmlspecialchars($category); ?></div>
                                        <?php foreach ($category_faqs as $faq): ?>
                                        <div class="faq-item">
                                            <div class="faq-question" onclick="toggleFAQ(this)">
                                                <?php echo htmlspecialchars($faq['question']); ?>
                                                <i class="fas fa-chevron-down"></i>
                                            </div>
                                            <div class="faq-answer">
                                                <?php echo nl2br(htmlspecialchars($faq['answer'])); ?>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Right Column - Contact Form & Message History -->
                    <div>
                        <!-- Contact Form -->
                        <div class="support-card">
                            <div class="support-card-header">
                                <h5><i class="fas fa-paper-plane"></i> Send Us a Message</h5>
                            </div>
                            <div class="support-card-body">
                                <form method="POST" action="">
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                    
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Your Name <span class="text-danger">*</span></label>
                                            <input type="text" class="form-control" name="name" 
                                                   value="<?php echo htmlspecialchars($member_name ?: $current_user['full_name']); ?>" required>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Email Address <span class="text-danger">*</span></label>
                                            <input type="email" class="form-control" name="email" 
                                                   value="<?php echo htmlspecialchars($member_email ?: $current_user['email']); ?>" required>
                                        </div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label">Subject <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" name="subject" required>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label">Category <span class="text-danger">*</span></label>
                                        <select class="form-select" name="category" required>
                                            <option value="">Select Category</option>
                                            <option value="Payment Issue">Payment Issue</option>
                                            <option value="Account Problem">Account Problem</option>
                                            <option value="Beneficiary">Beneficiary Concern</option>
                                            <option value="Death Benefits">Death Benefits</option>
                                            <option value="Damayan">Damayan / Financial Assistance</option>
                                            <option value="Membership">Membership Inquiry</option>
                                            <option value="Technical">Technical Issue</option>
                                            <option value="General">General Inquiry</option>
                                            <option value="Other">Other</option>
                                        </select>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label">Message <span class="text-danger">*</span></label>
                                        <textarea class="form-control" name="message" rows="5" required></textarea>
                                    </div>
                                    
                                    <button type="submit" name="send_message" class="btn-submit">
                                        <i class="fas fa-paper-plane me-2"></i>Send Message
                                    </button>
                                </form>
                            </div>
                        </div>

                        <!-- Message History -->
                        <?php if (!empty($user_messages)): ?>
                        <div class="support-card">
                            <div class="support-card-header">
                                <h5><i class="fas fa-history"></i> Your Message History</h5>
                            </div>
                            <div class="support-card-body">
                                <?php foreach ($user_messages as $msg): ?>
                                <div class="message-item">
                                    <div class="message-subject">
                                        <?php echo htmlspecialchars($msg['subject']); ?>
                                        <span class="message-status status-<?php echo $msg['status']; ?> float-end">
                                            <?php echo ucfirst($msg['status']); ?>
                                        </span>
                                    </div>
                                    <div class="message-meta">
                                        <i class="fas fa-tag me-1"></i><?php echo htmlspecialchars($msg['category']); ?>
                                        <span class="mx-2">•</span>
                                        <i class="fas fa-calendar-alt me-1"></i><?php echo date('M d, Y', strtotime($msg['created_at'])); ?>
                                    </div>
                                    <div class="message-preview small text-muted">
                                        <?php echo htmlspecialchars(substr($msg['message'], 0, 100)); ?>...
                                    </div>
                                    <?php if ($msg['admin_reply']): ?>
                                    <div class="mt-2 pt-2 border-top">
                                        <small class="text-success">
                                            <i class="fas fa-reply me-1"></i> Admin replied: 
                                            <?php echo htmlspecialchars(substr($msg['admin_reply'], 0, 80)); ?>...
                                        </small>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Support Services Section -->
                <div class="support-card mt-3">
                    <div class="support-card-header">
                        <h5><i class="fas fa-hand-holding-heart"></i> Support Services Available</h5>
                    </div>
                    <div class="support-card-body">
                        <div class="row">
                            <div class="col-md-3 col-sm-6 mb-3">
                                <div class="text-center">
                                    <i class="fas fa-hand-holding-usd fa-2x text-primary mb-2"></i>
                                    <h6 class="mb-0">Damayan</h6>
                                    <small class="text-muted">Emergency financial assistance</small>
                                </div>
                            </div>
                            <div class="col-md-3 col-sm-6 mb-3">
                                <div class="text-center">
                                    <i class="fas fa-heartbeat fa-2x text-danger mb-2"></i>
                                    <h6 class="mb-0">Death Benefits</h6>
                                    <small class="text-muted">Burial & death assistance</small>
                                </div>
                            </div>
                            <div class="col-md-3 col-sm-6 mb-3">
                                <div class="text-center">
                                    <i class="fas fa-graduation-cap fa-2x text-success mb-2"></i>
                                    <h6 class="mb-0">Educational Support</h6>
                                    <small class="text-muted">Scholarships & assistance</small>
                                </div>
                            </div>
                            <div class="col-md-3 col-sm-6 mb-3">
                                <div class="text-center">
                                    <i class="fas fa-chart-line fa-2x text-info mb-2"></i>
                                    <h6 class="mb-0">Livelihood Support</h6>
                                    <small class="text-muted">Business & livelihood loans</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Map Section -->
                <div class="support-card mt-3">
                    <div class="support-card-header">
                        <h5><i class="fas fa-map"></i> Our Location</h5>
                    </div>
                    <div class="support-card-body p-0">
                        <iframe 
                            src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d15432.567890123456!2d121.083333!3d15.416667!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x3390c5e2b3c5a2a1%3A0x9b8c5a2a1b3c5a2a!2sPalayan%20City%2C%20Nueva%20Ecija!5e0!3m2!1sen!2sph!4v1700000000000!5m2!1sen!2sph" 
                            width="100%" 
                            height="250" 
                            style="border:0;" 
                            allowfullscreen="" 
                            loading="lazy"
                            referrerpolicy="no-referrer-when-downgrade">
                        </iframe>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Sidebar Toggle
        const menuToggle = document.getElementById('menuToggle');
        const sidebar = document.getElementById('sidebar-wrapper');
        const headerLogo = document.getElementById('headerLogo');
        
        const sidebarCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';
        
        if (sidebarCollapsed) {
            sidebar.classList.add('collapsed');
        }
        
        if (menuToggle) {
            menuToggle.addEventListener('click', function() {
                sidebar.classList.toggle('collapsed');
                localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
            });
        }

        // Handle logo fallback
        const sidebarLogo = document.querySelector('.sidebar-heading img');
        if (sidebarLogo) {
            sidebarLogo.onerror = function() {
                this.style.display = 'none';
                this.nextSibling.textContent = 'Harana';
            };
        }
        
        if (headerLogo) {
            headerLogo.onerror = function() {
                this.style.display = 'none';
            };
        }

        // FAQ Toggle
        function toggleFAQ(element) {
            const answer = element.nextElementSibling;
            const icon = element.querySelector('i');
            
            answer.classList.toggle('show');
            
            if (answer.classList.contains('show')) {
                icon.classList.remove('fa-chevron-down');
                icon.classList.add('fa-chevron-up');
            } else {
                icon.classList.remove('fa-chevron-up');
                icon.classList.add('fa-chevron-down');
            }
        }
    </script>
</body>
</html>