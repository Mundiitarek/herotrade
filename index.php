<?php
define('APP_ACCESS', true);
require_once 'config.php';

// Redirect if logged in
if (is_logged_in()) {
    header('Location: dashboard.php');
    exit;
}

$dir = get_dir();
$is_rtl = is_rtl();
?>
<!DOCTYPE html>
<html lang="<?php echo $is_rtl ? 'ar' : 'en'; ?>" dir="<?php echo $dir; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo t('HeroTrade - Trade Smart, Trade with Heroes'); ?></title>
    <meta name="description" content="<?php echo t('Professional trading platform for Binary Trading, Spot Trading, and P2P Payments'); ?>">

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@300;400;500;600;700;800;900&family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --primary: #0F172A;
            --secondary: #1E293B;
            --accent: #F59E0B;
            --success: #10B981;
            --danger: #EF4444;
            --text-primary: #F1F5F9;
            --text-secondary: #94A3B8;
            --border: rgba(148, 163, 184, 0.1);
            --glass: rgba(30, 41, 59, 0.6);
        }

        body {
            font-family: <?php echo $is_rtl ? "'Tajawal', 'Poppins', sans-serif" : "'Poppins', 'Tajawal', sans-serif"; ?>;
            background: var(--primary);
            color: var(--text-primary);
            line-height: 1.6;
            overflow-x: hidden;
            direction: <?php echo $dir; ?>;
        }

        /* Animated Background */
        .bg-animated {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 0;
            background:
                radial-gradient(circle at 20% 30%, rgba(245, 158, 11, 0.08) 0%, transparent 50%),
                radial-gradient(circle at 80% 70%, rgba(16, 185, 129, 0.06) 0%, transparent 50%);
            animation: bgPulse 20s ease-in-out infinite;
        }

        @keyframes bgPulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.7; }
        }

        .bg-animated::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-image:
                linear-gradient(rgba(148, 163, 184, 0.02) 1px, transparent 1px),
                linear-gradient(90deg, rgba(148, 163, 184, 0.02) 1px, transparent 1px);
            background-size: 40px 40px;
        }

        /* Header/Navigation */
        header {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            padding: 20px 0;
            background: rgba(15, 23, 42, 0.8);
            backdrop-filter: blur(20px);
            border-bottom: 1px solid var(--border);
            z-index: 1000;
            transition: all 0.3s ease;
        }

        header.scrolled {
            padding: 15px 0;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
        }

        .nav-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
            color: var(--text-primary);
            font-size: 24px;
            font-weight: 800;
        }

        .logo i {
            width: 45px;
            height: 45px;
            background: linear-gradient(135deg, var(--accent), var(--success));
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            box-shadow: 0 8px 20px rgba(245, 158, 11, 0.3);
        }

        .logo-text {
            background: linear-gradient(135deg, var(--text-primary), var(--accent));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .nav-right {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .lang-switcher {
            display: flex;
            gap: 8px;
            background: var(--glass);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 6px;
        }

        .lang-btn {
            padding: 8px 16px;
            border: none;
            background: transparent;
            color: var(--text-secondary);
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            font-size: 14px;
            transition: all 0.3s ease;
            font-family: inherit;
        }

        .lang-btn.active {
            background: var(--accent);
            color: white;
        }

        .lang-btn:hover:not(.active) {
            color: var(--text-primary);
        }

        .nav-buttons {
            display: flex;
            gap: 12px;
        }

        .btn {
            padding: 12px 24px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-family: inherit;
        }

        .btn-outline {
            background: transparent;
            color: var(--text-primary);
            border: 1px solid var(--border);
        }

        .btn-outline:hover {
            border-color: var(--accent);
            color: var(--accent);
        }

        .btn-primary {
            background: var(--accent);
            color: white;
            box-shadow: 0 4px 14px rgba(245, 158, 11, 0.3);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(245, 158, 11, 0.4);
        }

        .mobile-menu-btn {
            display: none;
            background: none;
            border: none;
            color: var(--text-primary);
            font-size: 24px;
            cursor: pointer;
        }

        /* Hero Section */
        .hero {
            min-height: 100vh;
            display: flex;
            align-items: center;
            padding: 120px 30px 80px;
            position: relative;
            z-index: 1;
        }

        .hero-container {
            max-width: 1400px;
            margin: 0 auto;
            width: 100%;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 80px;
            align-items: center;
        }

        .hero-content h1 {
            font-size: 64px;
            font-weight: 900;
            line-height: 1.1;
            margin-bottom: 24px;
            background: linear-gradient(135deg, var(--text-primary), var(--accent));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .hero-content p {
            font-size: 20px;
            color: var(--text-secondary);
            margin-bottom: 40px;
            line-height: 1.7;
        }

        .hero-buttons {
            display: flex;
            gap: 16px;
            flex-wrap: wrap;
        }

        .btn-large {
            padding: 18px 36px;
            font-size: 16px;
        }

        .btn-outline-white {
            background: var(--glass);
            color: var(--text-primary);
            border: 1px solid var(--border);
        }

        .btn-outline-white:hover {
            background: rgba(30, 41, 59, 0.8);
            border-color: var(--accent);
        }

        .hero-image {
            position: relative;
        }

        .hero-card {
            background: var(--glass);
            backdrop-filter: blur(20px);
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 40px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.4);
            position: relative;
            overflow: hidden;
        }

        .hero-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 3px;
            background: linear-gradient(90deg, var(--accent), var(--success));
        }

        .chart-placeholder {
            height: 250px;
            background: rgba(15, 23, 42, 0.5);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 20px;
        }

        .chart-placeholder i {
            font-size: 64px;
            color: var(--accent);
            opacity: 0.3;
        }

        /* Features Section */
        .features {
            padding: 100px 30px;
            position: relative;
            z-index: 1;
        }

        .section-header {
            text-align: center;
            margin-bottom: 60px;
        }

        .section-title {
            font-size: 48px;
            font-weight: 900;
            margin-bottom: 16px;
            background: linear-gradient(135deg, var(--text-primary), var(--accent));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .section-subtitle {
            font-size: 18px;
            color: var(--text-secondary);
            max-width: 700px;
            margin: 0 auto;
        }

        .features-grid {
            max-width: 1400px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 30px;
        }

        .feature-card {
            background: var(--glass);
            backdrop-filter: blur(20px);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 40px 30px;
            text-align: center;
            transition: all 0.3s ease;
            opacity: 0;
            transform: translateY(20px);
        }

        .feature-card.visible {
            opacity: 1;
            transform: translateY(0);
        }

        .feature-card:hover {
            transform: translateY(-10px);
            border-color: var(--accent);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
        }

        .feature-icon {
            width: 70px;
            height: 70px;
            background: linear-gradient(135deg, var(--accent), var(--success));
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            margin: 0 auto 24px;
            box-shadow: 0 10px 30px rgba(245, 158, 11, 0.3);
        }

        .feature-card h3 {
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 12px;
        }

        .feature-card p {
            color: var(--text-secondary);
            font-size: 15px;
            line-height: 1.6;
        }

        /* Markets Section */
        .markets {
            padding: 100px 30px;
            background: var(--secondary);
            position: relative;
            z-index: 1;
        }

        .markets-tabs {
            max-width: 1400px;
            margin: 0 auto 40px;
            display: flex;
            gap: 12px;
            justify-content: center;
            flex-wrap: wrap;
        }

        .tab-btn {
            padding: 12px 24px;
            background: var(--glass);
            border: 1px solid var(--border);
            border-radius: 8px;
            color: var(--text-secondary);
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            font-family: inherit;
            font-size: 14px;
        }

        .tab-btn.active {
            background: var(--accent);
            color: white;
            border-color: var(--accent);
        }

        .tab-btn:hover:not(.active) {
            border-color: var(--accent);
            color: var(--text-primary);
        }

        .markets-grid {
            max-width: 1400px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 24px;
        }

        .market-card {
            background: var(--glass);
            backdrop-filter: blur(20px);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 24px;
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .market-card:hover {
            border-color: var(--accent);
            transform: translateY(-5px);
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.3);
        }

        .market-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 16px;
        }

        .market-icon {
            width: 42px;
            height: 42px;
            background: linear-gradient(135deg, var(--accent), var(--success));
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
        }

        .market-info h4 {
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 2px;
        }

        .market-info span {
            font-size: 12px;
            color: var(--text-secondary);
        }

        .market-price {
            font-size: 22px;
            font-weight: 800;
            margin-bottom: 8px;
        }

        .market-change {
            font-size: 14px;
            font-weight: 600;
        }

        .market-change.positive {
            color: var(--success);
        }

        .market-change.negative {
            color: var(--danger);
        }

        /* How It Works Section */
        .how-it-works {
            padding: 100px 30px;
            position: relative;
            z-index: 1;
        }

        .steps-container {
            max-width: 1200px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 40px;
        }

        .step-card {
            text-align: center;
            position: relative;
            opacity: 0;
            transform: translateY(20px);
        }

        .step-card.visible {
            opacity: 1;
            transform: translateY(0);
        }

        .step-number {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, var(--accent), var(--success));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            font-weight: 900;
            margin: 0 auto 24px;
            box-shadow: 0 10px 30px rgba(245, 158, 11, 0.4);
        }

        .step-card h3 {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 12px;
        }

        .step-card p {
            color: var(--text-secondary);
            font-size: 16px;
            line-height: 1.6;
        }

        .step-card::after {
            content: '→';
            position: absolute;
            top: 40px;
            <?php echo $is_rtl ? 'left' : 'right'; ?>: -30px;
            font-size: 32px;
            color: var(--accent);
            opacity: 0.3;
        }

        .step-card:last-child::after {
            display: none;
        }

        /* Footer */
        footer {
            background: var(--secondary);
            border-top: 1px solid var(--border);
            padding: 60px 30px 30px;
            position: relative;
            z-index: 1;
        }

        .footer-container {
            max-width: 1400px;
            margin: 0 auto;
        }

        .footer-content {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr;
            gap: 60px;
            margin-bottom: 40px;
        }

        .footer-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 20px;
        }

        .footer-description {
            color: var(--text-secondary);
            line-height: 1.7;
            margin-bottom: 20px;
        }

        .footer-title {
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 20px;
        }

        .footer-links {
            list-style: none;
        }

        .footer-links li {
            margin-bottom: 12px;
        }

        .footer-links a {
            color: var(--text-secondary);
            text-decoration: none;
            transition: color 0.3s ease;
        }

        .footer-links a:hover {
            color: var(--accent);
        }

        .footer-bottom {
            padding-top: 30px;
            border-top: 1px solid var(--border);
            text-align: center;
            color: var(--text-secondary);
            font-size: 14px;
        }

        /* Responsive Design */
        @media (max-width: 1024px) {
            .hero-container {
                grid-template-columns: 1fr;
                gap: 60px;
            }

            .hero-content h1 {
                font-size: 48px;
            }

            .features-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .markets-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .steps-container {
                grid-template-columns: 1fr;
            }

            .step-card::after {
                content: '↓';
                top: auto;
                bottom: -30px;
                <?php echo $is_rtl ? 'left' : 'right'; ?>: 50%;
                transform: translateX(50%);
            }

            .footer-content {
                grid-template-columns: repeat(2, 1fr);
                gap: 40px;
            }
        }

        @media (max-width: 768px) {
            .nav-buttons,
            .lang-switcher {
                display: none;
            }

            .mobile-menu-btn {
                display: block;
            }

            .hero-content h1 {
                font-size: 36px;
            }

            .hero-content p {
                font-size: 16px;
            }

            .section-title {
                font-size: 32px;
            }

            .features-grid {
                grid-template-columns: 1fr;
            }

            .markets-grid {
                grid-template-columns: 1fr;
            }

            .footer-content {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 480px) {
            .hero {
                padding: 100px 20px 60px;
            }

            .hero-content h1 {
                font-size: 28px;
            }

            .btn-large {
                padding: 14px 24px;
                font-size: 14px;
            }

            .hero-buttons {
                flex-direction: column;
            }

            .hero-buttons .btn {
                width: 100%;
                justify-content: center;
            }
        }

        /* Animations */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .animate-fadeInUp {
            animation: fadeInUp 0.6s ease-out forwards;
        }
    </style>
</head>
<body>
    <div class="bg-animated"></div>

    <!-- Header/Navigation -->
    <header id="header">
        <div class="nav-container">
            <a href="/" class="logo">
                <i class="fas fa-shield-alt"></i>
                <span class="logo-text">HeroTrade</span>
            </a>

            <div class="nav-right">
                <!-- Language Switcher -->
                <div class="lang-switcher">
                    <button class="lang-btn <?php echo $is_rtl ? 'active' : ''; ?>" onclick="switchLanguage('ar')">
                        العربية
                    </button>
                    <button class="lang-btn <?php echo !$is_rtl ? 'active' : ''; ?>" onclick="switchLanguage('en')">
                        English
                    </button>
                </div>

                <!-- Auth Buttons -->
                <div class="nav-buttons">
                    <a href="login.php" class="btn btn-outline">
                        <i class="fas fa-sign-in-alt"></i>
                        <?php echo t('Login'); ?>
                    </a>
                    <a href="register.php" class="btn btn-primary">
                        <i class="fas fa-user-plus"></i>
                        <?php echo t('Register'); ?>
                    </a>
                </div>

                <button class="mobile-menu-btn">
                    <i class="fas fa-bars"></i>
                </button>
            </div>
        </div>
    </header>

    <!-- Hero Section -->
    <section class="hero">
        <div class="hero-container">
            <div class="hero-content">
                <h1><?php echo t('Trade Smart, Trade with Heroes'); ?></h1>
                <p><?php echo t('Join the most advanced trading platform. Trade Binary Options, Spot Markets, and exchange with P2P payments across multiple assets worldwide.'); ?></p>

                <div class="hero-buttons">
                    <a href="register.php" class="btn btn-primary btn-large">
                        <i class="fas fa-rocket"></i>
                        <?php echo t('Start Trading'); ?>
                    </a>
                    <a href="#features" class="btn btn-outline-white btn-large">
                        <i class="fas fa-info-circle"></i>
                        <?php echo t('Learn More'); ?>
                    </a>
                </div>
            </div>

            <div class="hero-image">
                <div class="hero-card">
                    <div class="chart-placeholder">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                        <div style="background: var(--success); padding: 16px; border-radius: 8px; text-align: center; font-weight: 700;">
                            <i class="fas fa-arrow-up"></i> <?php echo t('BUY'); ?>
                        </div>
                        <div style="background: var(--danger); padding: 16px; border-radius: 8px; text-align: center; font-weight: 700;">
                            <i class="fas fa-arrow-down"></i> <?php echo t('SELL'); ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Features Section -->
    <section class="features" id="features">
        <div class="section-header">
            <h2 class="section-title"><?php echo t('Why Choose HeroTrade'); ?></h2>
            <p class="section-subtitle"><?php echo t('Discover powerful features designed for professional traders'); ?></p>
        </div>

        <div class="features-grid">
            <div class="feature-card">
                <div class="feature-icon">
                    <i class="fas fa-clock"></i>
                </div>
                <h3><?php echo t('Binary Trading'); ?></h3>
                <p><?php echo t('Trade binary options with countdown timers. Fast execution and instant payouts up to 85%.'); ?></p>
            </div>

            <div class="feature-card">
                <div class="feature-icon">
                    <i class="fas fa-chart-area"></i>
                </div>
                <h3><?php echo t('Spot Trading'); ?></h3>
                <p><?php echo t('Access real-time spot markets with advanced charting tools and technical indicators.'); ?></p>
            </div>

            <div class="feature-card">
                <div class="feature-icon">
                    <i class="fas fa-globe"></i>
                </div>
                <h3><?php echo t('Multi-Asset'); ?></h3>
                <p><?php echo t('Trade Crypto, Forex, Indices, and Stocks all from one powerful platform.'); ?></p>
            </div>

            <div class="feature-card">
                <div class="feature-icon">
                    <i class="fas fa-exchange-alt"></i>
                </div>
                <h3><?php echo t('P2P Payments'); ?></h3>
                <p><?php echo t('Peer-to-peer exchange system for fast and secure deposits and withdrawals.'); ?></p>
            </div>
        </div>
    </section>

    <!-- Markets Section -->
    <section class="markets" id="markets">
        <div class="section-header">
            <h2 class="section-title"><?php echo t('Explore Markets'); ?></h2>
            <p class="section-subtitle"><?php echo t('Trade the most popular assets across multiple categories'); ?></p>
        </div>

        <div class="markets-tabs">
            <button class="tab-btn active" onclick="switchTab('crypto')">
                <i class="fab fa-bitcoin"></i> <?php echo t('Crypto'); ?>
            </button>
            <button class="tab-btn" onclick="switchTab('forex')">
                <i class="fas fa-dollar-sign"></i> <?php echo t('Forex'); ?>
            </button>
            <button class="tab-btn" onclick="switchTab('indices')">
                <i class="fas fa-chart-pie"></i> <?php echo t('Indices'); ?>
            </button>
            <button class="tab-btn" onclick="switchTab('stocks')">
                <i class="fas fa-building"></i> <?php echo t('Stocks'); ?>
            </button>
        </div>

        <div class="markets-grid" id="markets-grid">
            <!-- Crypto Markets (Default) -->
            <div class="market-card">
                <div class="market-header">
                    <div class="market-icon">
                        <i class="fab fa-bitcoin"></i>
                    </div>
                    <div class="market-info">
                        <h4>Bitcoin</h4>
                        <span>BTC/USDT</span>
                    </div>
                </div>
                <div class="market-price">$67,234.50</div>
                <div class="market-change positive">
                    <i class="fas fa-arrow-up"></i> +2.34%
                </div>
            </div>

            <div class="market-card">
                <div class="market-header">
                    <div class="market-icon">
                        <i class="fab fa-ethereum"></i>
                    </div>
                    <div class="market-info">
                        <h4>Ethereum</h4>
                        <span>ETH/USDT</span>
                    </div>
                </div>
                <div class="market-price">$3,456.78</div>
                <div class="market-change positive">
                    <i class="fas fa-arrow-up"></i> +1.89%
                </div>
            </div>

            <div class="market-card">
                <div class="market-header">
                    <div class="market-icon">
                        <i class="fas fa-coins"></i>
                    </div>
                    <div class="market-info">
                        <h4>Ripple</h4>
                        <span>XRP/USDT</span>
                    </div>
                </div>
                <div class="market-price">$0.6234</div>
                <div class="market-change negative">
                    <i class="fas fa-arrow-down"></i> -0.56%
                </div>
            </div>

            <div class="market-card">
                <div class="market-header">
                    <div class="market-icon">
                        <i class="fas fa-coins"></i>
                    </div>
                    <div class="market-info">
                        <h4>Cardano</h4>
                        <span>ADA/USDT</span>
                    </div>
                </div>
                <div class="market-price">$0.4523</div>
                <div class="market-change positive">
                    <i class="fas fa-arrow-up"></i> +3.12%
                </div>
            </div>
        </div>
    </section>

    <!-- How It Works Section -->
    <section class="how-it-works" id="how-it-works">
        <div class="section-header">
            <h2 class="section-title"><?php echo t('How It Works'); ?></h2>
            <p class="section-subtitle"><?php echo t('Get started in three simple steps'); ?></p>
        </div>

        <div class="steps-container">
            <div class="step-card">
                <div class="step-number">1</div>
                <h3><?php echo t('Register'); ?></h3>
                <p><?php echo t('Create your free account in less than 2 minutes. No credit card required.'); ?></p>
            </div>

            <div class="step-card">
                <div class="step-number">2</div>
                <h3><?php echo t('Deposit'); ?></h3>
                <p><?php echo t('Fund your account using various payment methods including P2P exchange.'); ?></p>
            </div>

            <div class="step-card">
                <div class="step-number">3</div>
                <h3><?php echo t('Trade'); ?></h3>
                <p><?php echo t('Start trading Binary Options, Spot Markets, and more with professional tools.'); ?></p>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer>
        <div class="footer-container">
            <div class="footer-content">
                <div>
                    <div class="footer-brand">
                        <i class="fas fa-shield-alt" style="width: 40px; height: 40px; background: linear-gradient(135deg, var(--accent), var(--success)); border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 18px;"></i>
                        <span class="logo-text" style="font-size: 20px; font-weight: 800;">HeroTrade</span>
                    </div>
                    <p class="footer-description">
                        <?php echo t('Professional trading platform for Binary Trading, Spot Markets, and P2P Payments. Trade smart with the heroes.'); ?>
                    </p>
                </div>

                <div>
                    <h4 class="footer-title"><?php echo t('Platform'); ?></h4>
                    <ul class="footer-links">
                        <li><a href="#features"><?php echo t('Features'); ?></a></li>
                        <li><a href="#markets"><?php echo t('Markets'); ?></a></li>
                        <li><a href="#how-it-works"><?php echo t('How It Works'); ?></a></li>
                        <li><a href="register.php"><?php echo t('Get Started'); ?></a></li>
                    </ul>
                </div>

                <div>
                    <h4 class="footer-title"><?php echo t('Support'); ?></h4>
                    <ul class="footer-links">
                        <li><a href="#"><?php echo t('Help Center'); ?></a></li>
                        <li><a href="#"><?php echo t('FAQ'); ?></a></li>
                        <li><a href="#"><?php echo t('Contact Us'); ?></a></li>
                        <li><a href="#"><?php echo t('Trading Guide'); ?></a></li>
                    </ul>
                </div>

                <div>
                    <h4 class="footer-title"><?php echo t('Legal'); ?></h4>
                    <ul class="footer-links">
                        <li><a href="#"><?php echo t('Terms of Service'); ?></a></li>
                        <li><a href="#"><?php echo t('Privacy Policy'); ?></a></li>
                        <li><a href="#"><?php echo t('Risk Disclosure'); ?></a></li>
                        <li><a href="#"><?php echo t('Compliance'); ?></a></li>
                    </ul>
                </div>
            </div>

            <div class="footer-bottom">
                <p>&copy; <?php echo date('Y'); ?> HeroTrade. <?php echo t('All rights reserved.'); ?></p>
            </div>
        </div>
    </footer>

    <script>
        // Header scroll effect
        window.addEventListener('scroll', () => {
            const header = document.getElementById('header');
            if (window.scrollY > 50) {
                header.classList.add('scrolled');
            } else {
                header.classList.remove('scrolled');
            }
        });

        // Smooth scroll for anchor links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function(e) {
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

        // Intersection Observer for fade-in animations
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        };

        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('visible');
                }
            });
        }, observerOptions);

        // Observe feature cards and step cards
        document.querySelectorAll('.feature-card, .step-card').forEach(card => {
            observer.observe(card);
        });

        // Language switcher
        function switchLanguage(lang) {
            const url = new URL(window.location.href);
            url.searchParams.set('lang', lang);
            window.location.href = url.toString();
        }

        // Market tabs switcher
        function switchTab(category) {
            // Update active tab
            document.querySelectorAll('.tab-btn').forEach(btn => {
                btn.classList.remove('active');
            });
            event.target.closest('.tab-btn').classList.add('active');

            // Market data for different categories
            const marketData = {
                crypto: [
                    { icon: 'fab fa-bitcoin', name: 'Bitcoin', symbol: 'BTC/USDT', price: '$67,234.50', change: '+2.34%', positive: true },
                    { icon: 'fab fa-ethereum', name: 'Ethereum', symbol: 'ETH/USDT', price: '$3,456.78', change: '+1.89%', positive: true },
                    { icon: 'fas fa-coins', name: 'Ripple', symbol: 'XRP/USDT', price: '$0.6234', change: '-0.56%', positive: false },
                    { icon: 'fas fa-coins', name: 'Cardano', symbol: 'ADA/USDT', price: '$0.4523', change: '+3.12%', positive: true }
                ],
                forex: [
                    { icon: 'fas fa-dollar-sign', name: 'EUR/USD', symbol: 'Euro/Dollar', price: '1.0845', change: '+0.23%', positive: true },
                    { icon: 'fas fa-pound-sign', name: 'GBP/USD', symbol: 'Pound/Dollar', price: '1.2634', change: '-0.12%', positive: false },
                    { icon: 'fas fa-yen-sign', name: 'USD/JPY', symbol: 'Dollar/Yen', price: '149.76', change: '+0.45%', positive: true },
                    { icon: 'fas fa-dollar-sign', name: 'USD/CHF', symbol: 'Dollar/Franc', price: '0.8923', change: '+0.18%', positive: true }
                ],
                indices: [
                    { icon: 'fas fa-chart-line', name: 'S&P 500', symbol: 'US500', price: '4,567.89', change: '+1.23%', positive: true },
                    { icon: 'fas fa-chart-line', name: 'NASDAQ', symbol: 'US100', price: '15,234.56', change: '+1.67%', positive: true },
                    { icon: 'fas fa-chart-line', name: 'Dow Jones', symbol: 'US30', price: '35,678.90', change: '+0.89%', positive: true },
                    { icon: 'fas fa-chart-line', name: 'FTSE 100', symbol: 'UK100', price: '7,456.78', change: '-0.34%', positive: false }
                ],
                stocks: [
                    { icon: 'fab fa-apple', name: 'Apple', symbol: 'AAPL', price: '$178.45', change: '+2.15%', positive: true },
                    { icon: 'fab fa-microsoft', name: 'Microsoft', symbol: 'MSFT', price: '$367.89', change: '+1.34%', positive: true },
                    { icon: 'fab fa-amazon', name: 'Amazon', symbol: 'AMZN', price: '$145.67', change: '+0.78%', positive: true },
                    { icon: 'fab fa-google', name: 'Google', symbol: 'GOOGL', price: '$134.23', change: '-0.23%', positive: false }
                ]
            };

            // Update market cards
            const grid = document.getElementById('markets-grid');
            const markets = marketData[category];

            grid.innerHTML = markets.map(market => `
                <div class="market-card">
                    <div class="market-header">
                        <div class="market-icon">
                            <i class="${market.icon}"></i>
                        </div>
                        <div class="market-info">
                            <h4>${market.name}</h4>
                            <span>${market.symbol}</span>
                        </div>
                    </div>
                    <div class="market-price">${market.price}</div>
                    <div class="market-change ${market.positive ? 'positive' : 'negative'}">
                        <i class="fas fa-arrow-${market.positive ? 'up' : 'down'}"></i> ${market.change}
                    </div>
                </div>
            `).join('');
        }

        // Simulate price updates
        setInterval(() => {
            document.querySelectorAll('.market-price').forEach(el => {
                const isPositive = Math.random() > 0.5;
                const changePercent = (Math.random() * 2 - 1).toFixed(2);

                if (Math.abs(changePercent) > 0.1) {
                    el.style.color = isPositive ? 'var(--success)' : 'var(--danger)';
                    setTimeout(() => {
                        el.style.color = '';
                    }, 500);
                }
            });
        }, 3000);
    </script>
</body>
</html>
