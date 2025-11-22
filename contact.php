<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contact Us - Subodha Ayurveda Hospital</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/aos/2.3.4/aos.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500;600;700&family=Quicksand:wght@300;400;500;600&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <style>
        /* Include ALL CSS directly in the file */
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

        /* Contact Page Specific Styles */
        .page-hero {
            background: linear-gradient(135deg, var(--sage-green), var(--deep-emerald));
            color: white;
            padding: 120px 0 80px 0;
            text-align: center;
            margin-top: 0;
        }
        
        .contact-section {
            padding: 80px 0;
        }
        
        .contact-info-card {
            background: linear-gradient(135deg, var(--mint-cream), #ffffff);
            border-radius: 20px;
            padding: 40px 30px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.08);
            transition: all 0.3s ease;
            border-left: 4px solid var(--sage-green);
            height: 100%;
            text-align: center;
        }
        
        .contact-info-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 35px rgba(0,0,0,0.15);
        }
        
        .contact-icon {
            font-size: 2.5rem;
            color: var(--sage-green);
            margin-bottom: 20px;
        }
        
        .contact-form-card {
            background: white;
            border-radius: 20px;
            padding: 40px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.1);
            margin: 0 auto;
            max-width: 800px;
        }
        
        .form-control {
            border: 2px solid var(--forest-mist);
            border-radius: 10px;
            padding: 12px 15px;
            transition: all 0.3s ease;
            margin-bottom: 20px;
        }
        
        .form-control:focus {
            border-color: var(--sage-green);
            box-shadow: 0 0 0 0.2rem rgba(141, 182, 154, 0.25);
        }
        
        .form-label {
            font-weight: 600;
            color: var(--deep-emerald);
            margin-bottom: 8px;
        }
        
        /* Social Media Icons in Contact Cards */
        .contact-social-icons {
            margin-top: 15px;
        }
        
        .contact-social-icon {
            font-size: 1.5rem;
            color: var(--sage-green);
            margin: 0 10px;
            transition: all 0.3s ease;
            display: inline-block;
        }
        
        .contact-social-icon:hover {
            color: var(--dusty-teal);
            transform: translateY(-3px) scale(1.2);
        }
        
        /* Map Section */
        .map-section {
            background: linear-gradient(135deg, var(--mint-cream) 0%, var(--forest-mist) 100%);
        }
        
        .map-container {
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            height: 400px;
        }
        
        /* Hours Section */
        .hours-card {
            background: white;
            border-radius: 20px;
            padding: 40px 30px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.08);
        }
        
        .hours-list {
            list-style: none;
            padding: 0;
        }
        
        .hours-list li {
            padding: 12px 0;
            border-bottom: 1px solid var(--forest-mist);
            display: flex;
            justify-content: space-between;
        }
        
        .hours-list li:last-child {
            border-bottom: none;
        }
        
        .day {
            font-weight: 600;
            color: var(--deep-emerald);
        }
        
        .time {
            color: var(--dusty-teal);
        }
        
        .emergency-badge {
            background: linear-gradient(135deg, #ff6b6b, #ee5a52);
            color: white;
            padding: 8px 16px;
            border-radius: 25px;
            font-weight: 600;
            font-size: 0.9rem;
        }
        
        /* Quick Contact */
        .quick-contact-item {
            display: flex;
            align-items: center;
            padding: 15px 0;
            border-bottom: 1px solid var(--forest-mist);
        }
        
        .quick-contact-item:last-child {
            border-bottom: none;
        }
        
        .quick-contact-icon {
            width: 50px;
            height: 50px;
            background: var(--sage-green);
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
            font-size: 1.2rem;
        }
        
        /* Section Backgrounds */
        .section-bg {
            background: linear-gradient(135deg, var(--mint-cream) 0%, #ffffff 100%);
            position: relative;
        }
        
        .section-bg-alt {
            background: linear-gradient(135deg, #ffffff 0%, var(--forest-mist) 100%);
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
            
            .contact-form-card {
                padding: 30px 20px;
                margin: 0 15px;
            }
            
            .map-container {
                height: 300px;
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
            <a class="navbar-brand" href="index.php">
                <i class="fas fa-leaf me-2"></i>
                SUBODHA AYURVEDA
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item"><a class="nav-link" href="index.php">Home</a></li>
                    <li class="nav-item"><a class="nav-link" href="about.php">About Us</a></li>
                    <li class="nav-item"><a class="nav-link" href="services.php">Our Services</a></li>
                    <li class="nav-item"><a class="nav-link" href="team.php">Our Team</a></li>
                    <li class="nav-item"><a class="nav-link active" href="contact.php">Contact Us</a></li>
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
            <h1 class="display-3 fw-bold mb-4">Get In Touch</h1>
            <p class="lead mb-4">
                We're Here to Provide Compassionate Care and Support
            </p>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb justify-content-center">
                    <li class="breadcrumb-item"><a href="index.php" class="text-white">Home</a></li>
                    <li class="breadcrumb-item active text-white" aria-current="page">Contact Us</li>
                </ol>
            </nav>
        </div>
    </section>

    <!-- Contact Information Section -->
    <section class="contact-section section-bg">
        <div class="container">
            <div class="row">
                <div class="col-lg-4 mb-4">
                    <div class="contact-info-card">
                        <div class="contact-icon">
                            <i class="fas fa-map-marker-alt"></i>
                        </div>
                        <h4 class="brand-font mb-3">Our Location</h4>
                        <p class="mb-3">
                            <strong>Subodha Ayurveda Hospital</strong><br>
                            7/13, Thelawala Road<br>
                            Mayura Road, Rathmalana<br>
                            Sri Lanka
                        </p>
                        <a href="https://maps.google.com/?q=7/13+Thelawala+Road,+Mayura+Road,+Rathmalana" 
                           target="_blank" class="btn btn-primary">
                            <i class="fas fa-directions me-2"></i>Get Directions
                        </a>
                    </div>
                </div>
                <div class="col-lg-4 mb-4">
                    <div class="contact-info-card">
                        <div class="contact-icon">
                            <i class="fas fa-phone"></i>
                        </div>
                        <h4 class="brand-font mb-3">Phone & WhatsApp</h4>
                        <p class="mb-3">
                            <strong>Main Line:</strong><br>
                            <a href="tel:+94726506485" class="text-decoration-none">+94 72 650 6485</a>
                        </p>
                        <p class="mb-3">
                            <strong>Emergency:</strong><br>
                            <a href="tel:+94726506485" class="text-decoration-none">+94 72 650 6485</a>
                        </p>
                        <a href="https://wa.me/94726506485" target="_blank" class="btn btn-primary">
                            <i class="fab fa-whatsapp me-2"></i>WhatsApp Us
                        </a>
                    </div>
                </div>
                <div class="col-lg-4 mb-4">
                    <div class="contact-info-card">
                        <div class="contact-icon">
                            <i class="fas fa-envelope"></i>
                        </div>
                        <h4 class="brand-font mb-3">Email & Social</h4>
                        <p class="mb-3">
                            <strong>Email:</strong><br>
                            <a href="mailto:subodhaayurvedahospitalpvtltd@gmail.com" class="text-decoration-none">
                                subodhaayurvedahospitalpvtltd@gmail.com
                            </a>
                        </p>
                        <p class="mb-3">
                            <strong>Follow Us:</strong>
                        </p>
                        <div class="contact-social-icons">
                            <a href="https://www.facebook.com/share/1Bs1QLpBi2/" class="contact-social-icon" target="_blank">
                                <i class="fab fa-facebook"></i>
                            </a>
                            <a href="https://wa.me/94726506485" class="contact-social-icon" target="_blank">
                                <i class="fab fa-whatsapp"></i>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Contact Form - Centered -->
    <section class="py-5 section-bg-alt">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-lg-10">
                    <div class="contact-form-card">
                        <h3 class="section-title text-center display-5 mb-4">Send Us a Message</h3>
                        <p class="text-center mb-4">We'll get back to you within 24 hours</p>
                        
                        <form id="contactForm" action="send_message.php" method="POST">
                            <div class="row">
                                <div class="col-md-6">
                                    <label for="fullname" class="form-label">Full Name *</label>
                                    <input type="text" class="form-control" name="fullname" id="fullname" required>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <label for="email" class="form-label">Email Address *</label>
                                    <input type="email" class="form-control" name="email" id="email" required>
                                </div>
                                <div class="col-md-6">
                                    <label for="phone" class="form-label">Phone Number *</label>
                                    <input type="tel" class="form-control" name="phone" id="phone" required>
                                </div>
                            </div>  
                            
                            <div class="row">
                                <div class="col-12">
                                    <label for="message" class="form-label">Message *</label>
                                    <textarea class="form-control" name="message" id="message" rows="5" placeholder="Tell us about your requirements..." required></textarea>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-12">
                                    <button type="submit" class="btn btn-primary btn-lg w-100">
                                        <i class="fas fa-paper-plane me-2"></i><span>Send Message</span>
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- FAQ Section -->
    <section class="py-5 section-bg-alt">
        <div class="container">
            <h2 class="section-title text-center display-4 mb-5">Frequently Asked Questions</h2>
            <div class="row">
                <div class="col-lg-6">
                    <div class="accordion" id="faqAccordion">
                        <div class="accordion-item">
                            <h3 class="accordion-header">
                                <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#faq1">
                                    What are your visiting hours?
                                </button>
                            </h3>
                            <div id="faq1" class="accordion-collapse collapse show" data-bs-parent="#faqAccordion">
                                <div class="accordion-body">
                                    Our general visiting hours are 8:00 AM to 8:00 PM on weekdays, and 9:00 AM to 4:00 PM on weekends. 
                                    However, for emergency situations, we're available 24/7.
                                </div>
                            </div>
                        </div>
                        <div class="accordion-item">
                            <h3 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq2">
                                    Do I need an appointment for consultation?
                                </button>
                            </h3>
                            <div id="faq2" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                                <div class="accordion-body">
                                    While appointments are recommended for non-emergency consultations, we accept walk-in patients. 
                                    For specialized treatments, we recommend booking an appointment in advance.
                                </div>
                            </div>
                        </div>
                        <div class="accordion-item">
                            <h3 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq3">
                                    What payment methods do you accept?
                                </button>
                            </h3>
                            <div id="faq3" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                                <div class="accordion-body">
                                    We accept cash, credit/debit cards, and bank transfers. For long-term residential care, 
                                    we offer flexible payment plans. Please contact us for detailed payment information.
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="accordion" id="faqAccordion2">
                        <div class="accordion-item">
                            <h3 class="accordion-header">
                                <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#faq4">
                                    Do you provide ambulance services?
                                </button>
                            </h3>
                            <div id="faq4" class="accordion-collapse collapse show" data-bs-parent="#faqAccordion2">
                                <div class="accordion-body">
                                    Yes, we provide ambulance services for emergencies within the Rathmalana and surrounding areas. 
                                    For ambulance service, please call our emergency number immediately.
                                </div>
                            </div>
                        </div>
                        <div class="accordion-item">
                            <h3 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq5">
                                    Can I visit for a facility tour?
                                </button>
                            </h3>
                            <div id="faq5" class="accordion-collapse collapse" data-bs-parent="#faqAccordion2">
                                <div class="accordion-body">
                                    Absolutely! We encourage prospective residents and their families to tour our facilities. 
                                    Please call ahead to schedule a tour so we can ensure someone is available to guide you.
                                </div>
                            </div>
                        </div>
                        <div class="accordion-item">
                            <h3 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq6">
                                    What should I bring for residential care?
                                </button>
                            </h3>
                            <div id="faq6" class="accordion-collapse collapse" data-bs-parent="#faqAccordion2">
                                <div class="accordion-body">
                                    For residential care, please bring personal identification, medical records, current medications, 
                                    comfortable clothing, personal hygiene items, and any special dietary requirements information.
                                </div>
                            </div>
                        </div>
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
                <p class="mb-0">&copy; 2024 Subodha Ayurveda Hospital (Pvt) Ltd. All rights reserved.</p>
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