<?php
// Landingpage.php - Public Landing Page
require_once 'includes/config.php';
require_once 'includes/db_connection.php';
require_once 'includes/security.php';

$db = Database::getInstance();

// Get organization stats for display (excluding deleted members)
$member_count = $db->getSingle("SELECT COUNT(*) as total FROM members WHERE status = 'active'")['total'] ?? 0;
$council_count = $db->getSingle("SELECT COUNT(*) as total FROM council WHERE status = 'active' AND is_deleted = 0")['total'] ?? 0;
$chapters_count = $db->getSingle("SELECT COUNT(DISTINCT chapter) as total FROM members WHERE chapter IS NOT NULL AND chapter != ''")['total'] ?? 0;

// Get council members for display with photos (only active and not deleted)
$council_members = $db->getAll("SELECT council_id, full_name, position, photo FROM council WHERE status = 'active' AND is_deleted = 0 ORDER BY position LIMIT 6");

$csrf_token = Security::generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo APP_NAME; ?> - Financial Security & Community Support</title>
    <meta name="description" content="Join Harana Financial System - A community-based financial assistance program providing security, support, and financial wellness for members.">
    <meta name="keywords" content="financial assistance, community support, membership, savings, loans, Philippines">
    
    <!-- Open Graph / Social Media Meta Tags -->
    <meta property="og:title" content="<?php echo APP_NAME; ?> - Financial Security & Community Support">
    <meta property="og:description" content="Join our community-based financial assistance program for security and support.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="<?php echo BASE_URL; ?>">
    
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="assets/images/favicon.png">
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <!-- AOS Animation Library -->
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    
    <style>
        :root {
            --primary-color: #375a7f;
            --secondary-color: #2c4a6b;
            --accent-color: #e67e22;
            --dark-color: #2c3e50;
            --light-color: #f8f9fa;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            overflow-x: hidden;
            color: #333;
        }
        
        /* Navigation - Matching Admin Sidebar Colors */
        .navbar {
            background: linear-gradient(135deg, #375a7f 0%, #2c4a6b 100%);
            box-shadow: 0 2px 20px rgba(0,0,0,0.1);
            padding: 1rem 0;
            transition: all 0.3s ease;
        }
        
        .navbar.scrolled {
            padding: 0.5rem 0;
            background: linear-gradient(135deg, #375a7f 0%, #2c4a6b 100%);
        }
        
        .navbar-brand {
            font-size: 1.8rem;
            font-weight: 700;
            color: white !important;
        }
        
        .navbar-brand i {
            color: white;
            margin-right: 10px;
        }
        
        .nav-link {
            color: rgba(255,255,255,0.9) !important;
            font-weight: 500;
            margin: 0 10px;
            position: relative;
            transition: all 0.3s ease;
        }
        
        .nav-link::after {
            content: '';
            position: absolute;
            bottom: -5px;
            left: 0;
            width: 0;
            height: 2px;
            background: white;
            transition: width 0.3s ease;
        }
        
        .nav-link:hover::after {
            width: 100%;
        }
        
        .nav-link:hover {
            color: white !important;
        }
        
        .btn-login {
            background: white;
            color: #375a7f !important;
            border-radius: 50px;
            padding: 8px 25px !important;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.2);
            color: #375a7f !important;
        }
        
        .btn-login::after {
            display: none;
        }
        
        /* Hero Section */
        .hero {
            min-height: 100vh;
            display: flex;
            align-items: center;
            position: relative;
            overflow: hidden;
            background: linear-gradient(135deg, #f5f7fa 0%, #e9ecef 100%);
        }
        
        .hero-content {
            position: relative;
            z-index: 2;
            padding: 100px 0;
        }
        
        .hero h1 {
            font-size: 3.5rem;
            font-weight: 800;
            color: var(--dark-color);
            line-height: 1.2;
            margin-bottom: 20px;
        }
        
        .hero h1 span {
            color: #375a7f;
        }
        
        .hero p {
            font-size: 1.2rem;
            color: #6c757d;
            margin-bottom: 30px;
        }
        
        .hero-buttons .btn {
            padding: 12px 35px;
            font-weight: 600;
            border-radius: 50px;
            margin-right: 15px;
            transition: all 0.3s ease;
        }
        
        .btn-primary-custom {
            background: linear-gradient(135deg, #375a7f, #2c4a6b);
            color: white;
            border: none;
            box-shadow: 0 4px 15px rgba(55,90,127,0.3);
        }
        
        .btn-primary-custom:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(55,90,127,0.4);
            color: white;
        }
        
        .btn-outline-custom {
            border: 2px solid #375a7f;
            color: #375a7f;
            background: transparent;
        }
        
        .btn-outline-custom:hover {
            background: linear-gradient(135deg, #375a7f, #2c4a6b);
            color: white;
            border-color: transparent;
        }
        
        .hero-image {
            position: relative;
            z-index: 2;
            border-radius: 30px;
            overflow: hidden;
            box-shadow: 0 20px 40px rgba(0,0,0,0.2);
            animation: float 6s ease-in-out infinite;
        }
        
        .hero-image img {
            max-width: 100%;
            height: auto;
            display: block;
        }
        
        @keyframes float {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-20px); }
        }
        
        .hero-wave {
            position: absolute;
            bottom: 0;
            left: 0;
            width: 100%;
            z-index: 1;
        }
        
        /* Stats Section */
        .stats-section {
            padding: 80px 0;
            background: white;
        }
        
        .stat-card {
            text-align: center;
            padding: 30px;
            border-radius: 15px;
            background: white;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 15px 40px rgba(55,90,127,0.2);
        }
        
        .stat-icon {
            width: 80px;
            height: 80px;
            margin: 0 auto 20px;
            background: linear-gradient(135deg, rgba(55,90,127,0.1), rgba(44,74,107,0.1));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .stat-icon i {
            font-size: 2.5rem;
            color: #375a7f;
        }
        
        .stat-number {
            font-size: 2.5rem;
            font-weight: 800;
            color: var(--dark-color);
            margin-bottom: 5px;
        }
        
        .stat-label {
            color: #6c757d;
            font-size: 1.1rem;
            font-weight: 500;
        }
        
        /* Features Section */
        .features-section {
            padding: 80px 0;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
        }
        
        .section-title {
            text-align: center;
            margin-bottom: 60px;
        }
        
        .section-title h2 {
            font-size: 2.5rem;
            font-weight: 800;
            color: var(--dark-color);
            margin-bottom: 15px;
        }
        
        .section-title p {
            font-size: 1.1rem;
            color: #6c757d;
            max-width: 700px;
            margin: 0 auto;
        }
        
        .feature-card {
            background: white;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
            height: 100%;
        }
        
        .feature-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 15px 40px rgba(55,90,127,0.2);
        }
        
        .feature-icon {
            width: 70px;
            height: 70px;
            background: linear-gradient(135deg, rgba(55,90,127,0.1), rgba(44,74,107,0.1));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 20px;
        }
        
        .feature-icon i {
            font-size: 2rem;
            color: #375a7f;
        }
        
        .feature-card h3 {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--dark-color);
            margin-bottom: 15px;
        }
        
        .feature-card p {
            color: #6c757d;
            margin-bottom: 0;
            line-height: 1.6;
        }
        
        /* How It Works */
        .how-it-works {
            padding: 80px 0;
            background: white;
        }
        
        .step-card {
            text-align: center;
            position: relative;
        }
        
        .step-number {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, #375a7f, #2c4a6b);
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            font-weight: 700;
            margin: 0 auto 20px;
            box-shadow: 0 10px 20px rgba(55,90,127,0.3);
        }
        
        .step-card h3 {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--dark-color);
            margin-bottom: 15px;
        }
        
        .step-card p {
            color: #6c757d;
        }
        
        .step-connector {
            position: absolute;
            top: 30px;
            right: -30px;
            width: 60px;
            height: 2px;
            background: linear-gradient(135deg, #375a7f, #2c4a6b);
        }
        
        @media (max-width: 768px) {
            .step-connector {
                display: none;
            }
        }
        
        /* Council Section */
        .council-section {
            padding: 80px 0;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
        }
        
        .council-card {
            background: white;
            padding: 30px;
            border-radius: 15px;
            text-align: center;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
        }
        
        .council-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 15px 40px rgba(55,90,127,0.2);
        }
        
        .council-avatar {
            width: 120px;
            height: 120px;
            margin: 0 auto 20px;
            background: linear-gradient(135deg, #375a7f, #2c4a6b);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 3rem;
            font-weight: 600;
            box-shadow: 0 10px 20px rgba(55,90,127,0.3);
            overflow: hidden;
        }
        
        .council-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .council-card h3 {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--dark-color);
            margin-bottom: 5px;
        }
        
        .council-card p {
            color: #375a7f;
            font-weight: 500;
            margin-bottom: 0;
        }
        
        /* Rules Section */
        .rules-section {
            padding: 80px 0;
            background: white;
        }
        
        .rules-card {
            background: white;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            margin-top: 30px;
        }
        
        .rules-header {
            background: linear-gradient(135deg, #dc3545, #c82333);
            padding: 25px;
            color: white;
        }
        
        .rules-header h3 {
            margin: 0;
            font-weight: 700;
        }
        
        .rules-body {
            padding: 30px;
        }
        
        .rule-category {
            margin-bottom: 30px;
        }
        
        .rule-category h4 {
            color: #375a7f;
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
            padding: 10px 0;
            display: flex;
            align-items: flex-start;
            gap: 12px;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .rule-list li i {
            color: #dc3545;
            margin-top: 3px;
        }
        
        .benefit-table {
            width: 100%;
            margin: 15px 0;
        }
        
        .benefit-table th,
        .benefit-table td {
            padding: 10px 12px;
            border: 1px solid #dee2e6;
        }
        
        .benefit-table th {
            background: #f8f9fa;
            font-weight: 600;
            color: #375a7f;
        }
        
        /* Testimonials */
        .testimonials-section {
            padding: 80px 0;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
        }
        
        .testimonial-card {
            background: white;
            padding: 30px;
            border-radius: 15px;
            position: relative;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        
        .testimonial-card::before {
            content: '"';
            position: absolute;
            top: 20px;
            left: 20px;
            font-size: 5rem;
            color: rgba(55,90,127,0.2);
            font-family: serif;
        }
        
        .testimonial-text {
            font-size: 1.1rem;
            color: var(--dark-color);
            line-height: 1.8;
            margin-bottom: 20px;
            padding-left: 30px;
        }
        
        .testimonial-author {
            display: flex;
            align-items: center;
        }
        
        .author-avatar {
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, #375a7f, #2c4a6b);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            margin-right: 15px;
        }
        
        .author-info h4 {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--dark-color);
            margin-bottom: 5px;
        }
        
        .author-info p {
            font-size: 0.9rem;
            color: #6c757d;
            margin-bottom: 0;
        }
        
        /* CTA Section */
        .cta-section {
            padding: 100px 0;
            background: linear-gradient(135deg, #375a7f, #2c4a6b);
            color: white;
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        
        .cta-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" preserveAspectRatio="none"><path d="M0,0 L100,0 L100,100 L0,100 Z" fill="rgba(255,255,255,0.05)"/></svg>');
            background-size: 50px 50px;
        }
        
        .cta-section h2 {
            font-size: 2.5rem;
            font-weight: 800;
            margin-bottom: 20px;
            position: relative;
            z-index: 2;
        }
        
        .cta-section p {
            font-size: 1.2rem;
            margin-bottom: 30px;
            opacity: 0.9;
            position: relative;
            z-index: 2;
        }
        
        .cta-buttons .btn {
            padding: 15px 40px;
            font-weight: 600;
            border-radius: 50px;
            margin: 0 10px;
            position: relative;
            z-index: 2;
            transition: all 0.3s ease;
        }
        
        .btn-light-custom {
            background: white;
            color: #375a7f;
            border: none;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
        }
        
        .btn-light-custom:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0,0,0,0.3);
            color: #2c4a6b;
        }
        
        .btn-outline-light-custom {
            border: 2px solid white;
            color: white;
            background: transparent;
        }
        
        .btn-outline-light-custom:hover {
            background: white;
            color: #375a7f;
        }
        
        /* Footer */
        .footer {
            background: var(--dark-color);
            color: white;
            padding: 60px 0 20px;
        }
        
        .footer h3 {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 20px;
            color: white;
        }
        
        .footer h3 i {
            color: #375a7f;
            margin-right: 10px;
        }
        
        .footer p {
            color: rgba(255,255,255,0.7);
            line-height: 1.8;
        }
        
        .footer-links {
            list-style: none;
            padding: 0;
        }
        
        .footer-links li {
            margin-bottom: 10px;
        }
        
        .footer-links a {
            color: rgba(255,255,255,0.7);
            text-decoration: none;
            transition: all 0.3s ease;
        }
        
        .footer-links a:hover {
            color: white;
            padding-left: 5px;
        }
        
        .footer-links i {
            width: 20px;
            margin-right: 10px;
            color: #375a7f;
        }
        
        .social-links {
            margin-top: 20px;
        }
        
        .social-links a {
            display: inline-block;
            width: 40px;
            height: 40px;
            background: rgba(255,255,255,0.1);
            border-radius: 50%;
            text-align: center;
            line-height: 40px;
            color: white;
            margin-right: 10px;
            transition: all 0.3s ease;
        }
        
        .social-links a:hover {
            background: linear-gradient(135deg, #375a7f, #2c4a6b);
            transform: translateY(-3px);
        }
        
        .footer-bottom {
            text-align: center;
            padding-top: 30px;
            margin-top: 30px;
            border-top: 1px solid rgba(255,255,255,0.1);
            color: rgba(255,255,255,0.5);
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .hero h1 {
                font-size: 2.5rem;
            }
            
            .section-title h2 {
                font-size: 2rem;
            }
            
            .cta-section h2 {
                font-size: 2rem;
            }
            
            .cta-buttons .btn {
                display: block;
                margin: 10px 0;
            }
            
            .council-avatar {
                width: 100px;
                height: 100px;
            }
        }
    </style>
</head>
<body>
    <!-- Navigation - Updated to match admin sidebar colors -->
    <nav class="navbar navbar-expand-lg fixed-top">
        <div class="container">
            <a class="navbar-brand" href="Landingpage.php">
                <i class="fas fa-hand-holding-heart"></i> Harana
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto align-items-center">
                    <li class="nav-item">
                        <a class="nav-link" href="#home">Home</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#about">About</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#features">Features</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#rules">Rules</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#council">Council</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#contact">Contact</a>
                    </li>
                    <li class="nav-item ms-lg-3">
                        <a class="nav-link btn-login" href="index.php">
                            <i class="fas fa-sign-in-alt me-2"></i>Member Login
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link btn-login" href="register.php" style="background: linear-gradient(135deg, #28a745, #20c997); color: white !important;">
                            <i class="fas fa-user-plus me-2"></i>Join Now
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <section id="home" class="hero">
        <div class="hero-content container">
            <div class="row align-items-center">
                <div class="col-lg-6" data-aos="fade-right">
                    <h1>Your Partner in <span>Financial Security</span> and Community Support</h1>
                    <p>Join thousands of members who trust Harana for their financial needs. Get access to community-based financial assistance, savings programs, and support when you need it most.</p>
                    <div class="hero-buttons">
                        <a href="register.php" class="btn btn-primary-custom">
                            <i class="fas fa-user-plus me-2"></i>Become a Member
                        </a>
                        <a href="#about" class="btn btn-outline-custom">
                            <i class="fas fa-play me-2"></i>Learn More
                        </a>
                    </div>
                    
                    <div class="row mt-5">
                        <div class="col-4">
                            <div class="d-flex align-items-center">
                                <i class="fas fa-check-circle text-success me-2"></i>
                                <span>5+ Years Trusted</span>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="d-flex align-items-center">
                                <i class="fas fa-check-circle text-success me-2"></i>
                                <span>1000+ Members</span>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="d-flex align-items-center">
                                <i class="fas fa-check-circle text-success me-2"></i>
                                <span>24/7 Support</span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6" data-aos="fade-left">
                    <div class="hero-image" style="background: transparent; box-shadow: none; border-radius: 0; overflow: visible;">
                        <img src="assets/images/harana-logo.png" alt="Harana Financial System" style="width: 100%; max-width: 400px; display: block; margin: 0 auto; border-radius: 50%;" onerror="this.style.display='none'; this.parentNode.innerHTML='<div style=\'background: linear-gradient(135deg, #375a7f, #2c4a6b); padding: 80px; text-align: center; border-radius: 50%; width: 300px; height: 300px; margin: 0 auto; display: flex; align-items: center; justify-content: center;\'><i class=\'fas fa-hand-holding-heart\' style=\'font-size: 8rem; color: white;\'></i></div>'">
                    </div>
                </div>
            </div>
        </div>
        <svg class="hero-wave" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1440 320">
            <path fill="#ffffff" fill-opacity="1" d="M0,96L48,112C96,128,192,160,288,160C384,160,480,128,576,122.7C672,117,768,139,864,154.7C960,171,1056,181,1152,165.3C1248,149,1344,107,1392,85.3L1440,64L1440,320L1392,320C1344,320,1248,320,1152,320C1056,320,960,320,864,320C768,320,672,320,576,320C480,320,384,320,288,320C192,320,96,320,48,320L0,320Z"></path>
        </svg>
    </section>

    <!-- Stats Section -->
    <section class="stats-section">
        <div class="container">
            <div class="row">
                <div class="col-md-4" data-aos="fade-up" data-aos-delay="100">
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-users"></i>
                        </div>
                        <div class="stat-number"><?php echo number_format($member_count); ?>+</div>
                        <div class="stat-label">Active Members</div>
                    </div>
                </div>
                <div class="col-md-4" data-aos="fade-up" data-aos-delay="200">
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-user-tie"></i>
                        </div>
                        <div class="stat-number"><?php echo number_format($council_count); ?></div>
                        <div class="stat-label">Council Members</div>
                    </div>
                </div>
                <div class="col-md-4" data-aos="fade-up" data-aos-delay="300">
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-church"></i>
                        </div>
                        <div class="stat-number"><?php echo number_format($chapters_count); ?></div>
                        <div class="stat-label">Chapters</div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- About Section -->
    <section id="about" class="features-section">
        <div class="container">
            <div class="section-title" data-aos="fade-up">
                <h2>About Harana Financial System</h2>
                <p>Building a community of financial security and mutual support for all members</p>
            </div>
            
            <div class="row align-items-center">
                <div class="col-lg-6 mb-4 mb-lg-0" data-aos="fade-right">
                    <div style="background: linear-gradient(135deg, #375a7f, #2c4a6b); padding: 60px; text-align: center; border-radius: 30px;">
                        <i class="fas fa-building" style="font-size: 8rem; color: white; opacity: 0.9;"></i>
                        <h3 style="color: white; margin-top: 20px;">Est. 2024</h3>
                    </div>
                </div>
                <div class="col-lg-6" data-aos="fade-left">
                    <h3 class="mb-4">NAGKAISANG HARANISTA SA GINTONG LUZON, PHILS. INC. (NHGL, INC.)</h3>
                    <p class="mb-4">(Formerly Nagkaisang Haranista Sa Gintong Luzon, Inc.)<br>
                    <small class="text-muted">Sec. REG No. CN 700172104</small></p>
                    
                    <p class="mb-4">We are a community-based financial assistance organization dedicated to providing our members with financial security, support, and opportunities for growth. Our system is designed to help members save, access financial assistance when needed, and build a stronger financial future together.</p>
                    
                    <div class="row">
                        <div class="col-6 mb-3">
                            <div class="d-flex align-items-center">
                                <div class="feature-icon me-3" style="width: 40px; height: 40px;">
                                    <i class="fas fa-shield-alt"></i>
                                </div>
                                <div>
                                    <h6 class="mb-0 fw-bold">Secure & Reliable</h6>
                                    <small class="text-muted">Your funds are safe</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-6 mb-3">
                            <div class="d-flex align-items-center">
                                <div class="feature-icon me-3" style="width: 40px; height: 40px;">
                                    <i class="fas fa-hand-holding-heart"></i>
                                </div>
                                <div>
                                    <h6 class="mb-0 fw-bold">Community Support</h6>
                                    <small class="text-muted">We help each other</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-6 mb-3">
                            <div class="d-flex align-items-center">
                                <div class="feature-icon me-3" style="width: 40px; height: 40px;">
                                    <i class="fas fa-chart-line"></i>
                                </div>
                                <div>
                                    <h6 class="mb-0 fw-bold">Financial Growth</h6>
                                    <small class="text-muted">Grow your savings</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-6 mb-3">
                            <div class="d-flex align-items-center">
                                <div class="feature-icon me-3" style="width: 40px; height: 40px;">
                                    <i class="fas fa-clock"></i>
                                </div>
                                <div>
                                    <h6 class="mb-0 fw-bold">Quick Assistance</h6>
                                    <small class="text-muted">Fast processing</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Features Section -->
    <section id="features" class="how-it-works">
        <div class="container">
            <div class="section-title" data-aos="fade-up">
                <h2>Why Choose Harana?</h2>
                <p>Discover the benefits of being a Harana member</p>
            </div>
            
            <div class="row">
                <div class="col-lg-4 col-md-6 mb-4" data-aos="fade-up" data-aos-delay="100">
                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="fas fa-piggy-bank"></i>
                        </div>
                        <h3>Savings Program</h3>
                        <p>Build your savings with our structured contribution program. Start with as low as ₱100 monthly.</p>
                    </div>
                </div>
                
                <div class="col-lg-4 col-md-6 mb-4" data-aos="fade-up" data-aos-delay="200">
                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="fas fa-hand-holding-usd"></i>
                        </div>
                        <h3>Financial Assistance</h3>
                        <p>Access financial assistance when you need it most - for emergencies, education, or livelihood.</p>
                    </div>
                </div>
                
                <div class="col-lg-4 col-md-6 mb-4" data-aos="fade-up" data-aos-delay="300">
                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="fas fa-heartbeat"></i>
                        </div>
                        <h3>Damayan Program</h3>
                        <p>Our mutual aid program provides support during times of need for members and their families.</p>
                    </div>
                </div>
                
                <div class="col-lg-4 col-md-6 mb-4" data-aos="fade-up" data-aos-delay="400">
                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="fas fa-gift"></i>
                        </div>
                        <h3>Death & Burial Benefits</h3>
                        <p>Financial assistance for burial expenses and support for bereaved families.</p>
                    </div>
                </div>
                
                <div class="col-lg-4 col-md-6 mb-4" data-aos="fade-up" data-aos-delay="500">
                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="fas fa-graduation-cap"></i>
                        </div>
                        <h3>Educational Support</h3>
                        <p>Scholarship and educational assistance programs for members' children.</p>
                    </div>
                </div>
                
                <div class="col-lg-4 col-md-6 mb-4" data-aos="fade-up" data-aos-delay="600">
                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="fas fa-calendar-check"></i>
                        </div>
                        <h3>Regular Updates</h3>
                        <p>Stay informed with meeting schedules, announcements, and organization updates.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- How It Works -->
    <section class="features-section">
        <div class="container">
            <div class="section-title" data-aos="fade-up">
                <h2>How It Works</h2>
                <p>Simple steps to start your journey with Harana</p>
            </div>
            
            <div class="row">
                <div class="col-md-3 mb-4" data-aos="fade-up" data-aos-delay="100">
                    <div class="step-card">
                        <div class="step-number">1</div>
                        <h3>Register</h3>
                        <p>Fill out the membership application form with your personal information.</p>
                    </div>
                </div>
                
                <div class="col-md-3 mb-4" data-aos="fade-up" data-aos-delay="200">
                    <div class="step-card">
                        <div class="step-number">2</div>
                        <h3>Get Approved</h3>
                        <p>Wait for admin approval of your membership application.</p>
                    </div>
                </div>
                
                <div class="col-md-3 mb-4" data-aos="fade-up" data-aos-delay="300">
                    <div class="step-card">
                        <div class="step-number">3</div>
                        <h3>Start Contributing</h3>
                        <p>Begin your monthly contributions to build your savings.</p>
                    </div>
                </div>
                
                <div class="col-md-3 mb-4" data-aos="fade-up" data-aos-delay="400">
                    <div class="step-card">
                        <div class="step-number">4</div>
                        <h3>Enjoy Benefits</h3>
                        <p>Access financial assistance and other member benefits.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- NHGL Rules Section -->
    <section id="rules" class="rules-section">
        <div class="container">
            <div class="section-title" data-aos="fade-up">
                <h2>NHGL-Philippines Inc. Rules & Regulations</h2>
                <p>Revised 2022 - Approved by the Board of Directors</p>
            </div>
            
            <div class="rules-card" data-aos="fade-up">
                <div class="rules-header">
                    <h3><i class="fas fa-gavel me-2"></i>Mga Batas ng NHGL-Philippines Inc. "Revised 2022"</h3>
                    <p class="mb-0 mt-2">Ang mga sumusunod ay ilan lamang sa mga mahahalagang batas na pinagtibay at ipinatutupad ng Nagkaisang Haranista sa Gintong Luzon Phils. Inc.</p>
                </div>
                <div class="rules-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="rule-category">
                                <h4><i class="fas fa-user-plus me-2"></i>Mga Kailangan sa Pagsapi</h4>
                                <ul class="rule-list">
                                    <li><i class="fas fa-check-circle text-success"></i> Personal appearance</li>
                                    <li><i class="fas fa-file-alt"></i> Application form na may datos at lagda</li>
                                    <li><i class="fas fa-stethoscope"></i> Medical certificate (original copy)</li>
                                    <li><i class="fas fa-birthday-cake"></i> Kopya ng birth certificate (45 y/o pataas) o anumang katumbas na makapagpapatunay ng lugar at petsa ng kapanganakan</li>
                                    <li><i class="fas fa-money-bill"></i> Bayad sa pagsapi: ₱100.00 + ₱100.00 para sa ID</li>
                                    <li><i class="fas fa-id-card"></i> ₱60.00 bayad para sa I.D. at s.a.c.e.</li>
                                </ul>
                            </div>
                            
                            <div class="rule-category">
                                <h4><i class="fas fa-chart-line me-2"></i>Laang Benepisyo sa Regular Member</h4>
                                <table class="benefit-table">
                                    <tr><th>Tagal ng Pagiging Miyembro</th><th>Benepisyo</th></tr>
                                    <tr><td>1-2 taon</td><td>₱80,000.00</td></tr>
                                    <tr><td>3-4 taon</td><td>₱90,000.00</td></tr>
                                    <tr><td>5-6 taon</td><td>₱100,000.00</td></tr>
                                    <tr><td>7-8 taon</td><td>₱110,000.00</td></tr>
                                    <tr><td>9 taon</td><td>₱115,000.00</td></tr>
                                    <tr><td>10 taon</td><td>₱120,000.00</td></tr>
                                    <tr><td>11 taon</td><td>₱125,000.00</td></tr>
                                    <tr><td>12 taon</td><td>₱130,000.00</td></tr>
                                    <tr><td>13 taon</td><td>₱135,000.00</td></tr>
                                    <tr><td>14 taon</td><td>₱140,000.00</td></tr>
                                    <tr><td>15 taon</td><td>₱145,000.00</td></tr>
                                    <tr><td>16 taon</td><td>₱150,000.00</td></tr>
                                </table>
                                <ul class="rule-list mt-3">
                                    <li><i class="fas fa-car-crash"></i> Dagdag ₱10,000.00 benepisyo kung aksidente ang sanhi ng pagkamatay</li>
                                    <li><i class="fas fa-star"></i> Dagdag ₱5,000.00 – benepisyo kung "Good Payer" (walang palya sa huling 5 taon)</li>
                                </ul>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="rule-category">
                                <h4><i class="fas fa-users me-2"></i>Beneficiary to Regular</h4>
                                <ul class="rule-list">
                                    <li><i class="fas fa-user-check"></i> 10–60 taong gulang</li>
                                    <li><i class="fas fa-handshake"></i> Personal appearance</li>
                                    <li><i class="fas fa-heart"></i> Lehitimong miyembro (Member of the family) ng namatay na pamilya lamang ang maaaring pumasok bilang miyembro</li>
                                    <li><i class="fas fa-coins"></i> Magbabayad ng katumbas ng hulog na isang taon na binayaran ng mga miyembro</li>
                                </ul>
                            </div>
                            
                            <div class="rule-category">
                                <h4><i class="fas fa-clock me-2"></i>Probation Period (Late Payment)</h4>
                                <ul class="rule-list">
                                    <li><strong>51–100 patay/utang:</strong> 1 buwan probation</li>
                                    <li><strong>101–150 patay/utang:</strong> 2 buwan probation</li>
                                    <li><strong>Higit sa 150 patay:</strong> Maaari nang tanggalin sa talaan ng mga lehitimong miyembro</li>
                                    <li><i class="fas fa-user-check"></i> Personal appearance ang sinumang mag late payment</li>
                                    <li><i class="fas fa-money-bill"></i> Kabuuang halaga (Full Payment) lamang ang tatanggapin</li>
                                </ul>
                            </div>
                            
                            <div class="rule-category">
                                <h4><i class="fas fa-balance-scale me-2"></i>Adjusted Death Benefits</h4>
                                <ul class="rule-list">
                                    <li><strong>1–10 utang:</strong> Bawas ₱2,500.00</li>
                                    <li><strong>11–25 utang:</strong> Bawas ₱5,000.00</li>
                                    <li><strong>26–50 utang:</strong> Bawas ₱10,000.00</li>
                                    <li><strong>51–70 utang:</strong> Kalahati ng laang benepisyo</li>
                                    <li><strong>71 pataas:</strong> Donasyon depende sa tagal ng pagiging miyembro</li>
                                </ul>
                            </div>
                            
                            <div class="alert alert-warning mt-3">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                <strong>Mahalagang Paalala:</strong> Ang sinumang kasapi ng ibang samahan bukod sa NHGL (dual membership) ay walang pribilehiyo tulad ng loyal member.
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Council Section with Photos -->
    <section id="council" class="council-section">
        <div class="container">
            <div class="section-title" data-aos="fade-up">
                <h2>Our Council Members</h2>
                <p>Meet the dedicated leaders guiding our organization</p>
            </div>
            
            <div class="row">
                <?php if (empty($council_members)): ?>
                    <?php 
                    $sample_council = [
                        ['full_name' => 'Juan Dela Cruz', 'position' => 'President/CEO'],
                        ['full_name' => 'Maria Santos', 'position' => 'Vice President'],
                        ['full_name' => 'Jose Rizal', 'position' => 'Treasurer'],
                        ['full_name' => 'Andres Bonifacio', 'position' => 'Secretary'],
                        ['full_name' => 'Gabriela Silang', 'position' => 'Auditor'],
                        ['full_name' => 'Apolinario Mabini', 'position' => 'Adviser']
                    ];
                    foreach ($sample_council as $index => $council): 
                    ?>
                    <div class="col-lg-4 col-md-6 mb-4" data-aos="fade-up" data-aos-delay="<?php echo $index * 100; ?>">
                        <div class="council-card">
                            <div class="council-avatar">
                                <?php 
                                $initials = '';
                                $name_parts = explode(' ', $council['full_name']);
                                foreach ($name_parts as $part) {
                                    $initials .= strtoupper(substr($part, 0, 1));
                                }
                                echo $initials;
                                ?>
                            </div>
                            <h3><?php echo htmlspecialchars($council['full_name']); ?></h3>
                            <p><?php echo htmlspecialchars($council['position']); ?></p>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <?php foreach ($council_members as $index => $council): ?>
                    <div class="col-lg-4 col-md-6 mb-4" data-aos="fade-up" data-aos-delay="<?php echo $index * 100; ?>">
                        <div class="council-card">
                            <div class="council-avatar">
                                <?php if (!empty($council['photo'])): ?>
                                    <img src="<?php echo htmlspecialchars($council['photo']); ?>" alt="<?php echo htmlspecialchars($council['full_name']); ?>">
                                <?php else: ?>
                                    <?php 
                                    $initials = '';
                                    $name_parts = explode(' ', $council['full_name'] ?? '');
                                    foreach ($name_parts as $part) {
                                        if (!empty($part)) {
                                            $initials .= strtoupper(substr($part, 0, 1));
                                        }
                                    }
                                    echo $initials ?: 'CM';
                                    ?>
                                <?php endif; ?>
                            </div>
                            <h3><?php echo htmlspecialchars($council['full_name']); ?></h3>
                            <p><?php echo htmlspecialchars($council['position']); ?></p>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            
            <div class="text-center mt-4" data-aos="fade-up">
                <a href="#contact" class="btn btn-primary-custom">
                    <i class="fas fa-envelope me-2"></i>Contact Our Council
                </a>
            </div>
        </div>
    </section>

    <!-- Testimonials -->
    <section class="testimonials-section">
        <div class="container">
            <div class="section-title" data-aos="fade-up">
                <h2>What Our Members Say</h2>
                <p>Hear from the people who trust Harana</p>
            </div>
            
            <div class="row">
                <div class="col-md-4 mb-4" data-aos="fade-up" data-aos-delay="100">
                    <div class="testimonial-card">
                        <p class="testimonial-text">"Harana has been a blessing to our family. The financial assistance helped us during an emergency, and the savings program has taught us to be more disciplined with our finances."</p>
                        <div class="testimonial-author">
                            <div class="author-avatar">MR</div>
                            <div class="author-info">
                                <h4>Maria Reyes</h4>
                                <p>Member since 2020 • Guimba Chapter</p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-4 mb-4" data-aos="fade-up" data-aos-delay="200">
                    <div class="testimonial-card">
                        <p class="testimonial-text">"As a small business owner, the support from Harana has been invaluable. The community of members feels like family, and I know I can count on them when times are tough."</p>
                        <div class="testimonial-author">
                            <div class="author-avatar">JS</div>
                            <div class="author-info">
                                <h4>Jose Santos</h4>
                                <p>Member since 2019 • Palayan Chapter</p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-4 mb-4" data-aos="fade-up" data-aos-delay="300">
                    <div class="testimonial-card">
                        <p class="testimonial-text">"The process of applying for assistance was quick and easy. I'm proud to be part of an organization that truly cares about its members' welfare."</p>
                        <div class="testimonial-author">
                            <div class="author-avatar">AG</div>
                            <div class="author-info">
                                <h4>Ana Gonzales</h4>
                                <p>Member since 2021 • Talavera Chapter</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- CTA Section -->
    <section class="cta-section">
        <div class="container">
            <h2 data-aos="fade-up">Ready to Start Your Journey?</h2>
            <p data-aos="fade-up" data-aos-delay="100">Join thousands of members who have found financial security and community support with Harana.</p>
            <div class="cta-buttons" data-aos="fade-up" data-aos-delay="200">
                <a href="register.php" class="btn btn-light-custom">
                    <i class="fas fa-user-plus me-2"></i>Become a Member Today
                </a>
                <a href="#contact" class="btn btn-outline-light-custom">
                    <i class="fas fa-phone-alt me-2"></i>Contact Us
                </a>
            </div>
        </div>
    </section>

    <!-- Contact Section -->
    <section id="contact" class="how-it-works">
        <div class="container">
            <div class="section-title" data-aos="fade-up">
                <h2>Contact Us</h2>
                <p>Get in touch with us for any questions or concerns</p>
            </div>
            
            <div class="row">
                <div class="col-md-5 mb-4" data-aos="fade-right">
                    <h4 class="mb-4">Our Office</h4>
                    
                    <div class="d-flex mb-3">
                        <div class="feature-icon me-3">
                            <i class="fas fa-map-marker-alt"></i>
                        </div>
                        <div>
                            <h6 class="fw-bold mb-1">Address</h6>
                            <p class="text-muted">MF 2024, Brgy. Singalat, Palayan City, Province of Nueva Ecija</p>
                        </div>
                    </div>
                    
                    <div class="d-flex mb-3">
                        <div class="feature-icon me-3">
                            <i class="fas fa-phone-alt"></i>
                        </div>
                        <div>
                            <h6 class="fw-bold mb-1">Phone</h6>
                            <p class="text-muted">(044) 940-6708</p>
                        </div>
                    </div>
                    
                    <div class="d-flex mb-3">
                        <div class="feature-icon me-3">
                            <i class="fas fa-envelope"></i>
                        </div>
                        <div>
                            <h6 class="fw-bold mb-1">Email</h6>
                            <p class="text-muted">info@harana.com</p>
                        </div>
                    </div>
                    
                    <div class="d-flex mb-3">
                        <div class="feature-icon me-3">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div>
                            <h6 class="fw-bold mb-1">Office Hours</h6>
                            <p class="text-muted">Monday - Friday: 8:00 AM - 5:00 PM<br>Saturday: 8:00 AM - 12:00 PM</p>
                        </div>
                    </div>
                    
                    <div class="social-links mt-4">
                        <a href="#"><i class="fab fa-facebook-f"></i></a>
                        <a href="#"><i class="fab fa-twitter"></i></a>
                        <a href="#"><i class="fab fa-instagram"></i></a>
                        <a href="#"><i class="fab fa-youtube"></i></a>
                    </div>
                </div>
                
                <div class="col-md-7" data-aos="fade-left">
                    <form action="#" method="POST" class="contact-form">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <input type="text" class="form-control" placeholder="Your Name" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <input type="email" class="form-control" placeholder="Your Email" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <input type="text" class="form-control" placeholder="Subject" required>
                        </div>
                        <div class="mb-3">
                            <textarea class="form-control" rows="5" placeholder="Your Message" required></textarea>
                        </div>
                        <button type="submit" class="btn btn-primary-custom w-100">
                            <i class="fas fa-paper-plane me-2"></i>Send Message
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <div class="row">
                <div class="col-md-4 mb-4">
                    <h3><i class="fas fa-hand-holding-heart"></i> Harana</h3>
                    <p>Nagkaisang Haranista sa Gintong Luzon, Phils. Inc. (NHGL, Inc.)<br>
                    <small>(Formerly Nagkaisang Haranista Sa Gintong Luzon, Inc.)</small></p>
                    <p class="mt-3">Building a community of financial security and mutual support for all members.</p>
                </div>
                
                <div class="col-md-2 mb-4">
                    <h3>Quick Links</h3>
                    <ul class="footer-links">
                        <li><a href="#home"><i class="fas fa-chevron-right"></i>Home</a></li>
                        <li><a href="#about"><i class="fas fa-chevron-right"></i>About Us</a></li>
                        <li><a href="#features"><i class="fas fa-chevron-right"></i>Features</a></li>
                        <li><a href="#rules"><i class="fas fa-chevron-right"></i>Rules</a></li>
                        <li><a href="#council"><i class="fas fa-chevron-right"></i>Council</a></li>
                        <li><a href="#contact"><i class="fas fa-chevron-right"></i>Contact</a></li>
                    </ul>
                </div>
                
                <div class="col-md-3 mb-4">
                    <h3>For Members</h3>
                    <ul class="footer-links">
                        <li><a href="index.php"><i class="fas fa-chevron-right"></i>Member Login</a></li>
                        <li><a href="register.php"><i class="fas fa-chevron-right"></i>Register</a></li>
                        <li><a href="#"><i class="fas fa-chevron-right"></i>Member Benefits</a></li>
                        <li><a href="#"><i class="fas fa-chevron-right"></i>FAQs</a></li>
                        <li><a href="#"><i class="fas fa-chevron-right"></i>Privacy Policy</a></li>
                    </ul>
                </div>
                
                <div class="col-md-3 mb-4">
                    <h3>Newsletter</h3>
                    <p>Subscribe to get updates on announcements and events.</p>
                    <form class="mt-3">
                        <div class="input-group">
                            <input type="email" class="form-control" placeholder="Your Email">
                            <button class="btn btn-primary-custom" type="submit">
                                <i class="fas fa-paper-plane"></i>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            
            <div class="footer-bottom">
                <p class="mb-0">&copy; <?php echo date('Y'); ?> Harana Financial System. All rights reserved. | Sec. REG No. CN 700172104</p>
            </div>
        </div>
    </footer>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
    <script>
        // Initialize AOS
        AOS.init({
            duration: 1000,
            once: true,
            offset: 100
        });
        
        // Navbar scroll effect
        window.addEventListener('scroll', function() {
            const navbar = document.querySelector('.navbar');
            if (window.scrollY > 50) {
                navbar.classList.add('scrolled');
            } else {
                navbar.classList.remove('scrolled');
            }
        });
        
        // Smooth scrolling for anchor links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            });
        });
        
        // Handle contact form submission
        document.querySelector('.contact-form')?.addEventListener('submit', function(e) {
            e.preventDefault();
            alert('Thank you for your message! We will get back to you soon.');
            this.reset();
        });
        
        // Handle newsletter submission
        document.querySelector('.footer form')?.addEventListener('submit', function(e) {
            e.preventDefault();
            alert('Thank you for subscribing to our newsletter!');
            this.reset();
        });
    </script>
</body>
</html>