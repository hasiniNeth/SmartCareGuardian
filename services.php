<?php
session_start();
include 'db_connection.php';

// Fetch services from database
$services = $conn->query("
    SELECT s.*, c.category_name 
    FROM services s
    JOIN service_categories c ON s.category_id = c.category_id
    ORDER BY c.category_name, s.title
");

// Group services by category
$services_by_category = [];
if ($services && $services->num_rows > 0) {
    while($service = $services->fetch_assoc()) {
        $category = $service['category_name'];
        if (!isset($services_by_category[$category])) {
            $services_by_category[$category] = [];
        }
        $services_by_category[$category][] = $service;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Our Services - Subodha Ayurveda Hospital</title>
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

        /* Services Page Specific Styles */
        .page-hero {
            background: linear-gradient(135deg, var(--sage-green), var(--deep-emerald));
            color: white;
            padding: 120px 0 80px 0;
            text-align: center;
            margin-top: 0;
        }
        
        .service-category {
            margin-bottom: 80px;
        }
        
        .service-card {
            background: white;
            border-radius: 20px;
            padding: 10;
            text-align: center;
            box-shadow: 0 10px 30px rgba(0,0,0,0.08);
            transition: all 0.4s ease;
            position: relative;
            overflow: hidden;
            height: 100%;
            border-top: 4px solid var(--sage-green);
        }
        
        .service-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 20px 40px rgba(0,0,0,0.15);
        }
        
        .service-image {
            width: 100%;
            height: 250px;
            object-fit: cover;
            border-bottom: 3px solid var(--forest-mist);
        }
        
        .service-content {
            padding: 30px 25px;
        }
        
        .service-icon {
            font-size: 2.5rem;
            color: var(--sage-green);
            margin-bottom: 20px;
            transition: all 0.4s ease;
        }
        
        .service-card:hover .service-icon {
            transform: scale(1.2) rotate(5deg);
            color: var(--dusty-teal);
        }
        
        .service-features {
            list-style: none;
            padding: 10;
            margin: 20px 0;
            text-align: left;
        }
        
        .service-features li {
            padding: 8px 0;
            border-bottom: 1px solid var(--forest-mist);
            font-size: 0.9rem;
        }
        
        .service-features li:last-child {
            border-bottom: none;
        }
        
        .service-features li i {
            color: var(--sage-green);
            margin-right: 10px;
        }
        
        /* SmartCare Guardian Section */
        .smartcare-section {
            background: linear-gradient(135deg, var(--mint-cream) 0%, var(--forest-mist) 100%);
        }
        
        .feature-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 30px;
            margin-top: 50px;
        }
        
        .feature-item {
            background: white;
            padding: 30px 25px;
            border-radius: 15px;
            text-align: center;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            transition: all 0.3s ease;
        }
        
        .feature-item:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.15);
        }
        
        .feature-icon {
            font-size: 2.5rem;
            color: var(--sage-green);
            margin-bottom: 20px;
        }
        
        /* Process Section */
        .process-step {
            text-align: center;
            padding: 30px 20px;
        }
        
        .step-number {
            width: 60px;
            height: 60px;
            background: var(--sage-green);
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            font-weight: bold;
            margin: 0 auto 20px auto;
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

        /* Service Badges */
        .service-badge {
            background: var(--forest-mist);
            color: var(--deep-emerald);
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            margin-right: 5px;
            margin-bottom: 5px;
            display: inline-block;
        }

        /* No Services Message */
        .no-services {
            text-align: center;
            padding: 60px 20px;
            background: white;
            border-radius: 20px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.08);
        }

        .no-services-icon {
            font-size: 4rem;
            color: var(--forest-mist);
            margin-bottom: 20px;
        }

        /* Default Service Images */
        .default-service-image {
            width: 100%;
            height: 250px;
            background: linear-gradient(135deg, var(--sage-green), var(--dusty-teal));
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 4rem;
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

        /* Responsive adjustments */
        @media (max-width: 768px) {
            .display-3 {
                font-size: 2.5rem;
            }
            
            .display-5 {
                font-size: 2rem;
            }
            
            .footer-links a {
                display: block;
                margin: 8px 0;
            }
            
            .social-icon {
                margin: 0 10px;
            }
            
            .service-image {
                height: 200px;
            }
            
            .default-service-image {
                height: 200px;
                font-size: 3rem;
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
                    <li class="nav-item"><a class="nav-link active" href="services.php">Our Services</a></li>
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
            <h1 class="display-3 fw-bold mb-4">Our Specialized Services</h1>
            <p class="lead mb-4">
                Comprehensive Ayurvedic Treatments and Compassionate Elder Care Solutions
            </p>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb justify-content-center">
                    <li class="breadcrumb-item"><a href="index.php" class="text-white">Home</a></li>
                    <li class="breadcrumb-item active text-white" aria-current="page">Our Services</li>
                </ol>
            </nav>
        </div>
    </section>

    <!-- Dynamic Services Section -->
    <?php if (!empty($services_by_category)): ?>
        <?php foreach($services_by_category as $category_name => $services): ?>
            <section class="py-5 <?php echo ($category_name == 'Elder Care') ? 'section-bg-alt' : 'section-bg'; ?>">
                <div class="container">
                    <div class="service-category">
                        <h2 class="section-title text-center display-4 mb-5"><?php echo htmlspecialchars($category_name); ?></h2>
                        <div class="row">
                            <?php foreach($services as $service): ?>
                                <div class="col-lg-4 mb-4">
                                    <div class="service-card">
                                        <!-- Service Image -->
                                        <?php if (!empty($service['image_path'])): ?>
                                            <img src="<?php echo htmlspecialchars($service['image_path']); ?>" 
                                                 alt="<?php echo htmlspecialchars($service['title']); ?>" 
                                                 class="service-image">
                                        <?php else: ?>
                                            <!-- Default image with icon -->
                                            <div class="default-service-image">
                                                <?php 
                                                // Determine icon based on category
                                                $icon = 'fas fa-heart';
                                                if (stripos($category_name, 'ayurved') !== false) {
                                                    $icon = 'fas fa-spa';
                                                } elseif (stripos($category_name, 'elder') !== false) {
                                                    $icon = 'fas fa-home';
                                                } elseif (stripos($category_name, 'wellness') !== false) {
                                                    $icon = 'fas fa-heartbeat';
                                                }
                                                ?>
                                                <i class="<?php echo $icon; ?>"></i>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <div class="service-content">
                                            <h4 class="brand-font"><?php echo htmlspecialchars($service['title']); ?></h4>
                                            <p class="text-muted"><?php echo htmlspecialchars($service['description']); ?></p>
                                            
                                            <!-- Service badges for duration and tags if available -->
                                            <div class="mt-3">
                                                <?php if (!empty($service['duration'])): ?>
                                                    <span class="service-badge">
                                                        <i class="fas fa-clock me-1"></i><?php echo htmlspecialchars($service['duration']); ?>
                                                    </span>
                                                <?php endif; ?>
                                                
                                                <?php if (!empty($service['tags'])): ?>
                                                    <?php 
                                                    $tags = explode(',', $service['tags']);
                                                    foreach(array_slice($tags, 0, 2) as $tag): 
                                                        if (!empty(trim($tag))):
                                                    ?>
                                                        <span class="service-badge"><?php echo htmlspecialchars(trim($tag)); ?></span>
                                                    <?php 
                                                        endif;
                                                    endforeach; 
                                                    ?>
                                                <?php endif; ?>
                                            </div>
                                            
                                            <?php if (!empty($service['price'])): ?>
                                                <div class="mt-3">
                                                    <h5 class="text-success fw-bold">LKR <?php echo number_format($service['price'], 2); ?></h5>
                                                </div>
                                            <?php endif; ?>
                                            
                                            <!-- View Details Button -->
                                            <div class="mt-4">
                                                <button class="btn btn-primary" 
                                                        data-bs-toggle="modal" 
                                                        data-bs-target="#serviceModal<?php echo $service['service_id']; ?>">
                                                    <i class="fas fa-info-circle me-2"></i><span>View Details</span>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Service Modal -->
                                <div class="modal fade" id="serviceModal<?php echo $service['service_id']; ?>" tabindex="-1">
                                    <div class="modal-dialog modal-lg">
                                        <div class="modal-content">
                                            <div class="modal-header">
                                                <h5 class="modal-title brand-font"><?php echo htmlspecialchars($service['title']); ?></h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                            </div>
                                            <div class="modal-body">
                                                <div class="row">
                                                    <?php if (!empty($service['image_path'])): ?>
                                                        <div class="col-md-6">
                                                            <img src="<?php echo htmlspecialchars($service['image_path']); ?>" 
                                                                 alt="<?php echo htmlspecialchars($service['title']); ?>" 
                                                                 class="img-fluid rounded">
                                                        </div>
                                                    <?php endif; ?>
                                                    <div class="<?php echo !empty($service['image_path']) ? 'col-md-6' : 'col-12'; ?>">
                                                        <p class="lead"><?php echo htmlspecialchars($service['description']); ?></p>
                                                        
                                                        <?php if (!empty($service['features'])): ?>
                                                            <h6 class="brand-font">Key Features:</h6>
                                                            <ul class="service-features">
                                                                <?php 
                                                                $features = explode(',', $service['features']);
                                                                foreach($features as $feature): 
                                                                    if (!empty(trim($feature))):
                                                                ?>
                                                                    <li><i class="fas fa-check"></i><?php echo htmlspecialchars(trim($feature)); ?></li>
                                                                <?php 
                                                                    endif;
                                                                endforeach; 
                                                                ?>
                                                            </ul>
                                                        <?php endif; ?>
                                                        
                                                        <div class="mt-3">
                                                            <?php if (!empty($service['duration'])): ?>
                                                                <p><strong>Duration:</strong> <?php echo htmlspecialchars($service['duration']); ?></p>
                                                            <?php endif; ?>
                                                            
                                                            <?php if (!empty($service['price'])): ?>
                                                                <p><strong>Price:</strong> LKR <?php echo number_format($service['price'], 2); ?></p>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </section>
        <?php endforeach; ?>
    <?php else: ?>
        <!-- No Services Available -->
        <section class="py-5 section-bg">
            <div class="container">
                <div class="no-services">
                    <div class="no-services-icon">
                        <i class="fas fa-spa"></i>
                    </div>
                    <h3 class="text-muted">Services Coming Soon</h3>
                    <p class="lead text-muted">We are currently updating our service offerings. Please check back soon or contact us for more information.</p>
                    <a href="contact.php" class="btn btn-primary mt-3">
                        <i class="fas fa-phone me-2"></i>Contact Us
                    </a>
                </div>
            </div>
        </section>
    <?php endif; ?>

    <!-- Rest of the page remains the same -->
    <!-- SmartCare Guardian Section -->
<section class="py-5 smartcare-section">
    <div class="container">
        <h2 class="section-title text-center display-4 mb-5">SmartCare Guardian Technology</h2>
        <div class="row align-items-center">
            <div class="col-lg-6">
                <h3 class="display-5 fw-bold mb-4">AI-Powered Elderly Care Monitoring</h3>
                <p class="lead mb-4">
                    Our innovative SmartCare Guardian system uses artificial intelligence to provide proactive 
                    health monitoring and personalized care recommendations for our elderly residents.
                </p>
                <div class="feature-grid">
                    <div class="feature-item">
                        <div class="feature-icon">
                            <i class="fas fa-brain"></i>
                        </div>
                        <h5 class="brand-font">Predictive Analytics</h5>
                        <p>AI algorithms predict potential health risks before they become critical</p>
                    </div>
                    <div class="feature-item">
                        <div class="feature-icon">
                            <i class="fas fa-bell"></i>
                        </div>
                        <h5 class="brand-font">Real-time Alerts</h5>
                        <p>Instant notifications for caregivers about health anomalies</p>
                    </div>
                    <div class="feature-item">
                        <div class="feature-icon">
                            <i class="fas fa-chart-line"></i>
                        </div>
                        <h5 class="brand-font">Health Trends</h5>
                        <p>Comprehensive tracking of vital signs and health patterns</p>
                    </div>
                    <div class="feature-item">
                        <div class="feature-icon">
                            <i class="fas fa-user-cog"></i>
                        </div>
                        <h5 class="brand-font">Personalized Care</h5>
                        <p>Customized care plans based on individual health data</p>
                    </div>
                </div>
            </div>
            <div class="col-lg-6 text-center">
                <div class="service-card" style="margin: 0 auto; max-width: 400px;">
                    <div class="service-content">
                        <div class="service-icon">
                            <i class="fas fa-shield-heart"></i>
                        </div>
                        <h4 class="brand-font">SmartCare Guardian</h4>
                        <p class="mb-4">Advanced AI system for proactive elderly health management</p>
                        <a href="login.php" class="btn btn-primary btn-lg">
                            <i class="fas fa-sign-in-alt me-2"></i><span>Access System</span>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

    <!-- Service Process Section -->
    <section class="py-5 section-bg-alt">
        <div class="container">
            <h2 class="section-title text-center display-4 mb-5">Our Service Process</h2>
            <div class="row">
                <div class="col-lg-3 col-md-6">
                    <div class="process-step">
                        <div class="step-number">1</div>
                        <h5 class="brand-font">Initial Consultation</h5>
                        <p>Comprehensive health assessment and personalized care plan development</p>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6">
                    <div class="process-step">
                        <div class="step-number">2</div>
                        <h5 class="brand-font">Treatment Planning</h5>
                        <p>Customized treatment protocols based on individual needs and health conditions</p>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6">
                    <div class="process-step">
                        <div class="step-number">3</div>
                        <h5 class="brand-font">Implementation</h5>
                        <p>Professional delivery of treatments and continuous care monitoring</p>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6">
                    <div class="process-step">
                        <div class="step-number">4</div>
                        <h5 class="brand-font">Follow-up & Support</h5>
                        <p>Regular progress assessments and ongoing support for optimal results</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- CTA Section -->
    <section class="py-5" style="background: linear-gradient(135deg, var(--sage-green), var(--deep-emerald)); color: white;">
        <div class="container text-center">
            <h2 class="display-5 fw-bold mb-4">Ready to Begin Your Healing Journey?</h2>
            <p class="lead mb-5">
                Contact us today to schedule a consultation and discover how our services can transform your health and well-being.
            </p>
            <div class="d-flex flex-wrap justify-content-center gap-3">
                <a href="contact.php" class="btn btn-light btn-lg">
                    <i class="fas fa-calendar me-2"></i>Book Consultation
                </a>
                <a href="tel:+94726506485" class="btn btn-outline-light btn-lg">
                    <i class="fas fa-phone me-2"></i>Call Now
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
            <div class="text-center" data-aos="fade-up">
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