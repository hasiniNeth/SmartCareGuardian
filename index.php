<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Subodha Ayurveda Hospital - SmartCare Guardian</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/aos/2.3.4/aos.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500;600;700&family=Quicksand:wght@300;400;500;600&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-light fixed-top">
        <div class="container">
            <a class="navbar-brand" href="#">
                <i class="fas fa-leaf me-2"></i>
                SUBODHA AYURVEDA
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item"><a class="nav-link" href="#home">Home</a></li>
                    <li class="nav-item"><a class="nav-link" href="#about">About Us</a></li>
                    <li class="nav-item"><a class="nav-link" href="#services">Our Services</a></li>
                    <li class="nav-item"><a class="nav-link" href="#team">Our Team</a></li>
                    <li class="nav-item"><a class="nav-link" href="#contact">Contact Us</a></li>
                    <li class="nav-item">
                        <a class="btn btn-primary ms-3" href="login.php">
                            <i class="fas fa-sign-in-alt me-2"></i><span>SmartCare Login</span>
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Video Hero Section -->
    <section id="home" class="video-hero">
        <div class="hero-overlay"></div>
        <div class="floating-shapes">
            <div class="shape shape-1">🌿</div>
            <div class="shape shape-2">💚</div>
            <div class="shape shape-3">🌱</div>
            <div class="shape shape-4">🍃</div>
        </div>
        <!-- Replace with your actual video -->
        <video class="video-background" autoplay muted loop playsinline>
            <source src="assets/videos/background2.mp4" type="video/mp4">
        </video>
        <div class="hero-content">
            <div class="container">
                <div class="row align-items-center">
                    <div class="col-lg-8" data-aos="fade-right">
                        <h1 class="display-3 fw-bold mb-4">
                            Subodha Ayurveda Hospital & Elder Care Unit
                        </h1>
                        <p class="lead mb-4">
                            Where Ancient Wisdom Meets Modern Compassionate Care
                        </p>
                        <p class="mb-5 fs-5">
                            Experience holistic healing through time-tested Ayurvedic traditions 
                            enhanced with cutting-edge healthcare technology.
                        </p>
                    </div>
                    <div class="col-lg-4 text-center" data-aos="fade-left" data-aos-delay="200">
                        <div class="feature-card pulse-glow">
                            <div class="feature-icon">
                                <i class="fas fa-shield-heart"></i>
                            </div>
                            <h4 class="brand-font">SmartCare Guardian</h4>
                            <p class="mb-4">
                                AI-powered elderly care with predictive health monitoring and personalized wellness plans.
                            </p>
                            <a href="login.php" class="btn btn-primary w-100">
                                <i class="fas fa-sign-in-alt me-2"></i><span>Access System</span>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- About Us Section -->
    <section id="about" class="py-5 section-bg">
        <div class="container">
            <h2 class="section-title text-center display-4 mb-5" data-aos="fade-up">About Our Legacy</h2>
            <div class="row">
                <div class="col-lg-6" data-aos="fade-right">
                    <div class="feature-card h-100">
                        <div class="feature-icon">
                            <i class="fas fa-history"></i>
                        </div>
                        <h4 class="brand-font">Our Heritage & Vision</h4>
                        <p>
                            Subodha Ayurveda Hospital has been a pillar of holistic healthcare in Rathmalana, 
                            blending ancient Ayurvedic principles with modern medical practices. Our Elder Care Unit 
                            represents our commitment to providing compassionate, comprehensive care for seniors.
                        </p>
                        <div class="mt-4">
                            <img src="assets/images/EldersHome.png" 
                                 alt="Ayurvedic herbs" class="img-fluid rounded-3">
                        </div>
                    </div>
                </div>
                <div class="col-lg-6" data-aos="fade-left">
                    <div class="feature-card h-100">
                        <div class="feature-icon">
                            <i class="fas fa-bullseye"></i>
                        </div>
                        <h4 class="brand-font">Our Healing Mission</h4>
                        <p>
                            To provide personalized Ayurvedic treatments and elderly care that nurture physical, 
                            mental, and spiritual well-being. We integrate traditional healing methods with 
                            modern technology like SmartCare Guardian to ensure the highest quality of life 
                            for our residents.
                        </p>
                        <div class="mt-4">
                            <img src="assets/images/EldersHome2.jpg" 
                                 alt="Elderly care" class="img-fluid rounded-3">
                        </div>
                    </div>
                </div>
            </div>
            <div class="text-center mt-5" data-aos="zoom-in">
                <a href="about.php" class="btn btn-primary btn-lg">
                    <i class="fas fa-plus me-2"></i><span>Explore Our Story</span>
                </a>
            </div>
        </div>
    </section>

    <!-- Services Section -->
    <section id="services" class="py-5 section-bg-alt">
        <div class="container">
            <h2 class="section-title text-center display-4 mb-5" data-aos="fade-up">Our Specialized Services</h2>
            <div class="services-grid">
                <div class="service-item" data-aos="flip-left">
                    <img src="https://images.unsplash.com/photo-1544367567-0f2fcb009e0b?ixlib=rb-4.0.3&auto=format&fit=crop&w=300&q=80" 
                         alt="Ayurvedic Treatment" class="service-image">
                    <h5 class="brand-font">Traditional Ayurvedic Treatments</h5>
                    <p>Panchakarma therapies, herbal medicine, and personalized wellness programs based on ancient Ayurvedic principles for holistic healing.</p>
                </div>
                <div class="service-item" data-aos="flip-up">
                    <img src="https://images.unsplash.com/photo-1519494080410-f9aa76cb4283?ixlib=rb-4.0.3&auto=format&fit=crop&w=300&q=80" 
                         alt="Elder Care" class="service-image">
                    <h5 class="brand-font">Comprehensive Elder Care</h5>
                    <p>24/7 residential care with medical monitoring, personalized attention, and SmartCare Guardian technology for proactive health management.</p>
                </div>
                <div class="service-item" data-aos="flip-right">
                    <img src="https://images.unsplash.com/photo-1506126613408-eca07ce68773?ixlib=rb-4.0.3&auto=format&fit=crop&w=300&q=80" 
                         alt="Wellness Program" class="service-image">
                    <h5 class="brand-font">Senior Wellness Programs</h5>
                    <p>Yoga, meditation, nutritional guidance, and therapeutic activities specifically designed for elderly residents' needs and capabilities.</p>
                </div>
            </div>
            <div class="text-center mt-5" data-aos="zoom-in">
                <a href="services.php" class="btn btn-primary btn-lg">
                    <i class="fas fa-plus me-2"></i><span>Discover All Services</span>
                </a>
            </div>
        </div>
    </section>

    <!-- Team Section -->
    <section id="team" class="py-5 section-bg">
        <div class="container">
            <h2 class="section-title text-center display-4 mb-5" data-aos="fade-up">Our Compassionate Team</h2>
            <div class="row">
                <div class="col-md-4" data-aos="fade-up" data-aos-delay="100">
                    <div class="team-member">
                        <img src="https://images.unsplash.com/photo-1612349317150-e413f6a5b16d?ixlib=rb-4.0.3&auto=format&fit=crop&w=300&q=80" 
                             alt="Dr. Ananda Perera" class="member-photo">
                        <h5 class="brand-font">Dr. Ananda Perera</h5>
                        <p class="text-muted">Chief Ayurvedic Physician</p>
                        <p>Over 25 years of experience in Ayurvedic medicine, specializing in geriatric care and traditional healing therapies.</p>
                    </div>
                </div>
                <div class="col-md-4" data-aos="fade-up" data-aos-delay="200">
                    <div class="team-member">
                        <img src="assets/images/caregivers.png" 
                             alt="Nursing Team" class="member-photo">
                        <h5 class="brand-font">Dedicated Nursing Team</h5>
                        <p class="text-muted">Certified Caregivers</p>
                        <p>Professional nursing staff trained in both modern elderly care techniques and traditional Ayurvedic support methods.</p>
                    </div>
                </div>
                <div class="col-md-4" data-aos="fade-up" data-aos-delay="300">
                    <div class="team-member">
                        <img src="assets/images/yoga.webp" 
                             alt="Therapy Team" class="member-photo">
                        <h5 class="brand-font">Therapy & Wellness Team</h5>
                        <p class="text-muted">Yoga & Rehabilitation Specialists</p>
                        <p>Experts in senior yoga, physiotherapy, and Ayurvedic massage therapies for comprehensive elderly wellness.</p>
                    </div>
                </div>
            </div>
            <div class="text-center mt-5" data-aos="zoom-in">
                <a href="team.php" class="btn btn-primary btn-lg">
                    <i class="fas fa-plus me-2"></i><span>Meet Our Family</span>
                </a>
            </div>
        </div>
    </section>

    <!-- Contact Section -->
    <section id="contact" class="py-5 section-bg-alt">
        <div class="container">
            <h2 class="section-title text-center display-4 mb-5" data-aos="fade-up">Connect With Us</h2>
            <div class="row justify-content-center">
                <div class="col-lg-10">
                    <div class="contact-form" data-aos="zoom-in">
                        <div class="row align-items-stretch">
                            <div class="col-md-4 mb-4 mb-md-0">
                                <div class="contact-info h-100 d-flex flex-column justify-content-center">
                                    <i class="fas fa-map-marker-alt contact-icon"></i>
                                    <h6 class="brand-font mb-3">Location</h6>
                                    <p class="flex-grow-1">
                                        <a href="https://maps.google.com/?q=7/13+Thelawala+Road,+Mayura+Road,+Rathmalana" target="_blank">
                                            7/13, Thelawala Road,<br>Mayura Road,<br>Rathmalana
                                        </a>
                                    </p>
                                </div>
                            </div>
                            <div class="col-md-4 mb-4 mb-md-0">
                                <div class="contact-info h-100 d-flex flex-column justify-content-center">
                                    <i class="fas fa-phone contact-icon"></i>
                                    <h6 class="brand-font mb-3">Phone</h6>
                                    <p class="flex-grow-1">
                                        <a href="tel:+94726506485">
                                            +94 72 650 6485
                                        </a>
                                    </p>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="contact-info h-100 d-flex flex-column justify-content-center">
                                    <i class="fas fa-envelope contact-icon"></i>
                                    <h6 class="brand-font mb-3">Email</h6>
                                    <p class="flex-grow-1">
                                        <a href="mailto:subodhaayurvedahospitalpvtltd@gmail.com">
                                            subodhaayurvedahospitalpvtltd@gmail.com
                                        </a>
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="text-center mt-5" data-aos="zoom-in">
                <a href="contact.php" class="btn btn-primary btn-lg">
                    <i class="fas fa-plus me-2"></i><span>Get In Touch</span>
                </a>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="footer py-5">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-lg-4 text-center text-lg-start mb-4 mb-lg-0" data-aos="fade-right">
                    <h4 class="brand-font mb-3">
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
                        <a href="#home">Home</a>
                        <a href="#about">About</a>
                        <a href="#services">Services</a>
                        <a href="#contact">Contact</a>
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
            <div class="text-center" data-aos="fade-up">
                <p class="mb-0">&copy; 2024 Subodha Ayurveda Hospital (Pvt) Ltd. All rights reserved.</p>
            </div>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/aos/2.3.4/aos.js"></script>
    <script src="assets/js/script.js"></script>
</body>
</html>