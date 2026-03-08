<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>About Us - Subodha Ayurveda Hospital</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/aos/2.3.4/aos.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500;600;700&family=Quicksand:wght@300;400;500;600&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <style>
        /* Include ALL CSS directly in the file to ensure it loads */
        :root {
            --sage-green: #87A96B;
            --mint-cream: #F0FFF0;
            --seafoam: #9FE2BF;
            --forest-mist: #B8E0D2;
            --willow: #B5C8A4;
            --dusty-teal: #6D9B8E;
            --deep-emerald: #4A766E;
            --soft-olive: #8A9A5B;
        }
        
        body {
            font-family: 'Quicksand', sans-serif;
            color: #5A5A5A;
            overflow-x: hidden;
            padding-top: 76px; /* Add padding for fixed navbar */
        }
        
        h1, h2, h3, h4, h5, .display-4 {
            font-family: 'Playfair Display', serif;
            color: var(--deep-emerald);
        }
        
        .brand-font {
            font-family: 'Jost', sans-serif;
            font-weight: 600;
        }

        /* Navigation */
        .navbar {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(15px);
            box-shadow: 0 2px 30px rgba(0,0,0,0.1);
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .navbar-brand {
            color: var(--deep-emerald) !important;
            font-family: 'Playfair Display', serif;
            font-weight: 700;
            font-size: 1.8rem;
        }
        
        .nav-link {
            color: var(--dusty-teal) !important;
            font-weight: 500;
            margin: 0 12px;
            transition: all 0.3s ease;
            position: relative;
        }
        
        .nav-link::after {
            content: '';
            position: absolute;
            bottom: -5px;
            left: 0;
            width: 0;
            height: 2px;
            background: var(--sage-green);
            transition: width 0.3s ease;
        }
        
        .nav-link:hover::after {
            width: 100%;
        }
        
        .nav-link:hover {
            color: var(--sage-green) !important;
            transform: translateY(-2px);
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--sage-green), var(--dusty-teal));
            border: none;
            padding: 12px 30px;
            border-radius: 50px;
            font-weight: 600;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }
        
        .btn-primary::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, var(--dusty-teal), var(--sage-green));
            transition: left 0.4s ease;
        }
        
        .btn-primary:hover::before {
            left: 0;
        }
        
        .btn-primary span {
            position: relative;
            z-index: 2;
        }
        
        .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(141, 182, 154, 0.4);
        }

        /* Feature Cards */
        .feature-card {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 40px 30px;
            margin: 20px 0;
            box-shadow: 0 15px 35px rgba(0,0,0,0.1);
            border: 1px solid rgba(255,255,255,0.2);
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }
        
        .feature-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(141, 182, 154, 0.1), transparent);
            transition: left 0.6s ease;
        }
        
        .feature-card:hover::before {
            left: 100%;
        }
        
        .feature-card:hover {
            transform: translateY(-15px) scale(1.02);
            box-shadow: 0 25px 50px rgba(0,0,0,0.15);
        }
        
        .feature-icon {
            font-size: 3.5rem;
            color: var(--sage-green);
            margin-bottom: 25px;
            transition: all 0.4s ease;
        }
        
        .feature-card:hover .feature-icon {
            transform: scale(1.3) rotate(10deg);
            color: var(--dusty-teal);
        }

        /* Section Backgrounds */
        .section-bg {
            background: linear-gradient(135deg, var(--mint-cream) 0%, #ffffff 100%);
            position: relative;
        }
        
        .section-bg-alt {
            background: linear-gradient(135deg, #ffffff 0%, var(--forest-mist) 100%);
        }

        /* About Page Specific Styles */
        .page-hero {
            background: linear-gradient(135deg, var(--sage-green), var(--deep-emerald));
            color: white;
            padding: 120px 0 80px 0;
            text-align: center;
            margin-top: 0;
        }
        
        .timeline {
            position: relative;
            max-width: 1200px;
            margin: 0 auto;
            padding: 40px 0;
        }
        
        .timeline::after {
            content: '';
            position: absolute;
            width: 6px;
            background-color: var(--forest-mist);
            top: 0;
            bottom: 0;
            left: 50%;
            margin-left: -3px;
        }
        
        .timeline-item {
            padding: 10px 40px;
            position: relative;
            width: 50%;
            box-sizing: border-box;
        }
        
        .timeline-item::after {
            content: '';
            position: absolute;
            width: 20px;
            height: 20px;
            background-color: var(--sage-green);
            border: 4px solid white;
            border-radius: 50%;
            top: 20px;
            z-index: 1;
        }
        
        .left {
            left: 0;
        }
        
        .right {
            left: 50%;
        }
        
        .left::after {
            right: -10px;
        }
        
        .right::after {
            left: -10px;
        }
        
        .timeline-content {
            padding: 20px 30px;
            background-color: white;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .values-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 30px;
            margin-top: 50px;
        }
        
        .value-card {
            background: white;
            padding: 40px 30px;
            border-radius: 15px;
            text-align: center;
            box-shadow: 0 10px 30px rgba(0,0,0,0.08);
            transition: all 0.3s ease;
            border-top: 4px solid var(--sage-green);
        }
        
        .value-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 20px 40px rgba(0,0,0,0.15);
        }
        
        .value-icon {
            font-size: 3rem;
            color: var(--sage-green);
            margin-bottom: 20px;
        }
        
        .stats-section {
            background: linear-gradient(135deg, var(--mint-cream) 0%, var(--forest-mist) 100%);
        }
        
        .stat-card {
            text-align: center;
            padding: 30px 20px;
        }
        
        .stat-number {
            font-size: 3rem;
            font-weight: bold;
            color: var(--deep-emerald);
            margin-bottom: 10px;
        }
        
        .stat-label {
            color: var(--dusty-teal);
            font-weight: 600;
        }
        
        .team-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 30px;
            margin-top: 50px;
        }
        
        .team-member-card {
            background: white;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0,0,0,0.08);
            transition: all 0.3s ease;
        }
        
        .team-member-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 20px 40px rgba(0,0,0,0.15);
        }
        
        .member-image {
            width: 100%;
            height: 250px;
            object-fit: cover;
        }
        
        .member-info {
            padding: 25px;
        }

        /* Typography Enhancements */
        .display-3 {
            font-size: 3.5rem;
            font-weight: 700;
            margin-bottom: 1rem;
        }
        
        .display-5 {
            font-size: 2.5rem;
            font-weight: 700;
        }
        
        .lead {
            font-size: 1.3rem;
            font-weight: 300;
            line-height: 1.6;
        }
        
        .section-title {
            color: var(--deep-emerald);
            font-weight: bold;
            margin-bottom: 3rem;
            position: relative;
        }
        
        .section-title::after {
            content: '';
            position: absolute;
            bottom: -10px;
            left: 50%;
            transform: translateX(-50%);
            width: 60px;
            height: 3px;
            background: var(--sage-green);
        }

        /* Footer */
        .footer {
            background: linear-gradient(135deg, var(--deep-emerald), #2C5530);
            color: white;
            position: relative;
            overflow: hidden;
        }
        
        .footer::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1000 100" fill="%23ffffff" opacity="0.05"><polygon points="0,0 1000,50 1000,100 0,100"/></svg>');
            background-size: cover;
        }
        
        .social-icon {
            font-size: 1.8rem;
            color: white;
            margin: 0 15px;
            transition: all 0.3s ease;
            display: inline-block;
        }
        
        .social-icon:hover {
            color: var(--seafoam);
            transform: translateY(-5px) scale(1.2);
        }
        
        .footer-links {
            margin-top: 20px;
        }
        
        .footer-links a {
            color: white;
            text-decoration: none;
            margin: 0 12px;
            transition: all 0.3s ease;
            font-weight: 500;
        }
        
        .footer-links a:hover {
            color: var(--seafoam);
            transform: translateY(-2px);
        }
        
        .footer-contact a {
            color: white;
            text-decoration: none;
            transition: color 0.3s ease;
        }
        
        .footer-contact a:hover {
            color: var(--seafoam);
        }

        /* Footer Brand Text Fix */
        .footer-brand {
            color: white !important;
            font-family: 'Playfair Display', serif;
            font-weight: 700;
            font-size: 1.5rem;
        }

        /* Responsive adjustments */
        @media (max-width: 768px) {
            .display-3 {
                font-size: 2.5rem;
            }
            
            .display-5 {
                font-size: 2rem;
            }
            
            .timeline::after {
                left: 31px;
            }
            
            .timeline-item {
                width: 100%;
                padding-left: 70px;
                padding-right: 25px;
            }
            
            .timeline-item::after {
                left: 21px;
            }
            
            .right {
                left: 0;
            }
            
            .footer-links a {
                display: block;
                margin: 8px 0;
            }
            
            .social-icon {
                margin: 0 10px;
            }
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-light fixed-top">
        <div class="container">
            <a class="navbar-brand" href="index.html">
                <i class="fas fa-leaf me-2"></i>
                SUBODHA AYURVEDA
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item"><a class="nav-link" href="index.php">Home</a></li>
                    <li class="nav-item"><a class="nav-link active" href="about.php">About Us</a></li>
                    <li class="nav-item"><a class="nav-link" href="services.php">Our Services</a></li>
                    <li class="nav-item"><a class="nav-link" href="team.php">Our Team</a></li>
                    <li class="nav-item"><a class="nav-link" href="contact.php">Contact Us</a></li>
                    <li class="nav-item">
                        <a class="btn btn-primary ms-3" href="login.php">
                            <i class="fas fa-sign-in-alt me-2"></i><span>SmartCare Login</span>
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Page Hero Section -->
    <section class="page-hero">
        <div class="container">
            <h1 class="display-3 fw-bold mb-4">About Subodha Ayurveda</h1>
            <p class="lead mb-4">
                Two Decades of Compassionate Healing and Elder Care Excellence
            </p>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb justify-content-center">
                    <li class="breadcrumb-item"><a href="index.php" class="text-white">Home</a></li>
                    <li class="breadcrumb-item active text-white" aria-current="page">About Us</li>
                </ol>
            </nav>
        </div>
    </section>

    <!-- Our Story Section -->
    <section class="py-5 section-bg">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-lg-6">
                    <h2 class="display-5 fw-bold mb-4">Our Humble Beginnings</h2>
                    <p class="lead mb-4">
                        Founded in 2003, Subodha Ayurveda Hospital began as a small clinic with a big vision: 
                        to bring authentic Ayurvedic healing to the community of Rathmalana while embracing 
                        modern medical practices for comprehensive healthcare.
                    </p>
                    <p class="mb-4">
                        What started as a single-treatment room has now blossomed into a full-fledged healthcare 
                        facility specializing in both traditional Ayurvedic treatments and modern elderly care. 
                        Our Elder Care Unit, established in 2015, represents our commitment to providing 
                        compassionate, round-the-clock care for seniors.
                    </p>
                </div>
                <div class="col-lg-6">
                    <div class="feature-card">
                        <div class="text-center p-5">
                            <img src="assets/images/building.jpg" 
                                 alt="Hospital bulding" class="img-fluid rounded-3">
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Timeline Section -->
    <section id="timeline" class="py-5 section-bg-alt">
        <div class="container">
            <h2 class="section-title text-center display-4 mb-5">Our Journey Through Time</h2>
            <div class="timeline">
                <div class="timeline-item left">
                    <div class="timeline-content">
                        <h4 class="brand-font">2003</h4>
                        <h5>Foundation Established</h5>
                        <p>Subodha Ayurveda Hospital opens its doors, offering traditional Ayurvedic treatments to the local community.</p>
                    </div>
                </div>
                <div class="timeline-item right">
                    <div class="timeline-content">
                        <h4 class="brand-font">2008</h4>
                        <h5>Expansion Phase</h5>
                        <p>Added new treatment rooms and expanded our range of Ayurvedic therapies and wellness programs.</p>
                    </div>
                </div>
                <div class="timeline-item left">
                    <div class="timeline-content">
                        <h4 class="brand-font">2015</h4>
                        <h5>Elder Care Unit Launch</h5>
                        <p>Established our specialized Elder Care Unit to address the growing need for senior healthcare services.</p>
                    </div>
                </div>
                <div class="timeline-item right">
                    <div class="timeline-content">
                        <h4 class="brand-font">2020</h4>
                        <h5>Technology Integration</h5>
                        <p>Implemented SmartCare Guardian system for advanced health monitoring and personalized elderly care.</p>
                    </div>
                </div>
                <div class="timeline-item left">
                    <div class="timeline-content">
                        <h4 class="brand-font">2024</h4>
                        <h5>Present Day</h5>
                        <p>Continuing our mission with expanded facilities, advanced technology, and unwavering commitment to holistic care.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Stats Section -->
    <section class="py-5 stats-section">
        <div class="container">
            <div class="row text-center">
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="stat-number">20+</div>
                        <div class="stat-label">Years of Service</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="stat-number">5000+</div>
                        <div class="stat-label">Patients Treated</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="stat-number">50+</div>
                        <div class="stat-label">Elderly Residents</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="stat-number">15+</div>
                        <div class="stat-label">Expert Staff</div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Values Section -->
    <section id="values" class="py-5 section-bg">
        <div class="container">
            <h2 class="section-title text-center display-4 mb-5">Our Core Values</h2>
            <div class="values-grid">
                <div class="value-card">
                    <div class="value-icon">
                        <i class="fas fa-heart"></i>
                    </div>
                    <h4 class="brand-font">Compassionate Care</h4>
                    <p>We treat every patient and resident with the same care and respect we would offer our own family members.</p>
                </div>
                <div class="value-card">
                    <div class="value-icon">
                        <i class="fas fa-shield-alt"></i>
                    </div>
                    <h4 class="brand-font">Holistic Healing</h4>
                    <p>Integrating ancient Ayurvedic wisdom with modern medicine for complete mind-body-spirit wellness.</p>
                </div>
                <div class="value-card">
                    <div class="value-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <h4 class="brand-font">Family Environment</h4>
                    <p>Creating a warm, family-like atmosphere where every resident feels at home and cared for.</p>
                </div>
                <div class="value-card">
                    <div class="value-icon">
                        <i class="fas fa-star-of-life"></i>
                    </div>
                    <h4 class="brand-font">Excellence in Service</h4>
                    <p>Maintaining the highest standards of healthcare delivery through continuous improvement and innovation.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Mission & Vision Section -->
    <section class="py-5 section-bg-alt">
        <div class="container">
            <div class="row">
                <div class="col-lg-6">
                    <div class="feature-card h-100">
                        <div class="feature-icon">
                            <i class="fas fa-bullseye"></i>
                        </div>
                        <h3 class="brand-font">Our Mission</h3>
                        <p class="lead">
                            To provide exceptional Ayurvedic healthcare and compassionate elderly care that 
                            nurtures physical, mental, and spiritual well-being through integrated traditional 
                            and modern approaches.
                        </p>
                        <ul class="list-unstyled mt-4">
                            <li class="mb-2"><i class="fas fa-check text-success me-2"></i>Personalized treatment plans</li>
                            <li class="mb-2"><i class="fas fa-check text-success me-2"></i>24/7 elderly care services</li>
                            <li class="mb-2"><i class="fas fa-check text-success me-2"></i>Traditional Ayurvedic therapies</li>
                            <li class="mb-2"><i class="fas fa-check text-success me-2"></i>Modern health monitoring technology</li>
                        </ul>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="feature-card h-100">
                        <div class="feature-icon">
                            <i class="fas fa-eye"></i>
                        </div>
                        <h3 class="brand-font">Our Vision</h3>
                        <p class="lead">
                            To be the leading center for integrated Ayurvedic and elderly care in Sri Lanka, 
                            setting new standards in holistic healthcare while preserving ancient healing traditions.
                        </p>
                        <ul class="list-unstyled mt-4">
                            <li class="mb-2"><i class="fas fa-star text-warning me-2"></i>Expand healthcare access</li>
                            <li class="mb-2"><i class="fas fa-star text-warning me-2"></i>Innovate elderly care solutions</li>
                            <li class="mb-2"><i class="fas fa-star text-warning me-2"></i>Preserve Ayurvedic heritage</li>
                            <li class="mb-2"><i class="fas fa-star text-warning me-2"></i>Build community partnerships</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Team Preview Section -->
    <section class="py-5 section-bg">
        <div class="container">
            <h2 class="section-title text-center display-4 mb-5">Meet Our Leadership</h2>
            <div class="team-grid">
                <div class="team-member-card">
                    <div class="member-image bg-light d-flex align-items-center justify-content-center">
                        <i class="fas fa-user-md fa-5x text-muted"></i>
                    </div>
                    <div class="member-info">
                        <h5 class="brand-font">Mr. Sunil Perera</h5>
                        <p class="text-muted">Founder & Director</p>
                        <p>Visionary leader with 20+ years of experience in healthcare management and Ayurvedic practice.</p>
                    </div>
                </div>
                <div class="team-member-card">
                    <div class="member-image bg-light d-flex align-items-center justify-content-center">
                        <i class="fas fa-user-md fa-5x text-muted"></i>
                    </div>
                    <div class="member-info">
                        <h5 class="brand-font">Dr. Ananda Perera</h5>
                        <p class="text-muted">Chief Ayurvedic Physician</p>
                        <p>Specialized in geriatric Ayurvedic care with extensive knowledge in traditional healing therapies.</p>
                    </div>
                </div>
                <div class="team-member-card">
                    <div class="member-image bg-light d-flex align-items-center justify-content-center">
                        <i class="fas fa-user-nurse fa-5x text-muted"></i>
                    </div>
                    <div class="member-info">
                        <h5 class="brand-font">Mrs. Nirmala Fernando</h5>
                        <p class="text-muted">Head of Elder Care Unit</p>
                        <p>Dedicated professional with 15 years of experience in elderly care and nursing management.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="footer py-5">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-lg-4 text-center text-lg-start mb-4 mb-lg-0">
                    <h4 class="footer-brand mb-3">
                        <i class="fas fa-leaf me-2"></i>SUBODHA AYURVEDA
                    </h4>
                    <p class="mb-0">Ancient Wisdom, Modern Care, Eternal Compassion</p>
                </div>
                <div class="col-lg-4 text-center mb-4 mb-lg-0" data-aos="fade-up">
                    <div class="mb-3">
                        <a href="https://www.facebook.com/share/1Bs1QLpBi2/" class="social-icon" target="_blank">
                            <i class="fab fa-facebook"></i>
                        </a>
                        <a href="https://wa.me/94726506485" class="social-icon" target="_blank">
                            <i class="fab fa-whatsapp"></i>
                        </a>
                    </div>
                    <div class="footer-links">
                        <a href="index.php">Home</a>
                        <a href="about.php">About</a>
                        <a href="services.php">Services</a>
                        <a href="contact.php">Contact</a>
                    </div>
                </div>
                <div class="col-lg-4 text-center text-lg-end" data-aos="fade-left">
                    <div class="footer-contact">
                        <p class="mb-2">
                            <i class="fas fa-phone me-2"></i>
                            <a href="tel:+94726506485">
                                +94 72 650 6485
                            </a>
                        </p>
                        <p class="mb-2">
                            <i class="fas fa-envelope me-2"></i>
                            <a href="mailto:subodhaayurvedahospitalpvtltd@gmail.com">
                                subodhaayurvedahospitalpvtltd@gmail.com
                            </a>
                        </p>
                        <p class="mb-0">
                            <i class="fas fa-map-marker-alt me-2"></i>
                            <a href="https://maps.google.com/?q=7/13+Thelawala+Road,+Mayura+Road,+Rathmalana" target="_blank">
                                Rathmalana, Sri Lanka
                            </a>
                        </p>
                    </div>
                </div>
            </div>
            <hr class="my-4" style="background: rgba(255,255,255,0.3);">
            <div class="text-center">
                <p class="mb-0">&copy; 2026 Subodha Ayurveda Hospital (Pvt) Ltd. All rights reserved.</p>
            </div>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/aos/2.3.4/aos.js"></script>
    <script>
        // Initialize AOS
        AOS.init({
            duration: 1200,
            once: true,
            offset: 100
        });

        // Smooth scrolling
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({
                        behavior: 'smooth'
                    });
                }
            });
        });

        // Navbar scroll effect
        window.addEventListener('scroll', function() {
            const navbar = document.querySelector('.navbar');
            if (window.scrollY > 100) {
                navbar.style.background = 'rgba(255, 255, 255, 0.98)';
                navbar.style.boxShadow = '0 5px 30px rgba(0,0,0,0.15)';
            } else {
                navbar.style.background = 'rgba(255, 255, 255, 0.95)';
                navbar.style.boxShadow = '0 2px 30px rgba(0,0,0,0.1)';
            }
        });
    </script>
</body>
</html>