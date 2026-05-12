<?php
// user/organization.php
require_once '../includes/config.php';
require_once '../includes/db_connection.php';
require_once '../includes/security.php';
require_once '../includes/auth.php';
require_once '../includes/user_functions.php';

$auth->requireLogin();
$current_user = $auth->getCurrentUser();

$db = Database::getInstance();

// Get organization statistics
$total_members = $db->getSingle("SELECT COUNT(*) as total FROM members WHERE status = 'active'")['total'] ?? 0;
$total_chapters = $db->getSingle("SELECT COUNT(DISTINCT chapter) as total FROM members WHERE chapter IS NOT NULL AND chapter != ''")['total'] ?? 0;
$total_paid = $db->getSingle("SELECT COALESCE(SUM(amount), 0) as total FROM payments WHERE payment_status = 'confirmed'")['total'] ?? 0;

// Get council members
$council_members = $db->getAll(
    "SELECT * FROM council WHERE status = 'active' ORDER BY 
     CASE position 
         WHEN 'CEO/President' THEN 1
         WHEN 'COO/Vice President' THEN 2
         WHEN 'CFO/Treasurer' THEN 3
         WHEN 'Corporate Secretary' THEN 4
         WHEN 'Book Keeper' THEN 5
         ELSE 99
     END"
);

// Get chapter officials
$chapter_officials = $db->getAll(
    "SELECT full_name, position FROM council 
     WHERE status = 'active' AND (position LIKE '%Coordinator%' OR position LIKE '%Leader%' OR position LIKE '%Officer%')
     ORDER BY position"
);

