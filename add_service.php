<?php
session_start();
include 'db_connection.php';

// Only admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

$message = "";

// Fetch categories
$categories = $conn->query("SELECT * FROM service_categories ORDER BY category_name");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title']);
    $description = trim($_POST['description']);
    $category_id = intval($_POST['category']);

    // Upload image 
    $target_dir = "uploads/services/";
    if (!is_dir($target_dir)) mkdir($target_dir, 0777, true);

    $image_name = time() . "_" . basename($_FILES["image"]["name"]);
    $target_file = $target_dir . $image_name;
    move_uploaded_file($_FILES["image"]["tmp_name"], $target_file);

    // Insert into DB
    $stmt = $conn->prepare("INSERT INTO services (title, description, category_id, image_path) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("ssis", $title, $description, $category_id, $target_file);

    if ($stmt->execute()) {
        $message = "<div class='alert alert-success'>Service added successfully!</div>";
    } else {
        $message = "<div class='alert alert-danger'>Error adding service.</div>";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Service - SmartCare Guardian</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500;600;700&family=Quicksand:wght@300;400;500;600&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --sage-green: #87A96B;
            --mint-cream: #F0FFF0;
            --seafoam: #9FE2BF;
            --forest-mist: #B8E0D2;
            --dusty-teal: #6D9B8E;
            --deep-emerald: #4A766E;
        }
        
        body {
            font-family: 'Quicksand', sans-serif;
            background: linear-gradient(135deg, var(--mint-cream) 0%, var(--forest-mist) 100%);
            min-height: 100vh;
            padding: 20px;
            position: relative;
            overflow-x: hidden;
        }
        
        body::before {
            content: '';
            position: fixed;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 1px, transparent 1px);
            background-size: 50px 50px;
            animation: float 20s infinite linear;
            z-index: 0;
        }
        
        @keyframes float {
            0% { transform: translate(0, 0) rotate(0deg); }
            100% { transform: translate(-50px, -50px) rotate(360deg); }
        }
        
        h1, h2, h3, h4, h5 {
            font-family: 'Playfair Display', serif;
            color: var(--deep-emerald);
        }
        
        .brand-font {
            font-family: 'Jost', sans-serif;
            font-weight: 600;
        }

        .add-wrapper {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            position: relative;
            z-index: 1;
        }

        .add-container {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.1);
            overflow: hidden;
            max-width: 600px;
            width: 100%;
            border: 1px solid rgba(255, 255, 255, 0.2);
            margin: 20px 0;
        }
        
        .add-header {
            background: linear-gradient(135deg, var(--sage-green), var(--dusty-teal));
            color: white;
            padding: 40px 30px;
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        
        .add-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" fill="rgba(255,255,255,0.1)"><circle cx="20" cy="20" r="2"/><circle cx="80" cy="40" r="2"/><circle cx="40" cy="80" r="2"/><circle cx="70" cy="20" r="2"/></svg>');
            animation: subtleMove 10s infinite linear;
        }
        
        @keyframes subtleMove {
            0% { transform: translate(0, 0); }
            100% { transform: translate(10px, 10px); }
        }
        
        .add-body {
            padding: 40px 30px;
        }
        
        .form-control {
            border: 2px solid var(--forest-mist);
            border-radius: 12px;
            padding: 15px 20px;
            font-size: 16px;
            transition: all 0.3s ease;
            background: rgba(255, 255, 255, 0.8);
        }
        
        .form-control:focus {
            border-color: var(--sage-green);
            box-shadow: 0 0 0 0.2rem rgba(135, 169, 107, 0.25);
            transform: translateY(-2px);
        }
        
        .form-select {
            border: 2px solid var(--forest-mist);
            border-radius: 12px;
            padding: 15px 20px;
            font-size: 16px;
            transition: all 0.3s ease;
            background: rgba(255, 255, 255, 0.8);
        }
        
        .form-select:focus {
            border-color: var(--sage-green);
            box-shadow: 0 0 0 0.2rem rgba(135, 169, 107, 0.25);
            transform: translateY(-2px);
        }
        
        .form-label {
            color: var(--deep-emerald);
            font-weight: 600;
            margin-bottom: 8px;
        }
        
        .input-group-icon {
            position: relative;
        }
        
        .input-group-icon .form-control {
            padding-left: 45px;
        }
        
        .input-group-icon i {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--dusty-teal);
            z-index: 3;
        }
        
        .btn-success {
            background: linear-gradient(135deg, var(--sage-green), var(--dusty-teal));
            border: none;
            padding: 15px 30px;
            border-radius: 50px;
            font-weight: 600;
            font-size: 16px;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            width: 100%;
            position: relative;
            overflow: hidden;
        }
        
        .btn-success::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, var(--dusty-teal), var(--sage-green));
            transition: left 0.4s ease;
        }
        
        .btn-success:hover::before {
            left: 0;
        }
        
        .btn-success span {
            position: relative;
            z-index: 2;
        }
        
        .btn-success:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(141, 182, 154, 0.4);
        }
        
        .btn-secondary {
            background: transparent;
            border: 2px solid var(--dusty-teal);
            color: var(--dusty-teal);
            padding: 15px 30px;
            border-radius: 50px;
            font-weight: 600;
            font-size: 16px;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            width: 100%;
            margin-top: 10px;
            text-decoration: none;
            display: inline-block;
            text-align: center;
        }
        
        .btn-secondary:hover {
            background: var(--dusty-teal);
            color: white;
            transform: translateY(-3px);
        }
        
        .alert {
            border-radius: 12px;
            border: none;
            padding: 15px 20px;
            margin-bottom: 20px;
        }
        
        .alert-success {
            background: linear-gradient(135deg, var(--seafoam), var(--sage-green));
            color: white;
        }
        
        .alert-danger {
            background: linear-gradient(135deg, #ff6b6b, #ee5a52);
            color: white;
        }
        
        .feature-icon {
            font-size: 3rem;
            margin-bottom: 20px;
            color: rgba(255, 255, 255, 0.9);
        }
        
        .floating {
            animation: floating 3s ease-in-out infinite;
        }
        
        @keyframes floating {
            0% { transform: translate(0, 0px); }
            50% { transform: translate(0, -10px); }
            100% { transform: translate(0, 0px); }
        }
        
        .file-input-wrapper {
            position: relative;
            overflow: hidden;
            display: inline-block;
            width: 100%;
        }
        
        .file-input-wrapper input[type=file] {
            position: absolute;
            left: 0;
            top: 0;
            opacity: 0;
            width: 100%;
            height: 100%;
            cursor: pointer;
        }
        
        .file-input-custom {
            border: 2px dashed var(--forest-mist);
            border-radius: 12px;
            padding: 30px 20px;
            text-align: center;
            background: rgba(255, 255, 255, 0.6);
            transition: all 0.3s ease;
            cursor: pointer;
        }
        
        .file-input-custom:hover {
            border-color: var(--sage-green);
            background: rgba(255, 255, 255, 0.8);
        }
        
        .file-input-custom i {
            font-size: 2rem;
            color: var(--dusty-teal);
            margin-bottom: 10px;
        }
        
        .file-input-text {
            color: var(--deep-emerald);
            font-weight: 600;
        }
        
        .file-input-hint {
            color: var(--dusty-teal);
            font-size: 14px;
            margin-top: 5px;
        }

        .button-group {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        /* Responsive adjustments */
        @media (max-height: 700px) {
            .add-wrapper {
                align-items: flex-start;
                padding: 40px 0;
            }
            
            .add-container {
                margin: 20px;
            }
        }

        @media (max-width: 480px) {
            .add-body {
                padding: 30px 20px;
            }
            
            .add-header {
                padding: 30px 20px;
            }
        }

        @media (min-width: 768px) {
            .button-group {
                flex-direction: row;
            }
            
            .button-group .btn {
                width: 50%;
            }
        }
    </style>
</head>
<body>
    <div class="add-wrapper">
        <div class="add-container">
            <div class="add-header">
                <div class="feature-icon floating">
                    <i class="fas fa-plus-circle"></i>
                </div>
                <h2 class="brand-font mb-3">Add New Service</h2>
                <p class="mb-0">Create a new healthcare service for residents</p>
            </div>
            
            <div class="add-body">
                <?php echo $message; ?>
                
                <form method="POST" enctype="multipart/form-data" id="serviceForm">
                    <div class="mb-4">
                        <label class="form-label">
                            <i class="fas fa-heading me-2"></i>Service Title
                        </label>
                        <div class="input-group-icon">
                            <i class="fas fa-heading"></i>
                            <input type="text" name="title" class="form-control" required 
                                   placeholder="Enter service title">
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label">
                            <i class="fas fa-tags me-2"></i>Service Category
                        </label>
                        <select name="category" class="form-select" required>
                            <option value="">Select Category</option>
                            <?php while($cat = $categories->fetch_assoc()): ?>
                                <option value="<?= $cat['category_id'] ?>">
                                    <?= htmlspecialchars($cat['category_name']) ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div class="mb-4">
                        <label class="form-label">
                            <i class="fas fa-file-alt me-2"></i>Service Description
                        </label>
                        <textarea name="description" class="form-control" rows="4" required 
                                  placeholder="Describe the service in detail"></textarea>
                    </div>

                    <div class="mb-4">
                        <label class="form-label">
                            <i class="fas fa-image me-2"></i>Service Image
                        </label>
                        <div class="file-input-wrapper">
                            <div class="file-input-custom">
                                <i class="fas fa-cloud-upload-alt"></i>
                                <div class="file-input-text">Choose Service Image</div>
                                <div class="file-input-hint">Click to upload or drag and drop</div>
                                <div class="file-input-hint">PNG, JPG, JPEG up to 5MB</div>
                            </div>
                            <input type="file" name="image" required accept="image/*">
                        </div>
                        <div id="fileName" class="text-center mt-2 text-muted" style="font-size: 14px;"></div>
                    </div>

                    <div class="button-group">
                        <button type="submit" class="btn-success">
                            <i class="fas fa-plus me-2"></i><span>Add Service</span>
                        </button>
                        <a href="manage_services.php" class="btn btn-secondary">
                            <i class="fas fa-arrow-left me-2"></i>Back to Services
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // File input display
        document.querySelector('input[name="image"]').addEventListener('change', function(e) {
            const fileName = this.files[0] ? this.files[0].name : 'No file chosen';
            document.getElementById('fileName').textContent = `Selected: ${fileName}`;
            
            // Add visual feedback
            const fileInputCustom = document.querySelector('.file-input-custom');
            if (this.files[0]) {
                fileInputCustom.style.borderColor = 'var(--sage-green)';
                fileInputCustom.style.background = 'rgba(135, 169, 107, 0.1)';
            }
        });

        // Form validation and loading state
        document.getElementById('serviceForm').addEventListener('submit', function(e) {
            const title = document.querySelector('input[name="title"]').value;
            const category = document.querySelector('select[name="category"]').value;
            const description = document.querySelector('textarea[name="description"]').value;
            const image = document.querySelector('input[name="image"]').files[0];
            
            // Basic validation
            if (title.trim().length < 2) {
                e.preventDefault();
                alert('Please enter a valid service title.');
                return;
            }
            
            if (!category) {
                e.preventDefault();
                alert('Please select a service category.');
                return;
            }
            
            if (description.trim().length < 10) {
                e.preventDefault();
                alert('Please enter a detailed description (at least 10 characters).');
                return;
            }
            
            if (!image) {
                e.preventDefault();
                alert('Please select a service image.');
                return;
            }
            
            // Check file size (5MB limit)
            if (image.size > 5 * 1024 * 1024) {
                e.preventDefault();
                alert('Image size must be less than 5MB.');
                return;
            }
            
            // Show loading state
            const submitBtn = this.querySelector('button[type="submit"]');
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i><span>Adding Service...</span>';
            submitBtn.disabled = true;
        });
        
        // Add floating animation to form elements on focus
        document.querySelectorAll('.form-control, .form-select, .file-input-custom').forEach(input => {
            input.addEventListener('focus', function() {
                this.classList.add('floating');
            });
            
            input.addEventListener('blur', function() {
                this.classList.remove('floating');
            });
        });
        
        // Auto-focus first field on page load
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelector('input[name="title"]').focus();
        });

        // Drag and drop functionality
        const fileInput = document.querySelector('input[name="image"]');
        const fileInputCustom = document.querySelector('.file-input-custom');
        
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
            fileInputCustom.addEventListener(eventName, preventDefaults, false);
        });
        
        function preventDefaults(e) {
            e.preventDefault();
            e.stopPropagation();
        }
        
        ['dragenter', 'dragover'].forEach(eventName => {
            fileInputCustom.addEventListener(eventName, highlight, false);
        });
        
        ['dragleave', 'drop'].forEach(eventName => {
            fileInputCustom.addEventListener(eventName, unhighlight, false);
        });
        
        function highlight() {
            fileInputCustom.style.borderColor = 'var(--sage-green)';
            fileInputCustom.style.background = 'rgba(135, 169, 107, 0.2)';
        }
        
        function unhighlight() {
            fileInputCustom.style.borderColor = 'var(--forest-mist)';
            fileInputCustom.style.background = 'rgba(255, 255, 255, 0.6)';
        }
        
        fileInputCustom.addEventListener('drop', handleDrop, false);
        
        function handleDrop(e) {
            const dt = e.dataTransfer;
            const files = dt.files;
            fileInput.files = files;
            
            // Trigger change event
            const event = new Event('change');
            fileInput.dispatchEvent(event);
        }
    </script>
</body>
</html>