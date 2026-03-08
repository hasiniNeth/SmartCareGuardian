<?php
session_start();
include 'db_connection.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

$id = intval($_GET['id']);
$service = $conn->query("SELECT * FROM services WHERE service_id=$id")->fetch_assoc();
$categories = $conn->query("SELECT * FROM service_categories");

$message = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title       = trim($_POST['title']);
    $description = trim($_POST['description']);
    $category_id = intval($_POST['category']);
    $imagePath   = $service['image_path'];

    if ($_FILES['image']['size'] > 0) {
        $target_dir = "uploads/services/";
        if (!is_dir($target_dir)) mkdir($target_dir, 0777, true);
        $new_image   = time() . "_" . basename($_FILES["image"]["name"]);
        $target_file = $target_dir . $new_image;
        move_uploaded_file($_FILES["image"]["tmp_name"], $target_file);
        $imagePath = $target_file;
    }

    $stmt = $conn->prepare("UPDATE services SET title=?, description=?, category_id=?, image_path=? WHERE service_id=?");
    $stmt->bind_param("ssisi", $title, $description, $category_id, $imagePath, $id);

    if ($stmt->execute()) {
        $message = "<div class='alert alert-success'><i class='fas fa-check-circle me-2'></i>Service updated successfully!</div>";
    } else {
        $message = "<div class='alert alert-danger'><i class='fas fa-exclamation-circle me-2'></i>Error updating service.</div>";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Service - SmartCare Guardian</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;1,400&family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --s50:  #F2F6EF;
            --s100: #E3EDDB;
            --s200: #C4D9B4;
            --s300: #9DC07E;
            --s400: #7AA658;
            --s500: #5E8A40;
            --s600: #4A6E30;
            --s700: #365220;
            --s800: #243816;
            --w50:  #FDFAF5;
            --w100: #F7F1E5;
            --st300: #B8B0A4;
            --st500: #7A7268;
            --st700: #4A4540;
            --green-bg:  #DDEFD8;
            --green-text:#3A6830;
            --red-bg:    #F5DADA;
            --red-text:  #6A2020;
            --radius-sm: 8px;
            --radius-md: 12px;
            --radius-lg: 20px;
            --shadow-card: 0 4px 24px rgba(36,56,22,.09), 0 1px 4px rgba(36,56,22,.06);
            --shadow-lift: 0 8px 32px rgba(36,56,22,.13), 0 2px 8px rgba(36,56,22,.07);
        }

        *, *::before, *::after { box-sizing: border-box; }

        body {
            font-family: 'Outfit', sans-serif;
            font-size: 15px;
            line-height: 1.6;
            min-height: 100vh;
            margin: 0;
            padding: 20px;
            background-color: var(--w50);
            background-image:
                radial-gradient(ellipse 80% 60% at 10% 10%, rgba(157,192,126,.12) 0%, transparent 55%),
                radial-gradient(ellipse 60% 50% at 90% 90%, rgba(94,138,64,.08) 0%, transparent 50%);
            color: var(--st700);
            overflow-x: hidden;
        }

        h1, h2, h3, h4, h5 {
            font-family: 'Cormorant Garamond', serif;
            color: var(--s800);
            margin: 0;
        }

        /* ── Layout ── */
        .edit-wrapper {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
        }

        .edit-container {
            background: #ffffff;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-lift);
            overflow: hidden;
            max-width: 580px;
            width: 100%;
            margin: 20px 0;
            border: 1px solid var(--s100);
        }

        /* ── Header ── */
        .edit-header {
            background: linear-gradient(135deg, var(--s800) 0%, var(--s700) 45%, var(--s500) 100%);
            padding: 38px 30px 32px;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .edit-header::before {
            content: '';
            position: absolute;
            inset: 0;
            background-image:
                radial-gradient(ellipse 100% 70% at 100% 50%, rgba(157,192,126,.15) 0%, transparent 60%);
            pointer-events: none;
        }

        .brand-icon-wrap {
            width: 58px;
            height: 58px;
            background: linear-gradient(135deg, var(--s300), var(--s500));
            border-radius: var(--radius-md);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            color: white;
            box-shadow: 0 4px 16px rgba(0,0,0,.25);
            margin-bottom: 14px;
            position: relative;
        }

        .edit-header h2 {
            color: white;
            font-size: 26px;
            font-weight: 600;
            margin-bottom: 6px;
            position: relative;
        }

        .edit-header p {
            color: rgba(255,255,255,.6);
            font-size: 13px;
            margin: 0;
            position: relative;
        }

        /* ── Body ── */
        .edit-body { padding: 36px 32px 32px; }

        /* ── Alerts ── */
        .alert {
            border-radius: var(--radius-md);
            border: none;
            padding: 13px 16px;
            margin-bottom: 22px;
            font-size: 14px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .alert-success { background: var(--green-bg); color: var(--green-text); }
        .alert-danger  { background: var(--red-bg);   color: var(--red-text);   }

        /* ── Form labels ── */
        .form-label {
            color: var(--s800);
            font-weight: 700;
            font-size: 13px;
            margin-bottom: 6px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .form-label i { color: var(--s400); }

        /* ── Inputs ── */
        .input-group-icon { position: relative; }

        .input-group-icon .form-control { padding-left: 42px; }

        .input-group-icon > i:first-of-type {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--s400);
            font-size: 13px;
            z-index: 3;
            pointer-events: none;
        }

        .form-control,
        .form-select {
            border: 2px solid var(--s100);
            border-radius: var(--radius-md);
            padding: 11px 14px;
            font-size: 14px;
            font-family: 'Outfit', sans-serif;
            background: var(--w50);
            color: var(--st700);
            transition: border-color .2s, box-shadow .2s;
            width: 100%;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: var(--s400);
            box-shadow: 0 0 0 3px rgba(122,166,88,.15);
            outline: none;
            background: #ffffff;
        }

        .form-control::placeholder { color: var(--st300); }

        textarea.form-control { resize: vertical; min-height: 110px; }

        /* ── Current image ── */
        .current-image-wrap {
            background: var(--s50);
            border: 1px solid var(--s100);
            border-radius: var(--radius-md);
            padding: 16px;
            text-align: center;
            margin-bottom: 4px;
        }

        .current-image-wrap img {
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-card);
            max-width: 200px;
            height: auto;
            border: 2px solid var(--s200);
        }

        .image-section-label {
            font-size: 13px;
            font-weight: 700;
            color: var(--s800);
            display: flex;
            align-items: center;
            gap: 6px;
            margin-bottom: 8px;
        }

        .image-section-label i { color: var(--s400); }

        /* ── File upload ── */
        .file-input-wrapper {
            position: relative;
            display: block;
            width: 100%;
        }

        .file-input-wrapper input[type=file] {
            position: absolute;
            inset: 0;
            opacity: 0;
            width: 100%;
            height: 100%;
            cursor: pointer;
        }

        .file-input-custom {
            border: 2px dashed var(--s200);
            border-radius: var(--radius-md);
            padding: 26px 20px;
            text-align: center;
            background: var(--s50);
            transition: border-color .2s, background .2s;
            cursor: pointer;
        }

        .file-input-custom:hover {
            border-color: var(--s400);
            background: var(--w100);
        }

        .file-input-custom i {
            font-size: 1.8rem;
            color: var(--s400);
            margin-bottom: 8px;
            display: block;
        }

        .file-input-text {
            color: var(--s700);
            font-weight: 700;
            font-size: 14px;
        }

        .file-input-hint {
            color: var(--st300);
            font-size: 12px;
            margin-top: 3px;
        }

        #fileName {
            font-size: 13px;
            color: var(--s500);
            font-weight: 600;
            margin-top: 8px;
            text-align: center;
        }

        /* ── Divider ── */
        .divider {
            height: 1px;
            background: linear-gradient(90deg, transparent, var(--s100), transparent);
            margin: 26px 0 22px;
        }

        /* ── Buttons ── */
        .btn-save {
            background: linear-gradient(135deg, var(--s400), var(--s700));
            border: none;
            padding: 11px 26px;
            border-radius: var(--radius-sm);
            font-weight: 700;
            font-size: 14px;
            font-family: 'Outfit', sans-serif;
            color: white;
            cursor: pointer;
            transition: opacity .2s, transform .2s, box-shadow .2s;
            display: inline-flex;
            align-items: center;
            gap: 7px;
            flex: 1;
            justify-content: center;
        }

        .btn-save:hover {
            opacity: .92;
            transform: translateY(-1px);
            box-shadow: var(--shadow-card);
            color: white;
        }

        .btn-back {
            background: transparent;
            border: 2px solid var(--s200);
            border-radius: var(--radius-sm);
            padding: 11px 22px;
            font-weight: 700;
            font-size: 14px;
            font-family: 'Outfit', sans-serif;
            color: var(--st500);
            text-decoration: none;
            transition: background .2s, border-color .2s, color .2s;
            display: inline-flex;
            align-items: center;
            gap: 7px;
            flex: 1;
            justify-content: center;
        }

        .btn-back:hover {
            background: var(--s50);
            border-color: var(--s300);
            color: var(--s700);
        }

        .btn-row {
            display: flex;
            gap: 12px;
        }

        /* ── Responsive ── */
        @media (max-height: 700px) {
            .edit-wrapper { align-items: flex-start; padding-top: 40px; }
        }

        @media (max-width: 480px) {
            .edit-body   { padding: 26px 20px; }
            .edit-header { padding: 28px 20px 24px; }
            .btn-row     { flex-direction: column; }
        }
    </style>