$csrf_token = Security::generateCSRFToken();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>About NHGL - <?php echo APP_NAME; ?></title>
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
            --dark-color: #2c3e50;
            --light-color: #f8f9fa;
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
        .organization-container {
            padding: 20px;
            max-width: 1400px;
            margin: 0 auto;
        }

        /* Hero Section */
        .hero-section {
            background: linear-gradient(135deg, #375a7f, #2c4a6b);
            color: white;
            padding: 40px;
            border-radius: 16px;
            margin-bottom: 30px;
            position: relative;
            overflow: hidden;
        }

        .hero-section::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
            animation: rotate 20s linear infinite;
        }

        @keyframes rotate {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        .hero-title {
            font-size: 2rem;
            font-weight: 800;
            margin-bottom: 15px;
            position: relative;
            z-index: 2;
        }

        .hero-subtitle {
            font-size: 1rem;
            opacity: 0.9;
            position: relative;
            z-index: 2;
        }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            text-align: center;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 20px rgba(55,90,127,0.15);
        }

        .stat-icon {
            width: 60px;
            height: 60px;
            background: rgba(55,90,127,0.1);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px;
        }

        .stat-icon i {
            font-size: 1.8rem;
            color: #375a7f;
        }

        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            color: #2c3e50;
        }

        .stat-label {
            font-size: 0.85rem;
            color: #6c757d;
        }

        /* Mission & Vision Cards */
        .mv-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }

        .mission-card {
            background: linear-gradient(135deg, #375a7f, #2c4a6b);
            color: white;
            border-radius: 12px;
            padding: 30px;
            height: 100%;
        }

        .vision-card {
            background: linear-gradient(135deg, #28a745, #20c997);
            color: white;
            border-radius: 12px;
            padding: 30px;
            height: 100%;
        }

        .mv-icon {
            font-size: 2.5rem;
            margin-bottom: 20px;
        }

        .mv-title {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 15px;
        }

        .mv-text {
            line-height: 1.6;
            opacity: 0.95;
        }

        /* Core Values */
        .core-values-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .core-value-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            text-align: center;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            transition: all 0.3s ease;
        }

        .core-value-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 20px rgba(55,90,127,0.15);
        }

        .core-value-icon {
            width: 70px;
            height: 70px;
            background: rgba(55,90,127,0.1);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px;
        }

        .core-value-icon i {
            font-size: 2rem;
            color: #375a7f;
        }

        .core-value-title {
            font-weight: 700;
            color: #2c3e50;
            margin-bottom: 10px;
        }

        .core-value-text {
            font-size: 0.85rem;
            color: #6c757d;
        }

        /* Section Header */
        .section-header {
            margin: 30px 0 20px;
            position: relative;
            padding-left: 15px;
            border-left: 4px solid #375a7f;
        }

        .section-header h3 {
            margin: 0;
            font-size: 1.3rem;
            font-weight: 700;
            color: #2c3e50;
        }

        .section-header p {
            margin: 5px 0 0;
            font-size: 0.85rem;
            color: #6c757d;
        }

        /* Council Cards */
        .council-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .council-card {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            transition: all 0.3s ease;
        }

        .council-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 20px rgba(55,90,127,0.15);
        }

        .council-header {
            background: linear-gradient(135deg, #375a7f, #2c4a6b);
            padding: 20px;
            text-align: center;
            color: white;
        }

        .council-avatar {
            width: 80px;
            height: 80px;
            background: rgba(255,255,255,0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px;
            font-size: 2rem;
            font-weight: 600;
        }

        .council-name {
            font-size: 1.1rem;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .council-position {
            font-size: 0.85rem;
            opacity: 0.9;
        }

        .council-body {
            padding: 20px;
        }

        .council-detail {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 10px;
            font-size: 0.85rem;
            color: #6c757d;
        }

        .council-detail i {
            width: 20px;
            color: #375a7f;
        }

        /* Rules Section */
        .rules-section {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            margin-bottom: 30px;
        }

        .rules-header {
            background: linear-gradient(135deg, #dc3545, #c82333);
            padding: 20px;
            color: white;
        }

        .rules-header h4 {
            margin: 0;
            font-weight: 700;
        }

        .rules-header p {
            margin: 5px 0 0;
            opacity: 0.9;
            font-size: 0.85rem;
        }

        .rules-body {
            padding: 25px;
        }

        .rule-category {
            margin-bottom: 25px;
        }

        .rule-category h5 {
            color: #2c3e50;
            font-weight: 700;
            margin-bottom: 15px;
            padding-bottom: 8px;
            border-bottom: 2px solid #e9ecef;
        }

        .rule-list {
            list-style: none;
            padding: 0;
        }

        .rule-list li {
            padding: 8px 0;
            display: flex;
            align-items: flex-start;
            gap: 10px;
            border-bottom: 1px solid #f0f0f0;
        }

        .rule-list li i {
            color: #dc3545;
            margin-top: 3px;
        }

        .benefit-table {
            width: 100%;
            margin-top: 15px;
        }

        .benefit-table th,
        .benefit-table td {
            padding: 8px 12px;
            border: 1px solid #dee2e6;
        }

        .benefit-table th {
            background: #f8f9fa;
            font-weight: 600;
        }

        /* Timeline */
        .timeline {
            position: relative;
            padding-left: 30px;
            margin: 20px 0;
        }

        .timeline-item {
            position: relative;
            padding-bottom: 25px;
            border-left: 2px solid #e9ecef;
            padding-left: 20px;
            margin-left: 10px;
        }

        .timeline-item::before {
            content: '';
            position: absolute;
            left: -7px;
            top: 0;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: #375a7f;
        }

        .timeline-year {
            font-weight: 700;
            color: #375a7f;
            margin-bottom: 5px;
        }

        .timeline-title {
            font-weight: 600;
            margin-bottom: 5px;
        }

        /* Contact Section */
        .contact-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }

        .contact-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }

        .contact-icon {
            width: 50px;
            height: 50px;
            background: rgba(55,90,127,0.1);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px;
        }

        .contact-icon i {
            font-size: 1.5rem;
            color: #375a7f;
        }

        .contact-title {
            font-weight: 700;
            margin-bottom: 10px;
        }

        .contact-text {
            font-size: 0.85rem;
            color: #6c757d;
        }

        @media (max-width: 768px) {
            .mv-grid {
                grid-template-columns: 1fr;
            }
            
            .hero-title {
                font-size: 1.5rem;
            }
            
            .council-grid {
                grid-template-columns: 1fr;
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
                <a href="organization.php" class="list-group-item active"><i class="fas fa-building"></i><span>Organization</span></a>
                <a href="support.php" class="list-group-item"><i class="fas fa-life-ring"></i><span>Support</span></a>
                <a href="../logout.php" class="list-group-item" onclick="return confirm('Are you sure?');"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a>
            </div>
        </div>

        <!-- Main Content -->
        <div id="page-content-wrapper">
            <?php 
            $page_title = 'Organization';
            include '../includes/header.php'; 
            ?>

            <div class="organization-container">
                <!-- Hero Section -->
                <div class="hero-section">
                    <div class="hero-title">
                        Nagkaisang Haranista<br>sa Gintong Luzon Phils, Inc.
                    </div>
                    <div class="hero-subtitle">
                        Building a community of mutual support, financial security, and shared prosperity.<br>
                        <span class="badge bg-light text-dark mt-2">Sec. REG No. CN 700172104 | Est. 2024</span>
                    </div>
                </div>

                <!-- Statistics -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-users"></i>
                        </div>
                        <div class="stat-number"><?php echo number_format($total_members); ?></div>
                        <div class="stat-label">Active Members</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-map-marker-alt"></i>
                        </div>
                        <div class="stat-number"><?php echo number_format($total_chapters); ?></div>
                        <div class="stat-label">Chapters Nationwide</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-hand-holding-usd"></i>
                        </div>
                        <div class="stat-number">₱<?php echo number_format($total_paid / 1000000, 1); ?>M</div>
                        <div class="stat-label">Total Assistance Released</div>
                    </div>
                </div>

                <!-- Mission & Vision -->
                <div class="mv-grid">
                    <div class="mission-card">
                        <div class="mv-icon">
                            <i class="fas fa-bullseye"></i>
                        </div>
                        <div class="mv-title">Our Mission</div>
                        <div class="mv-text">
                            To provide accessible financial assistance and support to our members, fostering a community of mutual help, financial wellness, and sustainable growth for all Haranistas.
                        </div>
                        <div class="mt-3">
                            <i class="fas fa-quote-left me-2"></i> We serve with integrity, compassion, and excellence.
                        </div>
                    </div>
                    <div class="vision-card">
                        <div class="mv-icon">
                            <i class="fas fa-eye"></i>
                        </div>
                        <div class="mv-title">Our Vision</div>
                        <div class="mv-text">
                            A thriving community where every member achieves financial security, experiences mutual support, and contributes to the collective prosperity of all Haranistas.
                        </div>
                        <div class="mt-3">
                            <i class="fas fa-star me-2"></i> Building dreams together.
                        </div>
                    </div>
                </div>

                <!-- Core Values -->
                <div class="section-header">
                    <h3><i class="fas fa-gem me-2"></i>Our Core Values</h3>
                    <p>The principles that guide our organization</p>
                </div>
                <div class="core-values-grid">
                    <div class="core-value-card">
                        <div class="core-value-icon">
                            <i class="fas fa-handshake"></i>
                        </div>
                        <div class="core-value-title">Mutual Help</div>
                        <div class="core-value-text">Bayanihan spirit - helping each other succeed</div>
                    </div>
                    <div class="core-value-card">
                        <div class="core-value-icon">
                            <i class="fas fa-shield-alt"></i>
                        </div>
                        <div class="core-value-title">Financial Security</div>
                        <div class="core-value-text">Building stable financial futures for members</div>
                    </div>
                    <div class="core-value-card">
                        <div class="core-value-icon">
                            <i class="fas fa-heart"></i>
                        </div>
                        <div class="core-value-title">Compassion</div>
                        <div class="core-value-text">Caring for members in times of need</div>
                    </div>
                    <div class="core-value-card">
                        <div class="core-value-icon">
                            <i class="fas fa-chart-line"></i>
                        </div>
                        <div class="core-value-title">Sustainable Growth</div>
                        <div class="core-value-text">Growing together for a better tomorrow</div>
                    </div>
                </div>

                <!-- Council Members -->
                <div class="section-header">
                    <h3><i class="fas fa-user-tie me-2"></i>Council of Leaders</h3>
                    <p>Meet the dedicated leaders guiding our organization</p>
                </div>
                <div class="council-grid">
                    <?php if (empty($council_members)): ?>
                        <div class="council-card">
                            <div class="council-header">
                                <div class="council-avatar">
                                    <i class="fas fa-user-tie"></i>
                                </div>
                                <div class="council-name">Council Member</div>
                                <div class="council-position">Coming Soon</div>
                            </div>
                            <div class="council-body">
                                <div class="council-detail">
                                    <i class="fas fa-info-circle"></i>
                                    <span>Council member information will be updated soon.</span>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <?php foreach ($council_members as $council): ?>
                        <div class="council-card">
                            <div class="council-header">
                                <div class="council-avatar">
                                    <?php 
                                    $initials = '';
                                    $name_parts = explode(' ', $council['full_name'] ?? $council['first_name'] . ' ' . $council['last_name']);
                                    foreach ($name_parts as $part) {
                                        if (!empty($part)) $initials .= strtoupper(substr($part, 0, 1));
                                    }
                                    echo $initials ?: 'CM';
                                    ?>
                                </div>
                                <div class="council-name"><?php echo htmlspecialchars($council['full_name'] ?? $council['first_name'] . ' ' . $council['last_name']); ?></div>
                                <div class="council-position"><?php echo htmlspecialchars($council['position']); ?></div>
                            </div>
                            <div class="council-body">
                                <?php if (!empty($council['contact_number'])): ?>
                                <div class="council-detail">
                                    <i class="fas fa-phone-alt"></i>
                                    <span><?php echo htmlspecialchars($council['contact_number']); ?></span>
                                </div>
                                <?php endif; ?>
                                <?php if (!empty($council['email'])): ?>
                                <div class="council-detail">
                                    <i class="fas fa-envelope"></i>
                                    <span><?php echo htmlspecialchars($council['email']); ?></span>
                                </div>
                                <?php endif; ?>
                                <?php if (!empty($council['term_start'])): ?>
                                <div class="council-detail">
                                    <i class="fas fa-calendar-alt"></i>
                                    <span>Term: <?php echo date('Y', strtotime($council['term_start'])); ?> - <?php echo date('Y', strtotime($council['term_end'] ?? '+4 years')); ?></span>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- Rules & Regulations -->
                <div class="rules-section">
                    <div class="rules-header">
                        <h4><i class="fas fa-gavel me-2"></i>NHGL-Philippines Inc. Rules & Regulations</h4>
                        <p>Revised 2022 - Approved by the Board of Directors</p>
                    </div>
                    <div class="rules-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="rule-category">
                                    <h5><i class="fas fa-user-plus me-2"></i>Membership Requirements</h5>
                                    <ul class="rule-list">
                                        <li><i class="fas fa-check-circle text-success"></i> Personal appearance required</li>
                                        <li><i class="fas fa-file-alt"></i> Application form with data and signature</li>
                                        <li><i class="fas fa-stethoscope"></i> Medical certificate (original copy)</li>
                                        <li><i class="fas fa-birthday-cake"></i> Birth certificate copy (45 y/o and above)</li>
                                        <li><i class="fas fa-money-bill"></i> Membership fee: ₱100.00 + ID fee: ₱100.00</li>
                                        <li><i class="fas fa-id-card"></i> ID and S.A.C.E. fee: ₱60.00</li>
                                        <li><i class="fas fa-calendar-alt"></i> Age requirement: 10-60 years old for regular member</li>
                                    </ul>
                                </div>
                                
                                <div class="rule-category">
                                    <h5><i class="fas fa-chart-line me-2"></i>Death Benefit Scale</h5>
                                    <table class="benefit-table">
                                        <tr><th>Years of Membership</th><th>Benefit Amount</th></tr>
                                        <tr><td>1-2 years</td><td>₱80,000.00</td></tr>
                                        <tr><td>3-4 years</td><td>₱90,000.00</td></tr>
                                        <tr><td>5-6 years</td><td>₱100,000.00</td></tr>
                                        <tr><td>7-8 years</td><td>₱110,000.00</td></tr>
                                        <tr><td>9 years</td><td>₱115,000.00</td></tr>
                                        <tr><td>10 years</td><td>₱120,000.00</td></tr>
                                        <tr><td>11 years</td><td>₱125,000.00</td></tr>
                                        <tr><td>12 years</td><td>₱130,000.00</td></tr>
                                        <tr><td>13 years</td><td>₱135,000.00</td></tr>
                                        <tr><td>14 years</td><td>₱140,000.00</td></tr>
                                        <tr><td>15 years</td><td>₱145,000.00</td></tr>
                                        <tr><td>16+ years</td><td>₱150,000.00</td></tr>
                                    </table>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="rule-category">
                                    <h5><i class="fas fa-plus-circle me-2"></i>Additional Benefits</h5>
                                    <ul class="rule-list">
                                        <li><i class="fas fa-car-crash"></i> <strong>Accidental Death:</strong> Additional ₱10,000.00</li>
                                        <li><i class="fas fa-star"></i> <strong>Good Payer (5 years no missed payments):</strong> Additional ₱5,000.00</li>
                                        <li><i class="fas fa-hand-holding-heart"></i> <strong>Donation for members under 18 months:</strong> Based on membership duration</li>
                                    </ul>
                                </div>
                                
                                <div class="rule-category">
                                    <h5><i class="fas fa-minus-circle me-2"></i>Deductions & Adjustments</h5>
                                    <ul class="rule-list">
                                        <li><strong>1-10 missed payments:</strong> Deduct ₱2,500.00</li>
                                        <li><strong>11-25 missed payments:</strong> Deduct ₱5,000.00</li>
                                        <li><strong>26-50 missed payments:</strong> Deduct ₱10,000.00</li>
                                        <li><strong>51-70 missed payments:</strong> Half of death benefit</li>
                                        <li><strong>71+ missed payments:</strong> Donation based on membership duration</li>
                                    </ul>
                                </div>
                                
                                <div class="rule-category">
                                    <h5><i class="fas fa-clock me-2"></i>Probation Period</h5>
                                    <ul class="rule-list">
                                        <li><strong>51-100 missed payments:</strong> 1 month probation</li>
                                        <li><strong>101-150 missed payments:</strong> 2 months probation</li>
                                        <li><strong>150+ missed payments:</strong> Possible termination of membership</li>
                                    </ul>
                                </div>
                                
                                <div class="alert alert-warning mt-3">
                                    <i class="fas fa-exclamation-triangle me-2"></i>
                                    <strong>Important Notes:</strong>
                                    <ul class="mt-2 mb-0">
                                        <li>Dual membership (member of other organizations) not eligible for full benefits</li>
                                        <li>Any outstanding balance will be deducted from death benefits</li>
                                        <li>Benefits only given to legitimate beneficiary or authorized representative</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- History Timeline -->
                <div class="section-header">
                    <h3><i class="fas fa-history me-2"></i>Our Journey</h3>
                    <p>The story of Harana Financial System</p>
                </div>
                <div class="timeline">
                    <div class="timeline-item">
                        <div class="timeline-year">2024</div>
                        <div class="timeline-title">Official Registration</div>
                        <div class="timeline-text text-muted">NHGL, Inc. officially registered under Sec. REG No. CN 700172104, marking the formal establishment of our organization.</div>
                    </div>
                    <div class="timeline-item">
                        <div class="timeline-year">2025</div>
                        <div class="timeline-title">First 500 Members</div>
                        <div class="timeline-text text-muted">Reached 500 active members across multiple chapters in Luzon, expanding our reach and impact.</div>
                    </div>
                    <div class="timeline-item">
                        <div class="timeline-year">2026</div>
                        <div class="timeline-title">Digital Transformation</div>
                        <div class="timeline-text text-muted">Launched the Harana Financial Management System, bringing modern digital services to all members.</div>
                    </div>
                    <div class="timeline-item">
                        <div class="timeline-year">2027+</div>
                        <div class="timeline-title">Vision 2030</div>
                        <div class="timeline-text text-muted">Aiming to reach 10,000 members nationwide and establish comprehensive member benefits program.</div>
                    </div>
                </div>

                <!-- Contact Information -->
                <div class="section-header">
                    <h3><i class="fas fa-address-card me-2"></i>Contact Information</h3>
                    <p>Get in touch with us</p>
                </div>
                <div class="contact-grid">
                    <div class="contact-card">
                        <div class="contact-icon">
                            <i class="fas fa-map-marker-alt"></i>
                        </div>
                        <div class="contact-title">Address</div>
                        <div class="contact-text">
                            MF 2024, Brgy. Singalat<br>
                            Palayan City, Nueva Ecija<br>
                            Philippines
                        </div>
                    </div>
                    <div class="contact-card">
                        <div class="contact-icon">
                            <i class="fas fa-phone-alt"></i>
                        </div>
                        <div class="contact-title">Contact Numbers</div>
                        <div class="contact-text">
                            Tel: (044) 940-6708<br>
                            Mobile: 0917 123 4567
                        </div>
                    </div>
                    <div class="contact-card">
                        <div class="contact-icon">
                            <i class="fas fa-envelope"></i>
                        </div>
                        <div class="contact-title">Email</div>
                        <div class="contact-text">
                            info@harana.org.ph<br>
                            support@harana.org.ph
                        </div>
                    </div>
                    <div class="contact-card">
                        <div class="contact-icon">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div class="contact-title">Office Hours</div>
                        <div class="contact-text">
                            Mon-Fri: 8:00 AM - 5:00 PM<br>
                            Sat: 8:00 AM - 12:00 PM<br>
                            Sun: Closed
                        </div>
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
    </script>
</body>
</html>