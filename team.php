<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Our Team - Subodha Ayurveda Hospital</title>
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

        /* Team Page Specific Styles */
        .page-hero {
            background: linear-gradient(135deg, var(--sage-green), var(--deep-emerald));
            color: white;
            padding: 120px 0 80px 0;
            text-align: center;
            margin-top: 0;
        }
        
        .team-category {
            margin-bottom: 80px;
        }
        
        .team-member-card {
            background: white;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0,0,0,0.08);
            transition: all 0.4s ease;
            margin-bottom: 30px;
            height: 100%;
        }
        
        .team-member-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 20px 40px rgba(0,0,0,0.15);
        }
        
        .member-image {
            width: 100%;
            height: 300px;
            object-fit: cover;
            border-bottom: 4px solid var(--sage-green);
        }
        
        .member-image-placeholder {
            width: 100%;
            height: 300px;
            background: linear-gradient(135deg, var(--forest-mist), var(--seafoam));
            display: flex;
            align-items: center;
            justify-content: center;
            border-bottom: 4px solid var(--sage-green);
        }
        
        .member-info {
            padding: 30px 25px;
        }
        
        .member-name {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 5px;
            color: var(--deep-emerald);
        }
        
        .member-role {
            color: var(--sage-green);
            font-weight: 600;
            margin-bottom: 15px;
            font-size: 1.1rem;
        }
        
        .member-qualifications {
            color: var(--dusty-teal);
            font-size: 0.9rem;
            margin-bottom: 15px;
        }
        
        .member-bio {
            color: #666;
            line-height: 1.6;
            margin-bottom: 20px;
        }
        
        .member-specialties {
            margin-top: 15px;
        }
        
        .specialty-tag {
            display: inline-block;
            background: var(--forest-mist);
            color: var(--deep-emerald);
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            margin: 2px;
            font-weight: 500;
        }
        
        /* Department Sections */
        .department-header {
            text-align: center;
            margin-bottom: 50px;
            padding: 40px 0;
            background: linear-gradient(135deg, var(--mint-cream) 0%, #ffffff 100%);
            border-radius: 15px;
        }
        
        .department-icon {
            font-size: 4rem;
            color: var(--sage-green);
            margin-bottom: 20px;
        }
        
        /* Team Stats */
        .stats-section {
            background: linear-gradient(135deg, var(--mint-cream) 0%, var(--forest-mist) 100%);
        }
        
        .stat-card {
            text-align: center;
            padding: 40px 20px;
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
            font-size: 1.1rem;
        }
        
        /* Team Values */
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
            transform: translateY(-5px);
            box-shadow: 0 15px 35px rgba(0,0,0,0.15);
        }
        
        .value-icon {
            font-size: 3rem;
            color: var(--sage-green);
            margin-bottom: 20px;
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

        /* Responsive adjustments */
        @media (max-width: 768px) {
            .display-3 {
                font-size: 2.5rem;
            }
            
            .display-5 {
                font-size: 2rem;
            }
            
            .member-image {
                height: 250px;
            }
            
            .member-image-placeholder {
                height: 250px;
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
                    <li class="nav-item"><a class="nav-link" href="about.php">About Us</a></li>
                    <li class="nav-item"><a class="nav-link" href="services.php">Our Services</a></li>
                    <li class="nav-item"><a class="nav-link active" href="team.php">Our Team</a></li>
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
            <h1 class="display-3 fw-bold mb-4">Meet Our Compassionate Team</h1>
            <p class="lead mb-4">
                Dedicated Healthcare Professionals Committed to Your Well-being
            </p>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb justify-content-center">
                    <li class="breadcrumb-item"><a href="index.php" class="text-white">Home</a></li>
                    <li class="breadcrumb-item active text-white" aria-current="page">Our Team</li>
                </ol>
            </nav>
        </div>
    </section>

    <!-- Team Stats Section -->
    <section class="py-5 stats-section">
        <div class="container">
            <div class="row text-center">
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="stat-number">20+</div>
                        <div class="stat-label">Years Experience</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="stat-number">18</div>
                        <div class="stat-label">Expert Staff</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="stat-number">5,000+</div>
                        <div class="stat-label">Patients Treated</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="stat-number">24/7</div>
                        <div class="stat-label">Care Available</div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Medical Team Section -->
    <section class="py-5 section-bg">
        <div class="container">
            <div class="team-category">
                <div class="department-header">
                    <div class="department-icon">
                        <i class="fas fa-user-md"></i>
                    </div>
                    <h2 class="display-5 fw-bold mb-3">Medical & Ayurvedic Team</h2>
                    <p class="lead">Our experienced physicians and Ayurvedic specialists providing comprehensive healthcare</p>
                </div>
                
                <div class="row">
                    <!-- Chief Physician -->
                    <div class="col-lg-4 col-md-6">
                        <div class="team-member-card">
                            <div class="member-image-placeholder">
                                <i class="fas fa-user-md fa-6x text-white"></i>
                            </div>
                            <div class="member-info">
                                <h3 class="member-name">Dr. Ananda Perera</h3>
                                <div class="member-role">Chief Ayurvedic Physician</div>
                                <div class="member-qualifications">BAMS, MD (Ayurveda), 25+ years experience</div>
                                <p class="member-bio">
                                    Dr. Perera specializes in geriatric Ayurvedic care and traditional healing therapies. 
                                    With over 25 years of experience, he has helped thousands of patients achieve better health 
                                    through personalized Ayurvedic treatments.
                                </p>
                                <div class="member-specialties">
                                    <span class="specialty-tag">Geriatric Care</span>
                                    <span class="specialty-tag">Panchakarma</span>
                                    <span class="specialty-tag">Chronic Diseases</span>
                                    <span class="specialty-tag">Herbal Medicine</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Senior Ayurvedic Doctor -->
                    <div class="col-lg-4 col-md-6">
                        <div class="team-member-card">
                            <div class="member-image-placeholder">
                                <i class="fas fa-stethoscope fa-6x text-white"></i>
                            </div>
                            <div class="member-info">
                                <h3 class="member-name">Dr. Priya Fernando</h3>
                                <div class="member-role">Senior Ayurvedic Doctor</div>
                                <div class="member-qualifications">BAMS, PGD in Geriatric Care, 15 years experience</div>
                                <p class="member-bio">
                                    Dr. Fernando focuses on integrative medicine, blending traditional Ayurvedic principles 
                                    with modern healthcare practices. She specializes in women's health and preventive care.
                                </p>
                                <div class="member-specialties">
                                    <span class="specialty-tag">Women's Health</span>
                                    <span class="specialty-tag">Preventive Care</span>
                                    <span class="specialty-tag">Yoga Therapy</span>
                                    <span class="specialty-tag">Nutrition</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Resident Medical Officer -->
                    <div class="col-lg-4 col-md-6">
                        <div class="team-member-card">
                            <div class="member-image-placeholder">
                                <i class="fas fa-heartbeat fa-6x text-white"></i>
                            </div>
                            <div class="member-info">
                                <h3 class="member-name">Dr. Sameera Rajapaksa</h3>
                                <div class="member-role">Resident Medical Officer</div>
                                <div class="member-qualifications">MBBS, Diploma in Geriatric Medicine</div>
                                <p class="member-bio">
                                    Dr. Rajapaksa provides round-the-clock medical care and coordinates with specialists 
                                    for comprehensive elderly healthcare management. She ensures all medical needs are met promptly.
                                </p>
                                <div class="member-specialties">
                                    <span class="specialty-tag">Emergency Care</span>
                                    <span class="specialty-tag">Medication Management</span>
                                    <span class="specialty-tag">Health Monitoring</span>
                                    <span class="specialty-tag">Chronic Care</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Nursing & Care Team Section -->
    <section class="py-5 section-bg-alt">
        <div class="container">
            <div class="team-category">
                <div class="department-header">
                    <div class="department-icon">
                        <i class="fas fa-user-nurse"></i>
                    </div>
                    <h2 class="display-5 fw-bold mb-3">Nursing & Care Team</h2>
                    <p class="lead">Compassionate caregivers providing 24/7 support and personalized attention</p>
                </div>
                
                <div class="row">
                    <!-- Head of Elder Care -->
                    <div class="col-lg-4 col-md-6">
                        <div class="team-member-card">
                            <div class="member-image-placeholder">
                                <i class="fas fa-user-nurse fa-6x text-white"></i>
                            </div>
                            <div class="member-info">
                                <h3 class="member-name">Mrs. Nirmala Fernando</h3>
                                <div class="member-role">Head of Elder Care Unit</div>
                                <div class="member-qualifications">RN, BSc Nursing, 15 years experience</div>
                                <p class="member-bio">
                                    With 15 years of experience in elderly care, Mrs. Fernando leads our nursing team 
                                    with compassion and expertise. She ensures every resident receives personalized care 
                                    and maintains the highest standards of service.
                                </p>
                                <div class="member-specialties">
                                    <span class="specialty-tag">Elderly Care</span>
                                    <span class="specialty-tag">Team Management</span>
                                    <span class="specialty-tag">Care Planning</span>
                                    <span class="specialty-tag">Quality Assurance</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Senior Nurse -->
                    <div class="col-lg-4 col-md-6">
                        <div class="team-member-card">
                            <div class="member-image-placeholder">
                                <i class="fas fa-hand-holding-heart fa-6x text-white"></i>
                            </div>
                            <div class="member-info">
                                <h3 class="member-name">Mrs. Kamala Perera</h3>
                                <div class="member-role">Senior Registered Nurse</div>
                                <div class="member-qualifications">RN, Diploma in Geriatric Nursing</div>
                                <p class="member-bio">
                                    Mrs. Perera has been with us for 8 years, providing exceptional nursing care with 
                                    special focus on dementia and Alzheimer's patients. Her gentle approach brings comfort 
                                    to our residents.
                                </p>
                                <div class="member-specialties">
                                    <span class="specialty-tag">Dementia Care</span>
                                    <span class="specialty-tag">Medication Admin</span>
                                    <span class="specialty-tag">Wound Care</span>
                                    <span class="specialty-tag">Patient Education</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Caregiver -->
                    <div class="col-lg-4 col-md-6">
                        <div class="team-member-card">
                            <div class="member-image-placeholder">
                                <i class="fas fa-hands-helping fa-6x text-white"></i>
                            </div>
                            <div class="member-info">
                                <h3 class="member-name">Mr. Sunil Bandara</h3>
                                <div class="member-role">Senior Caregiver</div>
                                <div class="member-qualifications">Certified Caregiver, 10 years experience</div>
                                <p class="member-bio">
                                    Mr. Bandara provides compassionate daily living support with special training in 
                                    mobility assistance and therapeutic care. His positive attitude brightens everyone's day.
                                </p>
                                <div class="member-specialties">
                                    <span class="specialty-tag">Mobility Support</span>
                                    <span class="specialty-tag">Personal Care</span>
                                    <span class="specialty-tag">Therapeutic Activities</span>
                                    <span class="specialty-tag">Companionship</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Therapy & Wellness Team -->
    <section class="py-5 section-bg">
        <div class="container">
            <div class="team-category">
                <div class="department-header">
                    <div class="department-icon">
                        <i class="fas fa-spa"></i>
                    </div>
                    <h2 class="display-5 fw-bold mb-3">Therapy & Wellness Team</h2>
                    <p class="lead">Specialists in rehabilitation, yoga, and holistic wellness programs</p>
                </div>
                
                <div class="row">
                    <!-- Physiotherapist -->
                    <div class="col-lg-4 col-md-6">
                        <div class="team-member-card">
                            <div class="member-image-placeholder">
                                <i class="fas fa-wheelchair fa-6x text-white"></i>
                            </div>
                            <div class="member-info">
                                <h3 class="member-name">Mr. Dinesh Silva</h3>
                                <div class="member-role">Senior Physiotherapist</div>
                                <div class="member-qualifications">BSc Physiotherapy, Geriatric Specialist</div>
                                <p class="member-bio">
                                    Mr. Silva specializes in geriatric physiotherapy, helping residents maintain mobility 
                                    and independence through customized exercise programs and pain management techniques.
                                </p>
                                <div class="member-specialties">
                                    <span class="specialty-tag">Mobility Training</span>
                                    <span class="specialty-tag">Pain Management</span>
                                    <span class="specialty-tag">Balance Exercises</span>
                                    <span class="specialty-tag">Rehabilitation</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Yoga Instructor -->
                    <div class="col-lg-4 col-md-6">
                        <div class="team-member-card">
                            <div class="member-image-placeholder">
                                <i class="fas fa-spa fa-6x text-white"></i>
                            </div>
                            <div class="member-info">
                                <h3 class="member-name">Mrs. Anoma Wickramasinghe</h3>
                                <div class="member-role">Yoga & Meditation Instructor</div>
                                <div class="member-qualifications">RYT 500, Senior Yoga Teacher</div>
                                <p class="member-bio">
                                    Mrs. Wickramasinghe conducts gentle yoga and meditation sessions specifically designed 
                                    for seniors, focusing on flexibility, balance, and mental well-being.
                                </p>
                                <div class="member-specialties">
                                    <span class="specialty-tag">Chair Yoga</span>
                                    <span class="specialty-tag">Meditation</span>
                                    <span class="specialty-tag">Breathing Exercises</span>
                                    <span class="specialty-tag">Stress Relief</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Ayurvedic Therapist -->
                    <div class="col-lg-4 col-md-6">
                        <div class="team-member-card">
                            <div class="member-image-placeholder">
                                <i class="fas fa-hand-holding-medical fa-6x text-white"></i>
                            </div>
                            <div class="member-info">
                                <h3 class="member-name">Mr. Ranjith Kumara</h3>
                                <div class="member-role">Ayurvedic Therapist</div>
                                <div class="member-qualifications">Diploma in Ayurvedic Therapy, 12 years experience</div>
                                <p class="member-bio">
                                    Mr. Kumara provides traditional Ayurvedic massages and therapies, specializing in 
                                    pain relief, relaxation, and rejuvenation treatments for elderly clients.
                                </p>
                                <div class="member-specialties">
                                    <span class="specialty-tag">Abhyanga</span>
                                    <span class="specialty-tag">Shirodhara</span>
                                    <span class="specialty-tag">Pizhichil</span>
                                    <span class="specialty-tag">Pain Relief</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Support Staff Section -->
    <section class="py-5 section-bg-alt">
        <div class="container">
            <div class="team-category">
                <div class="department-header">
                    <div class="department-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <h2 class="display-5 fw-bold mb-3">Support & Administrative Team</h2>
                    <p class="lead">Dedicated professionals ensuring smooth operations and excellent service</p>
                </div>
                
                <div class="row">
                    <!-- Administrator -->
                    <div class="col-lg-4 col-md-6">
                        <div class="team-member-card">
                            <div class="member-image-placeholder">
                                <i class="fas fa-user-tie fa-6x text-white"></i>
                            </div>
                            <div class="member-info">
                                <h3 class="member-name">Mr. Sunil Perera</h3>
                                <div class="member-role">Hospital Administrator</div>
                                <div class="member-qualifications">MBA, Healthcare Management</div>
                                <p class="member-bio">
                                    Mr. Perera oversees all administrative operations, ensuring efficient management 
                                    and maintaining the highest standards of service delivery across the facility.
                                </p>
                                <div class="member-specialties">
                                    <span class="specialty-tag">Operations</span>
                                    <span class="specialty-tag">Management</span>
                                    <span class="specialty-tag">Coordination</span>
                                    <span class="specialty-tag">Planning</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Nutritionist -->
                    <div class="col-lg-4 col-md-6">
                        <div class="team-member-card">
                            <div class="member-image-placeholder">
                                <i class="fas fa-utensils fa-6x text-white"></i>
                            </div>
                            <div class="member-info">
                                <h3 class="member-name">Mrs. Chathuri Gunawardena</h3>
                                <div class="member-role">Nutritionist & Dietitian</div>
                                <div class="member-qualifications">BSc Nutrition, Ayurvedic Diet Specialist</div>
                                <p class="member-bio">
                                    Mrs. Gunawardena designs personalized nutritional plans based on Ayurvedic principles, 
                                    ensuring residents receive balanced, therapeutic meals for optimal health.
                                </p>
                                <div class="member-specialties">
                                    <span class="specialty-tag">Ayurvedic Nutrition</span>
                                    <span class="specialty-tag">Diet Planning</span>
                                    <span class="specialty-tag">Therapeutic Meals</span>
                                    <span class="specialty-tag">Health Education</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Housekeeping Supervisor -->
                    <div class="col-lg-4 col-md-6">
                        <div class="team-member-card">
                            <div class="member-image-placeholder">
                                <i class="fas fa-broom fa-6x text-white"></i>
                            </div>
                            <div class="member-info">
                                <h3 class="member-name">Mrs. Manel Herath</h3>
                                <div class="member-role">Housekeeping Supervisor</div>
                                <div class="member-qualifications">Hospital Hygiene Specialist</div>
                                <p class="member-bio">
                                    Mrs. Herath ensures our facility maintains the highest standards of cleanliness and hygiene, 
                                    creating a safe and comfortable environment for all residents and staff.
                                </p>
                                <div class="member-specialties">
                                    <span class="specialty-tag">Hygiene</span>
                                    <span class="specialty-tag">Sanitation</span>
                                    <span class="specialty-tag">Quality Control</span>
                                    <span class="specialty-tag">Team Supervision</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Team Values Section -->
    <section class="py-5 section-bg">
        <div class="container">
            <h2 class="section-title text-center display-4 mb-5">Our Team Values</h2>
            <div class="values-grid">
                <div class="value-card">
                    <div class="value-icon">
                        <i class="fas fa-heart"></i>
                    </div>
                    <h4 class="brand-font">Compassionate Care</h4>
                    <p>We treat every resident with the same love and respect we would give our own family members.</p>
                </div>
                <div class="value-card">
                    <div class="value-icon">
                        <i class="fas fa-shield-alt"></i>
                    </div>
                    <h4 class="brand-font">Professional Excellence</h4>
                    <p>Continuous training and development ensure we provide the highest quality healthcare services.</p>
                </div>
                <div class="value-card">
                    <div class="value-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <h4 class="brand-font">Team Collaboration</h4>
                    <p>We work together seamlessly to provide comprehensive, coordinated care for every resident.</p>
                </div>
                <div class="value-card">
                    <div class="value-icon">
                        <i class="fas fa-star"></i>
                    </div>
                    <h4 class="brand-font">Continuous Improvement</h4>
                    <p>We constantly seek ways to enhance our services and implement best practices in elderly care.</p>
                </div>
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