</head>
<body>
    <div class="edit-wrapper">
        <div class="edit-container">

            <div class="edit-header">
                <div class="brand-icon-wrap">
                    <i class="fas fa-pen-to-square"></i>
                </div>
                <h2>Edit Service</h2>
                <p>Update service information and image</p>
            </div>

            <div class="edit-body">

                <?php echo $message; ?>

                <form method="POST" enctype="multipart/form-data" id="serviceForm">

                    <div class="mb-4">
                        <label class="form-label">
                            <i class="fas fa-heading"></i>Service Title
                        </label>
                        <div class="input-group-icon">
                            <i class="fas fa-heading"></i>
                            <input type="text" name="title" class="form-control" required
                                   value="<?= htmlspecialchars($service['title']) ?>"
                                   placeholder="Enter service title">
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label">
                            <i class="fas fa-tags"></i>Service Category
                        </label>
                        <select name="category" class="form-select" required>
                            <?php while($cat = $categories->fetch_assoc()): ?>
                                <option value="<?= $cat['category_id'] ?>"
                                    <?= ($cat['category_id'] == $service['category_id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($cat['category_name']) ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div class="mb-4">
                        <label class="form-label">
                            <i class="fas fa-file-lines"></i>Service Description
                        </label>
                        <textarea name="description" class="form-control" rows="4" required
                                  placeholder="Describe the service in detail"><?= htmlspecialchars($service['description']) ?></textarea>
                    </div>

                    <div class="mb-4">
                        <div class="image-section-label">
                            <i class="fas fa-image"></i>Current Image
                        </div>
                        <div class="current-image-wrap">
                            <img src="<?= $service['image_path'] ?>" alt="Current service image" width="200">
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label">
                            <i class="fas fa-arrows-rotate"></i>Update Image <span style="font-weight:400;color:var(--st300)">(Optional)</span>
                        </label>
                        <div class="file-input-wrapper">
                            <div class="file-input-custom">
                                <i class="fas fa-cloud-arrow-up"></i>
                                <div class="file-input-text">Choose New Image</div>
                                <div class="file-input-hint">Click to upload or drag and drop</div>
                                <div class="file-input-hint">PNG, JPG, JPEG — max 5 MB</div>
                            </div>
                            <input type="file" name="image" accept="image/*">
                        </div>
                        <div id="fileName"></div>
                    </div>

                    <div class="divider"></div>

                    <div class="btn-row">
                        <a href="manage_services.php" class="btn-back">
                            <i class="fas fa-arrow-left"></i>Back to Services
                        </a>
                        <button type="submit" class="btn-save">
                            <i class="fas fa-check"></i><span>Update Service</span>
                        </button>
                    </div>

                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.querySelector('input[name="image"]').addEventListener('change', function() {
            const fileName = this.files[0] ? this.files[0].name : '';
            document.getElementById('fileName').textContent = fileName ? `Selected: ${fileName}` : '';
            const fc = document.querySelector('.file-input-custom');
            if (this.files[0]) {
                fc.style.borderColor = 'var(--s400)';
                fc.style.background  = 'var(--green-bg)';
            }
        });

        document.getElementById('serviceForm').addEventListener('submit', function(e) {
            const title       = document.querySelector('input[name="title"]').value;
            const category    = document.querySelector('select[name="category"]').value;
            const description = document.querySelector('textarea[name="description"]').value;
            const image       = document.querySelector('input[name="image"]').files[0];

            if (title.trim().length < 2)       { e.preventDefault(); alert('Please enter a valid service title.'); return; }
            if (!category)                      { e.preventDefault(); alert('Please select a service category.'); return; }
            if (description.trim().length < 10) { e.preventDefault(); alert('Please enter a detailed description (at least 10 characters).'); return; }
            if (image && image.size > 5 * 1024 * 1024) { e.preventDefault(); alert('Image size must be less than 5MB.'); return; }

            const btn = this.querySelector('button[type="submit"]');
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i><span>Updating Service…</span>';
            btn.disabled = true;
        });

        document.addEventListener('DOMContentLoaded', function() {
            document.querySelector('input[name="title"]').focus();
        });

        const fileInput       = document.querySelector('input[name="image"]');
        const fileInputCustom = document.querySelector('.file-input-custom');

        ['dragenter','dragover','dragleave','drop'].forEach(ev =>
            fileInputCustom.addEventListener(ev, e => { e.preventDefault(); e.stopPropagation(); })
        );
        ['dragenter','dragover'].forEach(ev =>
            fileInputCustom.addEventListener(ev, () => {
                fileInputCustom.style.borderColor = 'var(--s400)';
                fileInputCustom.style.background  = 'var(--w100)';
            })
        );
        ['dragleave','drop'].forEach(ev =>
            fileInputCustom.addEventListener(ev, () => {
                fileInputCustom.style.borderColor = '';
                fileInputCustom.style.background  = '';
            })
        );
        fileInputCustom.addEventListener('drop', function(e) {
            fileInput.files = e.dataTransfer.files;
            fileInput.dispatchEvent(new Event('change'));
        });
    </script>
</body>
</html>