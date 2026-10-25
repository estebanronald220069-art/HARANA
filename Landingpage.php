<?php
// Landingpage.php - Redesigned with Sulkin Askenazi-inspired Interface
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
    <title><?php echo APP_NAME; ?> – Financial Security & Community</title>
    <meta name="description" content="Join Harana Financial System – A community-based financial assistance program providing security, support, and financial wellness for members.">
    <meta name="keywords" content="financial assistance, community support, membership, savings, loans, Philippines">
    
    <!-- Open Graph / Social Media Meta Tags -->
    <meta property="og:title" content="<?php echo APP_NAME; ?> – Financial Security & Community Support">
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
    <!-- Google Fonts - Clean, Modern Typography -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Playfair+Display:ital,wght@0,700;1,700&display=swap" rel="stylesheet">
    
    <style>
        /* ========================================
           ROOT VARIABLES & RESET
           ======================================== */
        :root {
            --primary-dark: #1a1a2e;
            --primary-mid: #2c3e50;
            --primary-accent: #4a6fa5;
            --text-dark: #1a1a2e;
            --text-light: #f8f9fa;
            --text-muted: #8892a8;
            --bg-light: #f8f9fa;
            --bg-offwhite: #f0f2f5;
            --white: #ffffff;
            --shadow-subtle: 0 20px 60px rgba(0,0,0,0.08);
            --shadow-medium: 0 30px 80px rgba(0,0,0,0.12);
            --shadow-heavy: 0 40px 100px rgba(0,0,0,0.16);
            --transition-smooth: all 0.6s cubic-bezier(0.23, 1, 0.32, 1);
            --transition-bounce: all 0.8s cubic-bezier(0.34, 1.56, 0.64, 1);
            --border-radius-card: 24px;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            overflow-x: hidden;
            color: var(--text-dark);
            background: var(--white);
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }

        /* ========================================
           TYPOGRAPHY – Bold & Expressive
           ======================================== */
        .display-extra-large {
            font-size: clamp(3.5rem, 12vw, 7rem);
            font-weight: 900;
            line-height: 1.05;
            letter-spacing: -0.04em;
        }

        .display-large {
            font-size: clamp(2.8rem, 8vw, 5rem);
            font-weight: 800;
            line-height: 1.1;
            letter-spacing: -0.03em;
        }

        .display-medium {
            font-size: clamp(2rem, 5vw, 3.2rem);
            font-weight: 700;
            line-height: 1.2;
            letter-spacing: -0.02em;
        }

        .text-serif-italic {
            font-family: 'Playfair Display', serif;
            font-style: italic;
            font-weight: 700;
        }

        .text-gradient {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary-accent) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .text-gradient-light {
            background: linear-gradient(135deg, #ffffff 0%, rgba(255,255,255,0.7) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        /* ========================================
           NAVIGATION – Transparent & Minimal
           ======================================== */
        .navbar {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
            padding: 1.8rem 0;
            transition: var(--transition-smooth);
            background: transparent;
        }

        .navbar.scrolled {
            padding: 0.8rem 0;
            background: rgba(255, 255, 255, 0.92);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            box-shadow: 0 2px 40px rgba(0,0,0,0.06);
        }

        .navbar-brand {
            font-size: 1.6rem;
            font-weight: 800;
            color: var(--white) !important;
            letter-spacing: -0.02em;
            transition: var(--transition-smooth);
        }

        .navbar.scrolled .navbar-brand {
            color: var(--text-dark) !important;
        }

        .navbar-brand i {
            margin-right: 10px;
            opacity: 0.9;
        }

        .navbar-brand .brand-light {
            color: rgba(255,255,255,0.5);
            font-weight: 300;
        }

        .navbar.scrolled .navbar-brand .brand-light {
            color: var(--text-muted);
        }

        .nav-link {
            color: rgba(255,255,255,0.8) !important;
            font-weight: 500;
            font-size: 0.9rem;
            margin: 0 8px;
            padding: 0.5rem 0 !important;
            position: relative;
            transition: var(--transition-smooth);
            letter-spacing: 0.02em;
            text-transform: uppercase;
        }

        .navbar.scrolled .nav-link {
            color: var(--text-dark) !important;
        }

        .nav-link::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 0;
            height: 2px;
            background: var(--white);
            transition: width 0.4s cubic-bezier(0.23, 1, 0.32, 1);
        }

        .navbar.scrolled .nav-link::after {
            background: var(--primary-dark);
        }

        .nav-link:hover::after {
            width: 100%;
        }

        .nav-link:hover {
            color: var(--white) !important;
        }

        .navbar.scrolled .nav-link:hover {
            color: var(--primary-dark) !important;
        }

        .btn-nav-primary {
            background: var(--white);
            color: var(--primary-dark) !important;
            border-radius: 50px;
            padding: 0.6rem 1.8rem !important;
            font-weight: 600;
            font-size: 0.85rem;
            text-transform: none !important;
            transition: var(--transition-smooth);
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        }

        .btn-nav-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 30px rgba(0,0,0,0.15);
            color: var(--primary-dark) !important;
        }

        .btn-nav-primary::after {
            display: none !important;
        }

        .btn-nav-secondary {
            background: rgba(255,255,255,0.15);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.2);
            color: var(--white) !important;
            border-radius: 50px;
            padding: 0.6rem 1.8rem !important;
            font-weight: 600;
            font-size: 0.85rem;
            text-transform: none !important;
            transition: var(--transition-smooth);
        }

        .btn-nav-secondary:hover {
            background: var(--white);
            color: var(--primary-dark) !important;
            border-color: var(--white);
        }

        .navbar.scrolled .btn-nav-secondary {
            background: var(--primary-dark);
            border-color: var(--primary-dark);
            color: var(--white) !important;
        }

        .navbar.scrolled .btn-nav-secondary:hover {
            background: var(--primary-accent);
            border-color: var(--primary-accent);
            color: var(--white) !important;
        }

        .btn-nav-secondary::after {
            display: none !important;
        }

        /* Mobile Toggle */
        .navbar-toggler {
            border: none;
            padding: 0.5rem;
        }

        .navbar-toggler:focus {
            box-shadow: none;
        }

        .navbar-toggler-icon {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 30 30'%3E%3Cpath stroke='rgba(255,255,255,0.9)' stroke-linecap='round' stroke-miterlimit='10' stroke-width='2' d='M4 7h22M4 15h22M4 23h22'/%3E%3C/svg%3E");
        }

        .navbar.scrolled .navbar-toggler-icon {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 30 30'%3E%3Cpath stroke='rgba(26,26,46,0.9)' stroke-linecap='round' stroke-miterlimit='10' stroke-width='2' d='M4 7h22M4 15h22M4 23h22'/%3E%3C/svg%3E");
        }

        /* ========================================
           HERO – Full-Screen Dramatic
           ======================================== */
        .hero {
            min-height: 100vh;
            display: flex;
            align-items: center;
            position: relative;
            overflow: hidden;
            background: var(--primary-dark);
        }

        .hero::before {
            content: '';
            position: absolute;
            inset: 0;
            background: 
                radial-gradient(ellipse at 20% 50%, rgba(74, 111, 165, 0.15) 0%, transparent 60%),
                radial-gradient(ellipse at 80% 50%, rgba(26, 26, 46, 0.8) 0%, transparent 50%);
            z-index: 1;
        }

        .hero-pattern {
            position: absolute;
            inset: 0;
            z-index: 0;
            opacity: 0.04;
            background-image: 
                linear-gradient(rgba(255,255,255,0.1) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255,255,255,0.1) 1px, transparent 1px);
            background-size: 60px 60px;
        }

        .hero-content {
            position: relative;
            z-index: 2;
            padding: 120px 0 80px;
        }

        .hero-badge {
            display: inline-block;
            background: rgba(255,255,255,0.08);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.1);
            padding: 0.5rem 1.5rem;
            border-radius: 50px;
            color: rgba(255,255,255,0.7);
            font-size: 0.8rem;
            font-weight: 500;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            margin-bottom: 2rem;
        }

        .hero-badge i {
            margin-right: 8px;
            color: var(--primary-accent);
        }

        .hero h1 {
            color: var(--white);
            margin-bottom: 1.5rem;
        }

        .hero h1 .highlight-word {
            position: relative;
            display: inline-block;
        }

        .hero h1 .highlight-word::after {
            content: '';
            position: absolute;
            bottom: 0.05em;
            left: 0;
            width: 100%;
            height: 0.12em;
            background: var(--primary-accent);
            opacity: 0.4;
        }

        .hero p {
            color: rgba(255,255,255,0.6);
            font-size: 1.2rem;
            max-width: 560px;
            line-height: 1.8;
            margin-bottom: 2.5rem;
            font-weight: 300;
        }

        .hero-buttons .btn {
            padding: 1rem 2.8rem;
            font-weight: 600;
            border-radius: 50px;
            transition: var(--transition-smooth);
            font-size: 0.95rem;
        }

        .btn-hero-primary {
            background: var(--white);
            color: var(--primary-dark) !important;
            border: none;
            box-shadow: 0 8px 30px rgba(0,0,0,0.2);
        }

        .btn-hero-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 40px rgba(0,0,0,0.3);
            color: var(--primary-dark) !important;
        }

        .btn-hero-secondary {
            background: transparent;
            color: var(--white) !important;
            border: 1px solid rgba(255,255,255,0.3);
        }

        .btn-hero-secondary:hover {
            background: rgba(255,255,255,0.1);
            border-color: var(--white);
            color: var(--white) !important;
            transform: translateY(-3px);
        }

        .hero-stats {
            margin-top: 3rem;
            display: flex;
            gap: 3rem;
            flex-wrap: wrap;
        }

        .hero-stat-item {
            display: flex;
            align-items: center;
            gap: 0.8rem;
            color: rgba(255,255,255,0.5);
            font-size: 0.9rem;
        }

        .hero-stat-item i {
            color: var(--primary-accent);
            font-size: 1.2rem;
        }

        .hero-stat-item strong {
            color: var(--white);
            font-weight: 700;
            font-size: 1.1rem;
        }

        /* Hero Visual Element */
        .hero-visual {
            position: relative;
            z-index: 2;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .hero-logo-container {
            width: 100%;
            max-width: 420px;
            aspect-ratio: 1/1;
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .hero-logo-container .logo-ring {
            position: absolute;
            inset: -20px;
            border-radius: 50%;
            border: 1px solid rgba(255,255,255,0.06);
            animation: spin 30s linear infinite;
        }

        .hero-logo-container .logo-ring:nth-child(2) {
            inset: -40px;
            border-color: rgba(255,255,255,0.04);
            animation-duration: 40s;
            animation-direction: reverse;
        }

        .hero-logo-container .logo-ring:nth-child(3) {
            inset: -60px;
            border-color: rgba(255,255,255,0.03);
            animation-duration: 50s;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        .hero-logo-container img {
            width: 70%;
            height: auto;
            object-fit: contain;
            position: relative;
            z-index: 2;
            border-radius: 50%;
            box-shadow: 0 30px 80px rgba(0,0,0,0.4);
        }

        .hero-logo-container .logo-placeholder {
            width: 70%;
            aspect-ratio: 1/1;
            background: linear-gradient(135deg, rgba(255,255,255,0.05), rgba(255,255,255,0.02));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            z-index: 2;
            border: 1px solid rgba(255,255,255,0.06);
        }

        .hero-logo-container .logo-placeholder i {
            font-size: clamp(6rem, 15vw, 10rem);
            color: rgba(255,255,255,0.08);
        }

        /* Scroll Indicator */
        .scroll-indicator {
            position: absolute;
            bottom: 40px;
            left: 50%;
            transform: translateX(-50%);
            z-index: 3;
            color: rgba(255,255,255,0.2);
            font-size: 0.7rem;
            letter-spacing: 0.2em;
            text-transform: uppercase;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 10px;
            animation: floatDown 3s ease-in-out infinite;
        }

        .scroll-indicator .line {
            width: 1px;
            height: 40px;
            background: linear-gradient(to bottom, rgba(255,255,255,0.3), transparent);
        }

        @keyframes floatDown {
            0%, 100% { transform: translateX(-50%) translateY(0); opacity: 0.5; }
            50% { transform: translateX(-50%) translateY(8px); opacity: 1; }
        }

        /* ========================================
           STATS – Clean & Elevated
           ======================================== */
        .stats-section {
            padding: 100px 0;
            background: var(--bg-light);
            position: relative;
        }

        .stats-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 1px;
            background: linear-gradient(to right, transparent, rgba(0,0,0,0.05), transparent);
        }

        .stat-card {
            text-align: center;
            padding: 40px 30px;
            border-radius: var(--border-radius-card);
            background: var(--white);
            transition: var(--transition-smooth);
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(135deg, var(--primary-dark), var(--primary-accent));
            transform: scaleX(0);
            transform-origin: left;
            transition: transform 0.6s cubic-bezier(0.23, 1, 0.32, 1);
        }

        .stat-card:hover::before {
            transform: scaleX(1);
        }

        .stat-card:hover {
            transform: translateY(-8px);
            box-shadow: var(--shadow-medium);
        }

        .stat-icon-wrapper {
            width: 72px;
            height: 72px;
            margin: 0 auto 20px;
            background: rgba(26, 26, 46, 0.04);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: var(--transition-smooth);
        }

        .stat-card:hover .stat-icon-wrapper {
            background: rgba(26, 26, 46, 0.08);
            transform: scale(1.05);
        }

        .stat-icon-wrapper i {
            font-size: 2rem;
            color: var(--primary-dark);
        }

        .stat-number {
            font-size: 3rem;
            font-weight: 900;
            color: var(--primary-dark);
            letter-spacing: -0.02em;
            margin-bottom: 4px;
        }

        .stat-label {
            color: var(--text-muted);
            font-size: 0.95rem;
            font-weight: 500;
        }

        /* ========================================
           SECTION HEADERS – Minimal & Impactful
           ======================================== */
        .section-header {
            text-align: center;
            margin-bottom: 60px;
        }

        .section-header .section-tag {
            display: inline-block;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.15em;
            color: var(--primary-accent);
            margin-bottom: 12px;
        }

        .section-header h2 {
            font-size: clamp(2.2rem, 5vw, 3.2rem);
            font-weight: 800;
            color: var(--primary-dark);
            line-height: 1.2;
            margin-bottom: 16px;
        }

        .section-header p {
            color: var(--text-muted);
            font-size: 1.1rem;
            max-width: 600px;
            margin: 0 auto;
            font-weight: 300;
        }

        /* ========================================
           FEATURES – Card-Based Elegance
           ======================================== */
        .features-section {
            padding: 100px 0;
            background: var(--white);
        }

        .feature-card {
            background: var(--white);
            padding: 40px 32px;
            border-radius: var(--border-radius-card);
            transition: var(--transition-smooth);
            height: 100%;
            border: 1px solid rgba(0,0,0,0.04);
            position: relative;
        }

        .feature-card:hover {
            transform: translateY(-10px);
            box-shadow: var(--shadow-medium);
            border-color: transparent;
        }

        .feature-icon-wrapper {
            width: 60px;
            height: 60px;
            background: rgba(26, 26, 46, 0.04);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 20px;
            transition: var(--transition-smooth);
        }

        .feature-card:hover .feature-icon-wrapper {
            background: var(--primary-dark);
            transform: scale(1.05) rotate(-3deg);
        }

        .feature-icon-wrapper i {
            font-size: 1.6rem;
            color: var(--primary-dark);
            transition: var(--transition-smooth);
        }

        .feature-card:hover .feature-icon-wrapper i {
            color: var(--white);
        }

        .feature-card h3 {
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--primary-dark);
            margin-bottom: 12px;
        }

        .feature-card p {
            color: var(--text-muted);
            font-weight: 300;
            line-height: 1.7;
            font-size: 0.95rem;
            margin-bottom: 0;
        }

        .feature-card .feature-link {
            display: inline-block;
            margin-top: 16px;
            color: var(--primary-dark);
            font-weight: 600;
            font-size: 0.85rem;
            text-decoration: none;
            opacity: 0;
            transform: translateX(-10px);
            transition: var(--transition-smooth);
        }

        .feature-card:hover .feature-link {
            opacity: 1;
            transform: translateX(0);
        }

        .feature-card .feature-link i {
            transition: var(--transition-smooth);
        }

        .feature-card .feature-link:hover i {
            transform: translateX(4px);
        }

        /* ========================================
           HOW IT WORKS – Steps with Connectors
           ======================================== */
        .how-section {
            padding: 100px 0;
            background: var(--bg-light);
        }

        .step-card {
            text-align: center;
            padding: 40px 20px;
            position: relative;
        }

        .step-number-circle {
            width: 72px;
            height: 72px;
            margin: 0 auto 24px;
            background: var(--primary-dark);
            color: var(--white);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            font-weight: 800;
            box-shadow: 0 20px 40px rgba(26, 26, 46, 0.15);
            transition: var(--transition-smooth);
        }

        .step-card:hover .step-number-circle {
            transform: scale(1.05) translateY(-4px);
            box-shadow: 0 30px 60px rgba(26, 26, 46, 0.2);
        }

        .step-card h3 {
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--primary-dark);
            margin-bottom: 12px;
        }

        .step-card p {
            color: var(--text-muted);
            font-weight: 300;
            line-height: 1.7;
            font-size: 0.95rem;
            margin-bottom: 0;
        }

        .step-connector {
            position: absolute;
            top: 56px;
            right: -20px;
            width: 40px;
            height: 2px;
            background: linear-gradient(to right, var(--primary-dark), transparent);
            opacity: 0.15;
        }

        @media (max-width: 768px) {
            .step-connector {
                display: none;
            }
        }

        /* ========================================
           COUNCIL – Avatar Focus
           ======================================== */
        .council-section {
            padding: 100px 0;
            background: var(--white);
        }

        .council-card {
            text-align: center;
            padding: 36px 24px;
            border-radius: var(--border-radius-card);
            background: var(--white);
            transition: var(--transition-smooth);
            border: 1px solid rgba(0,0,0,0.04);
        }

        .council-card:hover {
            transform: translateY(-10px);
            box-shadow: var(--shadow-medium);
            border-color: transparent;
        }

        .council-avatar {
            width: 120px;
            height: 120px;
            margin: 0 auto 20px;
            border-radius: 50%;
            overflow: hidden;
            background: linear-gradient(135deg, var(--primary-dark), var(--primary-accent));
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 3rem;
            font-weight: 700;
            color: var(--white);
            box-shadow: 0 20px 40px rgba(26, 26, 46, 0.12);
            transition: var(--transition-smooth);
        }

        .council-card:hover .council-avatar {
            transform: scale(1.03);
            box-shadow: 0 30px 60px rgba(26, 26, 46, 0.18);
        }

        .council-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .council-card h3 {
            font-size: 1.15rem;
            font-weight: 700;
            color: var(--primary-dark);
            margin-bottom: 4px;
        }

        .council-card p {
            color: var(--primary-accent);
            font-size: 0.85rem;
            font-weight: 500;
        }

               /* ========================================
           RULES – REDESIGNED LAYOUT
           ======================================== */
        .rules-section {
            padding: 100px 0;
            background: var(--bg-light);
        }

        .rules-container {
            background: var(--white);
            border-radius: var(--border-radius-card);
            overflow: hidden;
            box-shadow: var(--shadow-subtle);
        }

        /* Rules Header */
        .rules-header {
            padding: 40px 48px;
            background: var(--primary-dark);
            color: var(--white);
            position: relative;
            overflow: hidden;
        }

        .rules-header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -10%;
            width: 300px;
            height: 300px;
            background: radial-gradient(circle, rgba(74,111,165,0.1) 0%, transparent 70%);
            border-radius: 50%;
        }

        .rules-header h3 {
            font-weight: 700;
            font-size: 1.6rem;
            margin-bottom: 8px;
            position: relative;
            z-index: 1;
        }

        .rules-header p {
            opacity: 0.6;
            font-weight: 300;
            margin-bottom: 0;
            position: relative;
            z-index: 1;
        }

        .rules-badge {
            display: inline-block;
            background: rgba(255,255,255,0.08);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.06);
            padding: 0.5rem 1.5rem;
            border-radius: 50px;
            font-size: 0.85rem;
            font-weight: 500;
            color: rgba(255,255,255,0.8);
            position: relative;
            z-index: 1;
        }

        .rules-body {
            padding: 48px;
        }

        /* Rule Cards */
        .rule-card {
            background: var(--white);
            border-radius: 16px;
            padding: 28px 30px;
            margin-bottom: 24px;
            border: 1px solid rgba(0,0,0,0.04);
            transition: var(--transition-smooth);
        }

        .rule-card:hover {
            border-color: transparent;
            box-shadow: var(--shadow-subtle);
        }

        .rule-card:last-child {
            margin-bottom: 0;
        }

        .rule-card-header {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 20px;
        }

        .rule-card-header.compact {
            margin-bottom: 16px;
        }

        .rule-card-icon {
            width: 48px;
            height: 48px;
            background: rgba(26, 26, 46, 0.04);
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            transition: var(--transition-smooth);
        }

        .rule-card:hover .rule-card-icon {
            background: var(--primary-dark);
        }

        .rule-card-icon i {
            font-size: 1.4rem;
            color: var(--primary-dark);
            transition: var(--transition-smooth);
        }

        .rule-card:hover .rule-card-icon i {
            color: var(--white);
        }

        .rule-card-icon.small {
            width: 40px;
            height: 40px;
        }

        .rule-card-icon.small i {
            font-size: 1.1rem;
        }

        .rule-card-header h4 {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--primary-dark);
            margin-bottom: 0;
        }

        /* Card Variants */
        .rule-card-requirements .rule-card-icon {
            background: rgba(52, 152, 219, 0.08);
        }
        .rule-card-requirements .rule-card-icon i {
            color: #3498db;
        }

        .rule-card-beneficiary .rule-card-icon {
            background: rgba(46, 204, 113, 0.08);
        }
        .rule-card-beneficiary .rule-card-icon i {
            color: #2ecc71;
        }

        .rule-card-benefits .rule-card-icon {
            background: rgba(241, 196, 15, 0.08);
        }
        .rule-card-benefits .rule-card-icon i {
            color: #f1c40f;
        }

        .rule-card-probation .rule-card-icon {
            background: rgba(231, 76, 60, 0.08);
        }
        .rule-card-probation .rule-card-icon i {
            color: #e74c3c;
        }

        .rule-card-death .rule-card-icon {
            background: rgba(155, 89, 182, 0.08);
        }
        .rule-card-death .rule-card-icon i {
            color: #9b59b6;
        }

        /* Modern Rule List */
        .rule-list-modern {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .rule-list-modern li {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            padding: 10px 0;
            border-bottom: 1px solid rgba(0,0,0,0.03);
        }

        .rule-list-modern li:last-child {
            border-bottom: none;
            padding-bottom: 0;
        }

        .rule-bullet {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            font-size: 0.8rem;
            margin-top: 2px;
        }

        .rule-bullet.bullet-check {
            background: rgba(46, 204, 113, 0.1);
            color: #2ecc71;
        }

        .rule-bullet.bullet-file {
            background: rgba(52, 152, 219, 0.1);
            color: #3498db;
        }

        .rule-bullet.bullet-medical {
            background: rgba(231, 76, 60, 0.1);
            color: #e74c3c;
        }

        .rule-bullet.bullet-birth {
            background: rgba(241, 196, 15, 0.1);
            color: #f1c40f;
        }

        .rule-bullet.bullet-money {
            background: rgba(46, 204, 113, 0.1);
            color: #2ecc71;
        }

        .rule-bullet.bullet-id {
            background: rgba(155, 89, 182, 0.1);
            color: #9b59b6;
        }

        .rule-bullet.bullet-age {
            background: rgba(52, 152, 219, 0.1);
            color: #3498db;
        }

        .rule-bullet.bullet-person {
            background: rgba(46, 204, 113, 0.1);
            color: #2ecc71;
        }

        .rule-bullet.bullet-family {
            background: rgba(231, 76, 60, 0.1);
            color: #e74c3c;
        }

        .rule-bullet.bullet-payment {
            background: rgba(241, 196, 15, 0.1);
            color: #f1c40f;
        }

        .rule-text {
            font-size: 0.95rem;
            color: var(--text-dark);
            line-height: 1.6;
        }

        .rule-text strong {
            color: var(--primary-dark);
        }

        /* Benefits Visual Grid */
        .benefits-visual {
            margin-bottom: 20px;
        }

        .benefit-grid {
            border: 1px solid rgba(0,0,0,0.04);
            border-radius: 12px;
            overflow: hidden;
        }

        .benefit-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            padding: 10px 16px;
            border-bottom: 1px solid rgba(0,0,0,0.03);
            font-size: 0.9rem;
        }

        .benefit-row:last-child {
            border-bottom: none;
        }

        .benefit-row.benefit-header {
            background: rgba(26, 26, 46, 0.02);
            font-weight: 600;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-muted);
        }

        .benefit-row .benefit-amount {
            font-weight: 700;
            color: var(--primary-dark);
            text-align: right;
        }

        .benefit-row:hover:not(.benefit-header) {
            background: rgba(26, 26, 46, 0.02);
        }

        /* Bonus Benefits */
        .bonus-benefits {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-top: 16px;
        }

        .bonus-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 16px;
            border-radius: 12px;
            font-size: 0.85rem;
            background: rgba(0,0,0,0.02);
            border: 1px solid rgba(0,0,0,0.03);
        }

        .bonus-item i {
            font-size: 1.2rem;
            flex-shrink: 0;
        }

        .bonus-item.bonus-accident i {
            color: #e74c3c;
        }

        .bonus-item.bonus-goodpayer i {
            color: #f1c40f;
        }

        .bonus-item div {
            display: flex;
            flex-direction: column;
        }

        .bonus-item strong {
            color: var(--primary-dark);
            font-size: 0.85rem;
        }

        .bonus-item span {
            font-size: 0.75rem;
            color: var(--text-muted);
        }

        /* Probation Items */
        .probation-items {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .probation-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 14px;
            border-radius: 10px;
            background: rgba(0,0,0,0.02);
            font-size: 0.9rem;
        }

        .probation-item.warning {
            background: rgba(231, 76, 60, 0.04);
            border: 1px solid rgba(231, 76, 60, 0.08);
        }

        .probation-range {
            font-weight: 700;
            color: var(--primary-dark);
            min-width: 50px;
        }

        .probation-detail strong {
            color: var(--primary-dark);
        }

        .probation-note {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 14px;
            font-size: 0.85rem;
            color: var(--text-muted);
            border-left: 3px solid var(--primary-accent);
            background: rgba(0,0,0,0.01);
            margin-top: 4px;
        }

        .probation-note i {
            color: var(--primary-accent);
            font-size: 0.9rem;
        }

        /* Death Items */
        .death-items {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .death-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 14px;
            border-radius: 10px;
            background: rgba(0,0,0,0.02);
            font-size: 0.9rem;
        }

        .death-item .death-range {
            font-weight: 700;
            color: var(--primary-dark);
        }

        .death-item .death-deduction {
            color: var(--text-muted);
        }

        .death-item.serious {
            background: rgba(231, 76, 60, 0.04);
            border: 1px solid rgba(231, 76, 60, 0.06);
        }

        .death-item.serious .death-deduction {
            color: #e74c3c;
            font-weight: 600;
        }

        .death-item.critical {
            background: rgba(155, 89, 182, 0.04);
            border: 1px solid rgba(155, 89, 182, 0.06);
        }

        .death-item.critical .death-deduction {
            color: #9b59b6;
            font-weight: 600;
        }

        /* Important Note */
        .important-note {
            margin-top: 40px;
            padding: 28px 32px;
            background: linear-gradient(135deg, rgba(231, 76, 60, 0.04), rgba(231, 76, 60, 0.01));
            border-radius: 16px;
            border-left: 4px solid #e74c3c;
            display: flex;
            align-items: flex-start;
            gap: 20px;
        }

        .important-note-icon {
            width: 48px;
            height: 48px;
            background: rgba(231, 76, 60, 0.08);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .important-note-icon i {
            font-size: 1.6rem;
            color: #e74c3c;
        }

        .important-note-content h5 {
            font-weight: 700;
            color: var(--primary-dark);
            margin-bottom: 8px;
            font-size: 1.05rem;
        }

        .important-note-content p {
            margin-bottom: 0;
            color: var(--text-dark);
            font-weight: 300;
            line-height: 1.7;
        }

        /* Responsive Adjustments */
        @media (max-width: 991.98px) {
            .rules-header {
                padding: 30px 24px;
            }
            
            .rules-body {
                padding: 30px 24px;
            }
            
            .rule-card {
                padding: 24px;
            }
            
            .bonus-benefits {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 767.98px) {
            .benefit-row {
                grid-template-columns: 1fr 1fr;
                font-size: 0.8rem;
                padding: 8px 12px;
            }
            
            .important-note {
                flex-direction: column;
                align-items: center;
                text-align: center;
                padding: 24px 20px;
            }
            
            .probation-item,
            .death-item {
                flex-direction: column;
                text-align: center;
                gap: 4px;
            }
            
            .rules-header h3 {
                font-size: 1.3rem;
            }
        }
        .testimonials-section {
            padding: 100px 0;
            background: var(--white);
        }

        .testimonial-card {
            padding: 36px 32px;
            background: var(--bg-light);
            border-radius: var(--border-radius-card);
            position: relative;
            transition: var(--transition-smooth);
        }

        .testimonial-card:hover {
            transform: translateY(-6px);
            box-shadow: var(--shadow-subtle);
        }

        .testimonial-card .quote-mark {
            font-size: 4rem;
            font-family: 'Playfair Display', serif;
            color: rgba(26, 26, 46, 0.06);
            line-height: 1;
            margin-bottom: -1.5rem;
        }

        .testimonial-text {
            font-size: 1rem;
            color: var(--text-dark);
            line-height: 1.8;
            font-weight: 300;
            margin-bottom: 20px;
        }

        .testimonial-author {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .testimonial-avatar {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary-dark), var(--primary-accent));
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--white);
            font-weight: 700;
            font-size: 1rem;
            flex-shrink: 0;
        }

        .testimonial-author-info h4 {
            font-size: 1rem;
            font-weight: 700;
            color: var(--primary-dark);
            margin-bottom: 2px;
        }

        .testimonial-author-info p {
            font-size: 0.85rem;
            color: var(--text-muted);
            margin-bottom: 0;
        }

        /* ========================================
           CTA – Bold & Dramatic
           ======================================== */
        .cta-section {
            padding: 120px 0;
            background: var(--primary-dark);
            position: relative;
            overflow: hidden;
        }

        .cta-section::before {
            content: '';
            position: absolute;
            inset: 0;
            background: 
                radial-gradient(ellipse at 30% 50%, rgba(74, 111, 165, 0.08) 0%, transparent 60%),
                radial-gradient(ellipse at 70% 50%, rgba(26, 26, 46, 0.5) 0%, transparent 50%);
        }

        .cta-section .cta-pattern {
            position: absolute;
            inset: 0;
            opacity: 0.03;
            background-image: 
                linear-gradient(rgba(255,255,255,0.05) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255,255,255,0.05) 1px, transparent 1px);
            background-size: 40px 40px;
        }

        .cta-content {
            position: relative;
            z-index: 2;
            text-align: center;
        }

        .cta-content h2 {
            color: var(--white);
            font-weight: 800;
            margin-bottom: 16px;
        }

        .cta-content p {
            color: rgba(255,255,255,0.5);
            font-size: 1.2rem;
            max-width: 560px;
            margin: 0 auto 40px;
            font-weight: 300;
        }

        .cta-buttons .btn {
            padding: 1rem 3rem;
            font-weight: 600;
            border-radius: 50px;
            transition: var(--transition-smooth);
            font-size: 0.95rem;
            margin: 0 8px;
        }

        .btn-cta-primary {
            background: var(--white);
            color: var(--primary-dark) !important;
            border: none;
            box-shadow: 0 8px 30px rgba(0,0,0,0.2);
        }

        .btn-cta-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 40px rgba(0,0,0,0.3);
            color: var(--primary-dark) !important;
        }

        .btn-cta-secondary {
            background: transparent;
            color: var(--white) !important;
            border: 1px solid rgba(255,255,255,0.2);
        }

        .btn-cta-secondary:hover {
            background: rgba(255,255,255,0.05);
            border-color: rgba(255,255,255,0.4);
            color: var(--white) !important;
            transform: translateY(-3px);
        }

        /* ========================================
           FOOTER – Clean & Minimal
           ======================================== */
        .footer {
            background: var(--primary-dark);
            color: var(--white);
            padding: 80px 0 30px;
            border-top: 1px solid rgba(255,255,255,0.04);
        }

        .footer h3 {
            font-size: 1.1rem;
            font-weight: 700;
            margin-bottom: 20px;
            color: var(--white);
        }

        .footer p {
            color: rgba(255,255,255,0.4);
            font-weight: 300;
            line-height: 1.8;
            font-size: 0.95rem;
        }

        .footer .brand-footer {
            font-size: 1.4rem;
            font-weight: 800;
            margin-bottom: 12px;
        }

        .footer .brand-footer i {
            color: var(--primary-accent);
            margin-right: 10px;
        }

        .footer-links {
            list-style: none;
            padding: 0;
        }

        .footer-links li {
            margin-bottom: 10px;
        }

        .footer-links a {
            color: rgba(255,255,255,0.4);
            text-decoration: none;
            transition: var(--transition-smooth);
            font-weight: 300;
            font-size: 0.95rem;
        }

        .footer-links a:hover {
            color: var(--white);
            padding-left: 4px;
        }

        .footer-links a i {
            margin-right: 8px;
            font-size: 0.7rem;
            opacity: 0.3;
        }

        .footer-social {
            display: flex;
            gap: 12px;
            margin-top: 20px;
        }

        .footer-social a {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            border: 1px solid rgba(255,255,255,0.06);
            display: flex;
            align-items: center;
            justify-content: center;
            color: rgba(255,255,255,0.3);
            transition: var(--transition-smooth);
            text-decoration: none;
        }

        .footer-social a:hover {
            border-color: var(--primary-accent);
            color: var(--white);
            background: rgba(74, 111, 165, 0.1);
            transform: translateY(-3px);
        }

        .footer-bottom {
            text-align: center;
            padding-top: 40px;
            margin-top: 40px;
            border-top: 1px solid rgba(255,255,255,0.04);
            color: rgba(255,255,255,0.2);
            font-size: 0.85rem;
            font-weight: 300;
        }

        .footer-bottom strong {
            color: rgba(255,255,255,0.3);
        }

        /* ========================================
           RESPONSIVE
           ======================================== */
        @media (max-width: 991.98px) {
            .navbar {
                padding: 1.2rem 0;
            }
            
            .navbar .container {
                padding: 0 20px;
            }
            
            .navbar-collapse {
                background: var(--white);
                padding: 20px;
                border-radius: 16px;
                margin-top: 16px;
                box-shadow: var(--shadow-medium);
            }
            
            .navbar.scrolled .navbar-collapse {
                background: var(--white);
            }
            
            .nav-link {
                color: var(--text-dark) !important;
                padding: 0.6rem 0 !important;
                margin: 0;
            }
            
            .nav-link::after {
                background: var(--primary-dark) !important;
            }
            
            .btn-nav-primary,
            .btn-nav-secondary {
                color: var(--text-dark) !important;
                background: rgba(26, 26, 46, 0.04) !important;
                border: 1px solid rgba(26, 26, 46, 0.08) !important;
                margin-top: 4px;
            }
            
            .navbar.scrolled .btn-nav-secondary {
                background: rgba(26, 26, 46, 0.04) !important;
                color: var(--text-dark) !important;
                border-color: rgba(26, 26, 46, 0.08) !important;
            }
            
            .hero h1 {
                font-size: clamp(2.5rem, 10vw, 3.5rem);
            }
            
            .hero p {
                font-size: 1rem;
            }
            
            .hero-stats {
                gap: 1.5rem;
            }
            
            .hero-logo-container {
                max-width: 280px;
                margin: 40px auto 0;
            }
            
            .rules-header {
                padding: 30px 24px;
            }
            
            .rules-body {
                padding: 30px 24px;
            }
            
            .cta-section {
                padding: 80px 0;
            }
            
            .cta-buttons .btn {
                display: block;
                width: 100%;
                margin: 8px 0;
            }
        }

        @media (max-width: 575.98px) {
            .hero {
                min-height: 90vh;
            }
            
            .hero-content {
                padding: 100px 0 40px;
            }
            
            .hero h1 {
                font-size: clamp(2rem, 12vw, 2.8rem);
            }
            
            .hero-buttons .btn {
                display: block;
                width: 100%;
                margin: 8px 0;
                text-align: center;
            }
            
            .stat-number {
                font-size: 2.2rem;
            }
            
            .section-header h2 {
                font-size: 1.8rem;
            }
            
            .feature-card {
                padding: 30px 24px;
            }
            
            .step-number-circle {
                width: 60px;
                height: 60px;
                font-size: 1.4rem;
            }
            
            .council-avatar {
                width: 100px;
                height: 100px;
                font-size: 2.4rem;
            }
        }

        /* ========================================
           UTILITY – Smooth Reveal Enhancements
           ======================================== */
        [data-aos] {
            pointer-events: none;
        }
        
        [data-aos].aos-animate {
            pointer-events: auto;
        }

        /* Custom AOS Overrides for smoother feel */
        .aos-init {
            transition-timing-function: cubic-bezier(0.23, 1, 0.32, 1) !important;
        }
    </style>
</head>
<body>
    <!-- ========================================
    NAVIGATION – Transparent Minimal
    ======================================== -->
    <nav class="navbar navbar-expand-lg fixed-top" id="mainNav">
        <div class="container">
            <a class="navbar-brand" href="Landingpage.php">
                <i class="fas fa-hand-holding-heart"></i>Harana<span class="brand-light">.</span>
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto align-items-center">
                    <li class="nav-item"><a class="nav-link" href="#home">Home</a></li>
                    <li class="nav-item"><a class="nav-link" href="#about">About</a></li>
                    <li class="nav-item"><a class="nav-link" href="#features">Features</a></li>
                    <li class="nav-item"><a class="nav-link" href="#rules">Rules</a></li>
                    <li class="nav-item"><a class="nav-link" href="#council">Council</a></li>
                    <li class="nav-item"><a class="nav-link" href="#contact">Contact</a></li>
                    <li class="nav-item ms-lg-2">
                        <a class="nav-link btn-nav-primary" href="index.php">
                            <i class="fas fa-sign-in-alt me-2"></i>Login
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link btn-nav-secondary" href="join.php">
                            <i class="fas fa-user-plus me-2"></i>Join Now
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- ========================================
    HERO – Full-Screen Dramatic
    ======================================== -->
    <section id="home" class="hero">
        <div class="hero-pattern"></div>
        
        <div class="container hero-content">
            <div class="row align-items-center">
                <div class="col-lg-7" data-aos="fade-up" data-aos-delay="100">
                    <div class="hero-badge">
                        <i class="fas fa-shield-alt"></i> Sec. REG No. CN 700172104
                    </div>
                    
                    <h1 class="display-extra-large">
                        Financial Security<br>
                        <span class="highlight-word">for Every</span><br>
                        <span class="text-gradient-light">Community</span>
                    </h1>
                    
                    <p>
                        Join thousands of members who trust Harana for their financial needs. 
                        Community-based assistance, savings programs, and support when you need it most.
                    </p>
                    
                    <div class="hero-buttons">
                        <a href="join.php" class="btn btn-hero-primary">
                            <i class="fas fa-user-plus me-2"></i>Become a Member
                        </a>
                        <a href="#about" class="btn btn-hero-secondary">
                            <i class="fas fa-play-circle me-2"></i>Discover More
                        </a>
                    </div>
                    
                    <div class="hero-stats">
                        <div class="hero-stat-item">
                            <i class="fas fa-check-circle"></i>
                            <span><strong><?php echo number_format($member_count); ?>+</strong> Members</span>
                        </div>
                        <div class="hero-stat-item">
                            <i class="fas fa-check-circle"></i>
                            <span><strong><?php echo number_format($chapters_count); ?></strong> Chapters</span>
                        </div>
                        <div class="hero-stat-item">
                            <i class="fas fa-check-circle"></i>
                            <span><strong>5+</strong> Years Trusted</span>
                        </div>
                    </div>
                </div>
                
                <div class="col-lg-5" data-aos="fade-up" data-aos-delay="300">
                    <div class="hero-visual">
                        <div class="hero-logo-container">
                            <div class="logo-ring"></div>
                            <div class="logo-ring"></div>
                            <div class="logo-ring"></div>
                            <?php if (file_exists('assets/images/harana-logo.png')): ?>
                                <img src="assets/images/harana-logo.png" alt="Harana Financial System">
                            <?php else: ?>
                                <div class="logo-placeholder">
                                    <i class="fas fa-hand-holding-heart"></i>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="scroll-indicator">
            <span>Scroll</span>
            <div class="line"></div>
        </div>
    </section>

    <!-- ========================================
    STATS – Clean & Elevated
    ======================================== -->
    <section class="stats-section">
        <div class="container">
            <div class="row g-4">
                <div class="col-md-4" data-aos="fade-up" data-aos-delay="100">
                    <div class="stat-card">
                        <div class="stat-icon-wrapper">
                            <i class="fas fa-users"></i>
                        </div>
                        <div class="stat-number"><?php echo number_format($member_count); ?>+</div>
                        <div class="stat-label">Active Members</div>
                    </div>
                </div>
                <div class="col-md-4" data-aos="fade-up" data-aos-delay="200">
                    <div class="stat-card">
                        <div class="stat-icon-wrapper">
                            <i class="fas fa-user-tie"></i>
                        </div>
                        <div class="stat-number"><?php echo number_format($council_count); ?></div>
                        <div class="stat-label">Council Members</div>
                    </div>
                </div>
                <div class="col-md-4" data-aos="fade-up" data-aos-delay="300">
                    <div class="stat-card">
                        <div class="stat-icon-wrapper">
                            <i class="fas fa-church"></i>
                        </div>
                        <div class="stat-number"><?php echo number_format($chapters_count); ?></div>
                        <div class="stat-label">Chapters</div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ========================================
    ABOUT – Minimal & Impactful
    ======================================== -->
    <section id="about" class="features-section">
        <div class="container">
            <div class="section-header" data-aos="fade-up">
                <span class="section-tag">About Us</span>
                <h2>Building <span class="text-gradient">Financial Security</span> Together</h2>
                <p>Nagkaisang Haranista sa Gintong Luzon, Phils. Inc. — A community of mutual support and growth</p>
            </div>
            
            <div class="row align-items-center g-5">
                <div class="col-lg-6" data-aos="fade-right">
                    <div style="background: var(--primary-dark); padding: 60px; border-radius: var(--border-radius-card); text-align: center; position: relative; overflow: hidden;">
                        <div style="position: absolute; inset: 0; opacity: 0.03; background-image: linear-gradient(rgba(255,255,255,0.05) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,0.05) 1px, transparent 1px); background-size: 40px 40px;"></div>
                        <i class="fas fa-building" style="font-size: 6rem; color: rgba(255,255,255,0.08); position: relative; z-index: 1;"></i>
                        <h3 style="color: var(--white); margin-top: 20px; position: relative; z-index: 1; font-weight: 700;">Est. 2024</h3>
                        <p style="color: rgba(255,255,255,0.3); position: relative; z-index: 1; font-weight: 300;">Community-Driven Financial System</p>
                    </div>
                </div>
                <div class="col-lg-6" data-aos="fade-left">
                    <h3 style="font-weight: 800; font-size: 1.8rem; margin-bottom: 16px;">NAGKAISANG HARANISTA SA GINTONG LUZON, PHILS. INC.</h3>
                    <p style="color: var(--text-muted); font-weight: 300; line-height: 1.8; margin-bottom: 24px;">
                        <small class="text-muted">Sec. REG No. CN 700172104</small>
                    </p>
                    <p style="color: var(--text-muted); font-weight: 300; line-height: 1.8; margin-bottom: 30px;">
                        We are a community-based financial assistance organization dedicated to providing our members 
                        with financial security, support, and opportunities for growth. Our system is designed to help 
                        members save, access financial assistance when needed, and build a stronger financial future together.
                    </p>
                    
                    <div class="row g-3">
                        <div class="col-6">
                            <div class="d-flex align-items-center gap-3">
                                <div style="width: 40px; height: 40px; background: rgba(26, 26, 46, 0.04); border-radius: 12px; display: flex; align-items: center; justify-content: center;">
                                    <i class="fas fa-shield-alt" style="color: var(--primary-dark);"></i>
                                </div>
                                <div>
                                    <h6 style="font-weight: 700; margin-bottom: 0; font-size: 0.9rem;">Secure & Reliable</h6>
                                    <small style="color: var(--text-muted);">Your funds are safe</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="d-flex align-items-center gap-3">
                                <div style="width: 40px; height: 40px; background: rgba(26, 26, 46, 0.04); border-radius: 12px; display: flex; align-items: center; justify-content: center;">
                                    <i class="fas fa-hand-holding-heart" style="color: var(--primary-dark);"></i>
                                </div>
                                <div>
                                    <h6 style="font-weight: 700; margin-bottom: 0; font-size: 0.9rem;">Community Support</h6>
                                    <small style="color: var(--text-muted);">We help each other</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="d-flex align-items-center gap-3">
                                <div style="width: 40px; height: 40px; background: rgba(26, 26, 46, 0.04); border-radius: 12px; display: flex; align-items: center; justify-content: center;">
                                    <i class="fas fa-chart-line" style="color: var(--primary-dark);"></i>
                                </div>
                                <div>
                                    <h6 style="font-weight: 700; margin-bottom: 0; font-size: 0.9rem;">Financial Growth</h6>
                                    <small style="color: var(--text-muted);">Grow your savings</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="d-flex align-items-center gap-3">
                                <div style="width: 40px; height: 40px; background: rgba(26, 26, 46, 0.04); border-radius: 12px; display: flex; align-items: center; justify-content: center;">
                                    <i class="fas fa-clock" style="color: var(--primary-dark);"></i>
                                </div>
                                <div>
                                    <h6 style="font-weight: 700; margin-bottom: 0; font-size: 0.9rem;">Quick Assistance</h6>
                                    <small style="color: var(--text-muted);">Fast processing</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ========================================
    FEATURES – Card-Based Elegance
    ======================================== -->
    <section id="features" class="how-section">
        <div class="container">
            <div class="section-header" data-aos="fade-up">
                <span class="section-tag">Why Harana</span>
                <h2>Designed for <span class="text-gradient">Your Financial Wellness</span></h2>
                <p>Discover the benefits of being a Harana member</p>
            </div>
            
            <div class="row g-4">
                <?php 
                $features = [
                    ['icon' => 'fa-piggy-bank', 'title' => 'Savings Program', 'desc' => 'Build your savings with our structured contribution program. Start with as low as ₱100 monthly.'],
                    ['icon' => 'fa-hand-holding-usd', 'title' => 'Financial Assistance', 'desc' => 'Access financial assistance when you need it most — for emergencies, education, or livelihood.'],
                    ['icon' => 'fa-heartbeat', 'title' => 'Damayan Program', 'desc' => 'Our mutual aid program provides support during times of need for members and their families.'],
                    ['icon' => 'fa-gift', 'title' => 'Death & Burial Benefits', 'desc' => 'Financial assistance for burial expenses and support for bereaved families.'],
                    ['icon' => 'fa-graduation-cap', 'title' => 'Educational Support', 'desc' => 'Scholarship and educational assistance programs for members\' children.'],
                    ['icon' => 'fa-calendar-check', 'title' => 'Regular Updates', 'desc' => 'Stay informed with meeting schedules, announcements, and organization updates.'],
                ];
                foreach ($features as $index => $feature): 
                ?>
                <div class="col-lg-4 col-md-6" data-aos="fade-up" data-aos-delay="<?php echo 100 + ($index * 80); ?>">
                    <div class="feature-card">
                        <div class="feature-icon-wrapper">
                            <i class="fas <?php echo $feature['icon']; ?>"></i>
                        </div>
                        <h3><?php echo $feature['title']; ?></h3>
                        <p><?php echo $feature['desc']; ?></p>
                        <a href="#" class="feature-link">Learn More <i class="fas fa-arrow-right"></i></a>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- ========================================
    HOW IT WORKS – Steps with Connectors
    ======================================== -->
    <section class="features-section">
        <div class="container">
            <div class="section-header" data-aos="fade-up">
                <span class="section-tag">Simple Steps</span>
                <h2>How It <span class="text-gradient">Works</span></h2>
                <p>Start your journey with Harana in four simple steps</p>
            </div>
            
            <div class="row position-relative">
                <?php 
                $steps = [
                    ['num' => '1', 'title' => 'Register', 'desc' => 'Fill out the membership application form with your personal information.'],
                    ['num' => '2', 'title' => 'Get Approved', 'desc' => 'Wait for admin approval of your membership application.'],
                    ['num' => '3', 'title' => 'Start Contributing', 'desc' => 'Begin your monthly contributions to build your savings.'],
                    ['num' => '4', 'title' => 'Enjoy Benefits', 'desc' => 'Access financial assistance and other member benefits.'],
                ];
                foreach ($steps as $index => $step): 
                ?>
                <div class="col-md-3 col-6" data-aos="fade-up" data-aos-delay="<?php echo 100 + ($index * 120); ?>">
                    <div class="step-card">
                        <div class="step-number-circle"><?php echo $step['num']; ?></div>
                        <h3><?php echo $step['title']; ?></h3>
                        <p><?php echo $step['desc']; ?></p>
                        <?php if ($index < count($steps) - 1): ?>
                            <div class="step-connector"></div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

        <!-- ========================================
    RULES – REDESIGNED WITH BETTER LAYOUT
    ======================================== -->
    <section id="rules" class="rules-section">
        <div class="container">
            <div class="section-header" data-aos="fade-up">
                <span class="section-tag">Rules & Regulations</span>
                <h2>NHGL-Philippines Inc. <span class="text-gradient">Revised 2022</span></h2>
                <p>Approved by the Board of Directors</p>
            </div>

            <!-- Main Rules Container -->
            <div class="rules-container" data-aos="fade-up">
                <!-- Header with decorative element -->
                <div class="rules-header">
                    <div class="row align-items-center">
                        <div class="col-lg-8">
                            <h3><i class="fas fa-gavel me-3"></i>Mga Batas ng NHGL-Philippines Inc.</h3>
                            <p class="mb-0">Ang mga sumusunod ay ilan lamang sa mga mahahalagang batas na pinagtibay at ipinatutupad ng Nagkaisang Haranista sa Gintong Luzon Phils. Inc.</p>
                        </div>
                        <div class="col-lg-4 text-lg-end mt-3 mt-lg-0">
                            <span class="rules-badge">
                                <i class="fas fa-calendar-alt me-2"></i>Revised 2022
                            </span>
                        </div>
                    </div>
                </div>

                <div class="rules-body">
                    <!-- ============================================
                    SECTION 1: REQUIREMENTS (Left Column)
                    ============================================ -->
                    <div class="row g-4">
                        <!-- Left Column -->
                        <div class="col-lg-6">
                            <!-- Requirement Card -->
                            <div class="rule-card rule-card-requirements">
                                <div class="rule-card-header">
                                    <div class="rule-card-icon">
                                        <i class="fas fa-user-plus"></i>
                                    </div>
                                    <h4>Mga Kailangan sa Pagsapi</h4>
                                </div>
                                <ul class="rule-list-modern">
                                    <li>
                                        <span class="rule-bullet bullet-check"><i class="fas fa-check-circle"></i></span>
                                        <span class="rule-text">Personal appearance</span>
                                    </li>
                                    <li>
                                        <span class="rule-bullet bullet-file"><i class="fas fa-file-alt"></i></span>
                                        <span class="rule-text">Application form na may datos at lagda</span>
                                    </li>
                                    <li>
                                        <span class="rule-bullet bullet-medical"><i class="fas fa-stethoscope"></i></span>
                                        <span class="rule-text">Medical certificate (original copy)</span>
                                    </li>
                                    <li>
                                        <span class="rule-bullet bullet-birth"><i class="fas fa-birthday-cake"></i></span>
                                        <span class="rule-text">Kopya ng birth certificate (45 y/o pataas) o anumang katumbas na makapagpapatunay ng lugar at petsa ng kapanganakan</span>
                                    </li>
                                    <li>
                                        <span class="rule-bullet bullet-money"><i class="fas fa-money-bill-wave"></i></span>
                                        <span class="rule-text"><strong>Bayad sa pagsapi:</strong> ₱100.00 + ₱100.00 para sa ID</span>
                                    </li>
                                    <li>
                                        <span class="rule-bullet bullet-id"><i class="fas fa-id-card"></i></span>
                                        <span class="rule-text">₱60.00 bayad para sa I.D. at s.a.c.e.</span>
                                    </li>
                                </ul>
                            </div>

                            <!-- Beneficiary Card -->
                            <div class="rule-card rule-card-beneficiary">
                                <div class="rule-card-header">
                                    <div class="rule-card-icon">
                                        <i class="fas fa-user-friends"></i>
                                    </div>
                                    <h4>Beneficiary to Regular</h4>
                                </div>
                                <ul class="rule-list-modern">
                                    <li>
                                        <span class="rule-bullet bullet-age"><i class="fas fa-calendar-alt"></i></span>
                                        <span class="rule-text"><strong>10–60 taong gulang</strong></span>
                                    </li>
                                    <li>
                                        <span class="rule-bullet bullet-person"><i class="fas fa-user-check"></i></span>
                                        <span class="rule-text">Personal appearance</span>
                                    </li>
                                    <li>
                                        <span class="rule-bullet bullet-family"><i class="fas fa-heart"></i></span>
                                        <span class="rule-text">Lehitimong miyembro (Member of the family) ng namatay na pamilya lamang ang maaaring pumasok bilang miyembro</span>
                                    </li>
                                    <li>
                                        <span class="rule-bullet bullet-payment"><i class="fas fa-coins"></i></span>
                                        <span class="rule-text">Magbabayad ng katumbas ng hulog na isang taon na binayaran ng mga miyembro</span>
                                    </li>
                                </ul>
                            </div>
                        </div>

                        <!-- ============================================
                        SECTION 2: BENEFITS & PENALTIES (Right Column)
                        ============================================ -->
                        <div class="col-lg-6">
                            <!-- Benefits Card -->
                            <div class="rule-card rule-card-benefits">
                                <div class="rule-card-header">
                                    <div class="rule-card-icon">
                                        <i class="fas fa-chart-line"></i>
                                    </div>
                                    <h4>Laang Benepisyo sa Regular Member</h4>
                                </div>
                                
                                <!-- Benefits Table - Visual -->
                                <div class="benefits-visual">
                                    <div class="benefit-grid">
                                        <?php 
                                        $benefits = [
                                            ['years' => '1-2 taon', 'amount' => '₱80,000'],
                                            ['years' => '3-4 taon', 'amount' => '₱90,000'],
                                            ['years' => '5-6 taon', 'amount' => '₱100,000'],
                                            ['years' => '7-8 taon', 'amount' => '₱110,000'],
                                            ['years' => '9 taon', 'amount' => '₱115,000'],
                                            ['years' => '10 taon', 'amount' => '₱120,000'],
                                            ['years' => '11 taon', 'amount' => '₱125,000'],
                                            ['years' => '12 taon', 'amount' => '₱130,000'],
                                            ['years' => '13 taon', 'amount' => '₱135,000'],
                                            ['years' => '14 taon', 'amount' => '₱140,000'],
                                            ['years' => '15 taon', 'amount' => '₱145,000'],
                                            ['years' => '16 taon', 'amount' => '₱150,000'],
                                        ];
                                        ?>
                                        <div class="benefit-row benefit-header">
                                            <span class="benefit-years">Tagal ng Pagiging Miyembro</span>
                                            <span class="benefit-amount">Benepisyo</span>
                                        </div>
                                        <?php foreach ($benefits as $benefit): ?>
                                        <div class="benefit-row">
                                            <span class="benefit-years"><?php echo $benefit['years']; ?></span>
                                            <span class="benefit-amount"><?php echo $benefit['amount']; ?></span>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>

                                <!-- Bonus Benefits -->
                                <div class="bonus-benefits">
                                    <div class="bonus-item bonus-accident">
                                        <i class="fas fa-car-crash"></i>
                                        <div>
                                            <strong>Dagdag ₱10,000.00</strong>
                                            <span>benepisyo kung aksidente ang sanhi ng pagkamatay</span>
                                        </div>
                                    </div>
                                    <div class="bonus-item bonus-goodpayer">
                                        <i class="fas fa-star"></i>
                                        <div>
                                            <strong>Dagdag ₱5,000.00</strong>
                                            <span>benepisyo kung "Good Payer" (walang palya sa huling 5 taon)</span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Probation & Death Benefits Combined -->
                            <div class="row g-3 mt-0">
                                <div class="col-md-6">
                                    <div class="rule-card rule-card-probation">
                                        <div class="rule-card-header compact">
                                            <div class="rule-card-icon small">
                                                <i class="fas fa-clock"></i>
                                            </div>
                                            <h4>Probation Period</h4>
                                        </div>
                                        <div class="probation-items">
                                            <div class="probation-item">
                                                <span class="probation-range">51–100</span>
                                                <span class="probation-detail">patay/utang: <strong>1 buwan</strong> probation</span>
                                            </div>
                                            <div class="probation-item">
                                                <span class="probation-range">101–150</span>
                                                <span class="probation-detail">patay/utang: <strong>2 buwan</strong> probation</span>
                                            </div>
                                            <div class="probation-item warning">
                                                <span class="probation-range">150+</span>
                                                <span class="probation-detail">Maaari nang tanggalin sa talaan</span>
                                            </div>
                                            <div class="probation-note">
                                                <i class="fas fa-info-circle"></i>
                                                <span>Personal appearance ang sinumang mag late payment</span>
                                            </div>
                                            <div class="probation-note">
                                                <i class="fas fa-exclamation-circle"></i>
                                                <span>Kabuuang halaga (Full Payment) lamang ang tatanggapin</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="rule-card rule-card-death">
                                        <div class="rule-card-header compact">
                                            <div class="rule-card-icon small">
                                                <i class="fas fa-balance-scale"></i>
                                            </div>
                                            <h4>Adjusted Death Benefits</h4>
                                        </div>
                                        <div class="death-items">
                                            <div class="death-item">
                                                <span class="death-range">1–10</span>
                                                <span class="death-deduction">Bawas ₱2,500.00</span>
                                            </div>
                                            <div class="death-item">
                                                <span class="death-range">11–25</span>
                                                <span class="death-deduction">Bawas ₱5,000.00</span>
                                            </div>
                                            <div class="death-item">
                                                <span class="death-range">26–50</span>
                                                <span class="death-deduction">Bawas ₱10,000.00</span>
                                            </div>
                                            <div class="death-item serious">
                                                <span class="death-range">51–70</span>
                                                <span class="death-deduction">Kalahati ng laang benepisyo</span>
                                            </div>
                                            <div class="death-item critical">
                                                <span class="death-range">71 pataas</span>
                                                <span class="death-deduction">Donasyon depende sa tagal ng pagiging miyembro</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ============================================
                    SECTION 3: IMPORTANT NOTE (Full Width)
                    ============================================ -->
                    <div class="important-note" data-aos="fade-up" data-aos-delay="200">
                        <div class="important-note-icon">
                            <i class="fas fa-exclamation-triangle"></i>
                        </div>
                        <div class="important-note-content">
                            <h5><i class="fas fa-bullhorn me-2"></i>Mahalagang Paalala</h5>
                            <p>Ang sinumang kasapi ng ibang samahan bukod sa NHGL (dual membership) ay walang pribilehiyo tulad ng loyal member.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ========================================
    TESTIMONIALS – Elegant Cards
    ======================================== -->
    <section class="testimonials-section">
        <div class="container">
            <div class="section-header" data-aos="fade-up">
                <span class="section-tag">Testimonials</span>
                <h2>What Our <span class="text-gradient">Members Say</span></h2>
                <p>Hear from the people who trust Harana</p>
            </div>
            
            <div class="row g-4">
                <?php 
                $testimonials = [
                    [
                        'text' => '"Harana has been a blessing to our family. The financial assistance helped us during an emergency, and the savings program has taught us to be more disciplined with our finances."',
                        'name' => 'Maria Reyes',
                        'initials' => 'MR',
                        'detail' => 'Member since 2020 • Guimba Chapter'
                    ],
                    [
                        'text' => '"As a small business owner, the support from Harana has been invaluable. The community of members feels like family, and I know I can count on them when times are tough."',
                        'name' => 'Jose Santos',
                        'initials' => 'JS',
                        'detail' => 'Member since 2019 • Palayan Chapter'
                    ],
                    [
                        'text' => '"The process of applying for assistance was quick and easy. I\'m proud to be part of an organization that truly cares about its members\' welfare."',
                        'name' => 'Ana Gonzales',
                        'initials' => 'AG',
                        'detail' => 'Member since 2021 • Talavera Chapter'
                    ]
                ];
                foreach ($testimonials as $index => $testimonial): 
                ?>
                <div class="col-md-4" data-aos="fade-up" data-aos-delay="<?php echo 100 + ($index * 120); ?>">
                    <div class="testimonial-card">
                        <div class="quote-mark">"</div>
                        <p class="testimonial-text"><?php echo $testimonial['text']; ?></p>
                        <div class="testimonial-author">
                            <div class="testimonial-avatar"><?php echo $testimonial['initials']; ?></div>
                            <div class="testimonial-author-info">
                                <h4><?php echo $testimonial['name']; ?></h4>
                                <p><?php echo $testimonial['detail']; ?></p>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- ========================================
    CTA – Bold & Dramatic
    ======================================== -->
    <section class="cta-section">
        <div class="cta-pattern"></div>
        <div class="container cta-content">
            <h2 class="display-large" data-aos="fade-up">
                Ready to Start<br>
                <span class="text-gradient-light">Your Journey?</span>
            </h2>
            <p data-aos="fade-up" data-aos-delay="100">
                Join thousands of members who have found financial security and community support with Harana.
            </p>
            <div class="cta-buttons" data-aos="fade-up" data-aos-delay="200">
                <a href="join.php" class="btn btn-cta-primary">
                    <i class="fas fa-user-plus me-2"></i>Become a Member Today
                </a>
                <a href="#contact" class="btn btn-cta-secondary">
                    <i class="fas fa-phone-alt me-2"></i>Contact Us
                </a>
            </div>
        </div>
    </section>

    <!-- ========================================
    CONTACT – Minimal & Clean
    ======================================== -->
    <section id="contact" class="how-section" style="background: var(--white);">
        <div class="container">
            <div class="section-header" data-aos="fade-up">
                <span class="section-tag">Get in Touch</span>
                <h2>Contact <span class="text-gradient">Us</span></h2>
                <p>Have questions? We're here to help.</p>
            </div>
            
            <div class="row g-5">
                <div class="col-md-5" data-aos="fade-right">
                    <h4 style="font-weight: 700; margin-bottom: 24px;">Our Office</h4>
                    
                    <div class="d-flex gap-4 mb-4">
                        <div style="width: 44px; height: 44px; background: rgba(26, 26, 46, 0.04); border-radius: 12px; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                            <i class="fas fa-map-marker-alt" style="color: var(--primary-dark);"></i>
                        </div>
                        <div>
                            <h6 style="font-weight: 700; margin-bottom: 2px;">Address</h6>
                            <p style="color: var(--text-muted); margin-bottom: 0; font-weight: 300;">MF 2024, Brgy. Singalat, Palayan City, Province of Nueva Ecija</p>
                        </div>
                    </div>
                    
                    <div class="d-flex gap-4 mb-4">
                        <div style="width: 44px; height: 44px; background: rgba(26, 26, 46, 0.04); border-radius: 12px; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                            <i class="fas fa-phone-alt" style="color: var(--primary-dark);"></i>
                        </div>
                        <div>
                            <h6 style="font-weight: 700; margin-bottom: 2px;">Phone</h6>
                            <p style="color: var(--text-muted); margin-bottom: 0; font-weight: 300;">(044) 940-6708</p>
                        </div>
                    </div>
                    
                    <div class="d-flex gap-4 mb-4">
                        <div style="width: 44px; height: 44px; background: rgba(26, 26, 46, 0.04); border-radius: 12px; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                            <i class="fas fa-envelope" style="color: var(--primary-dark);"></i>
                        </div>
                        <div>
                            <h6 style="font-weight: 700; margin-bottom: 2px;">Email</h6>
                            <p style="color: var(--text-muted); margin-bottom: 0; font-weight: 300;">info@harana.com</p>
                        </div>
                    </div>
                    
                    <div class="d-flex gap-4 mb-4">
                        <div style="width: 44px; height: 44px; background: rgba(26, 26, 46, 0.04); border-radius: 12px; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                            <i class="fas fa-clock" style="color: var(--primary-dark);"></i>
                        </div>
                        <div>
                            <h6 style="font-weight: 700; margin-bottom: 2px;">Office Hours</h6>
                            <p style="color: var(--text-muted); margin-bottom: 0; font-weight: 300;">Mon-Fri: 8:00 AM - 5:00 PM<br>Sat: 8:00 AM - 12:00 PM</p>
                        </div>
                    </div>
                    
                    <div class="footer-social">
                        <a href="#"><i class="fab fa-facebook-f"></i></a>
                        <a href="#"><i class="fab fa-twitter"></i></a>
                        <a href="#"><i class="fab fa-instagram"></i></a>
                        <a href="#"><i class="fab fa-youtube"></i></a>
                    </div>
                </div>
                
                <div class="col-md-7" data-aos="fade-left">
                    <form action="#" method="POST" class="contact-form">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <input type="text" class="form-control" placeholder="Your Name" required style="padding: 14px 18px; border-radius: 12px; border: 1px solid rgba(0,0,0,0.06); font-weight: 300;">
                            </div>
                            <div class="col-md-6">
                                <input type="email" class="form-control" placeholder="Your Email" required style="padding: 14px 18px; border-radius: 12px; border: 1px solid rgba(0,0,0,0.06); font-weight: 300;">
                            </div>
                        </div>
                        <div class="mt-3">
                            <input type="text" class="form-control" placeholder="Subject" required style="padding: 14px 18px; border-radius: 12px; border: 1px solid rgba(0,0,0,0.06); font-weight: 300;">
                        </div>
                        <div class="mt-3">
                            <textarea class="form-control" rows="5" placeholder="Your Message" required style="padding: 14px 18px; border-radius: 12px; border: 1px solid rgba(0,0,0,0.06); font-weight: 300; resize: vertical;"></textarea>
                        </div>
                        <button type="submit" class="btn btn-cta-primary w-100 mt-3" style="background: var(--primary-dark); color: var(--white) !important; padding: 1rem;">
                            <i class="fas fa-paper-plane me-2"></i>Send Message
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </section>

    <!-- ========================================
    FOOTER – Clean & Minimal
    ======================================== -->
    <footer class="footer">
        <div class="container">
            <div class="row g-4">
                <div class="col-lg-4 col-md-6">
                    <div class="brand-footer">
                        <i class="fas fa-hand-holding-heart"></i>Harana<span style="opacity: 0.3;">.</span>
                    </div>
                    <p>Nagkaisang Haranista sa Gintong Luzon, Phils. Inc. (NHGL, Inc.)<br>
                    <small style="opacity: 0.3;">(Formerly Nagkaisang Haranista Sa Gintong Luzon, Inc.)</small></p>
                    <p style="margin-top: 16px;">Building a community of financial security and mutual support for all members.</p>
                </div>
                
                <div class="col-lg-2 col-md-6">
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
                
                <div class="col-lg-3 col-md-6">
                    <h3>For Members</h3>
                    <ul class="footer-links">
                        <li><a href="index.php"><i class="fas fa-chevron-right"></i>Member Login</a></li>
                        <li><a href="join.php"><i class="fas fa-chevron-right"></i>Register</a></li>
                        <li><a href="#"><i class="fas fa-chevron-right"></i>Member Benefits</a></li>
                        <li><a href="#"><i class="fas fa-chevron-right"></i>FAQs</a></li>
                        <li><a href="#"><i class="fas fa-chevron-right"></i>Privacy Policy</a></li>
                    </ul>
                </div>
                
                <div class="col-lg-3 col-md-6">
                    <h3>Newsletter</h3>
                    <p style="margin-bottom: 16px;">Subscribe to get updates on announcements and events.</p>
                    <form class="newsletter-form">
                        <div style="display: flex; gap: 8px;">
                            <input type="email" class="form-control" placeholder="Your Email" style="padding: 12px 16px; border-radius: 50px; border: 1px solid rgba(255,255,255,0.06); background: rgba(255,255,255,0.03); color: var(--white); font-weight: 300;">
                            <button type="submit" style="padding: 12px 20px; border-radius: 50px; border: none; background: var(--primary-accent); color: var(--white); font-weight: 600; transition: var(--transition-smooth);">
                                <i class="fas fa-paper-plane"></i>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            
            <div class="footer-bottom">
                <p>&copy; <?php echo date('Y'); ?> Harana Financial System. All rights reserved. | Sec. REG No. CN 700172104</p>
            </div>
        </div>
    </footer>

    <!-- ========================================
    SCRIPTS
    ======================================== -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
    <script>
        // Initialize AOS with refined settings
        AOS.init({
            duration: 800,
            easing: 'cubic-bezier(0.23, 1, 0.32, 1)',
            once: true,
            offset: 60,
            delay: 0
        });

        // Navbar scroll effect
        const navbar = document.getElementById('mainNav');
        let lastScroll = 0;

        window.addEventListener('scroll', function() {
            const currentScroll = window.pageYOffset || document.documentElement.scrollTop;
            
            if (currentScroll > 50) {
                navbar.classList.add('scrolled');
            } else {
                navbar.classList.remove('scrolled');
            }
            
            lastScroll = currentScroll;
        });

        // Smooth scrolling for anchor links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function(e) {
                e.preventDefault();
                const targetId = this.getAttribute('href');
                if (targetId === '#') return;
                
                const target = document.querySelector(targetId);
                if (target) {
                    const navHeight = navbar.offsetHeight;
                    const targetPosition = target.getBoundingClientRect().top + window.pageYOffset - navHeight - 20;
                    
                    window.scrollTo({
                        top: targetPosition,
                        behavior: 'smooth'
                    });
                }
            });
        });

        // Contact form submission
        document.querySelector('.contact-form')?.addEventListener('submit', function(e) {
            e.preventDefault();
            alert('Thank you for your message! We will get back to you soon.');
            this.reset();
        });

        // Newsletter form submission
        document.querySelector('.newsletter-form')?.addEventListener('submit', function(e) {
            e.preventDefault();
            alert('Thank you for subscribing to our newsletter!');
            this.reset();
        });

        // Close mobile menu on link click
        document.querySelectorAll('.navbar-collapse .nav-link').forEach(link => {
            link.addEventListener('click', () => {
                const collapse = document.querySelector('.navbar-collapse');
                if (collapse.classList.contains('show')) {
                    const toggler = document.querySelector('.navbar-toggler');
                    toggler.click();
                }
            });
        });
    </script>
</body>
</html>