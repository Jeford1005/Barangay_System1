<?php
require_once __DIR__ . '/config.php';
header('Location: ' . BASE_URL . '/login.php');
exit;
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Barangay Bidduang - Public Portal</title>
    <link rel="shortcut icon" type="image/png" href="assets/img/Brgy_Bidduang.png">
    <link rel="stylesheet" href="assets/css/fontawesome.min.css">
    <link rel="stylesheet" href="assets/css/login.css">
    <style>
        .public-portal {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            background: #f5f7fa;
            padding: 2rem;
            text-align: center;
        }
        .seal-container {
            width: 160px;
            height: 160px;
            border-radius: 50%;
            background: #fff;
            border: 4px solid #1a5c38;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 2rem;
            box-shadow: 0 8px 24px rgba(26,92,56,0.15);
            font-size: 3rem;
            color: #1a5c38;
        }
        .portal-title {
            font-size: 2.5rem;
            font-weight: 700;
            color: #1a5c38;
            margin-bottom: 0.5rem;
        }
        .portal-subtitle {
            font-size: 1.25rem;
            color: #4a5568;
            margin-bottom: 2rem;
            line-height: 1.6;
        }
        .portal-buttons {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            justify-content: center;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 1rem 2rem;
            font-size: 1.125rem;
            min-height: 52px;
            border-radius: 12px;
            text-decoration: none;
            font-weight: 600;
            border: none;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        .btn-primary {
            background: #1a5c38;
            color: #fff;
        }
        .btn-primary:hover {
            background: #14472d;
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(26,92,56,0.3);
        }
        .btn-secondary {
            background: #fff;
            color: #1a5c38;
            border: 2px solid #1a5c38;
        }
        .btn-secondary:hover {
            background: #f0f9f4;
            transform: translateY(-2px);
        }
        .features {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 1.5rem;
            max-width: 900px;
            margin: 3rem auto 0;
            width: 100%;
        }
        .feature-card {
            background: #fff;
            padding: 1.5rem;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        }
        .feature-card i {
            font-size: 2rem;
            color: #1a5c38;
            margin-bottom: 0.75rem;
        }
        .feature-card h3 {
            font-size: 1.125rem;
            margin-bottom: 0.5rem;
            color: #1a5c38;
        }
        .feature-card p {
            font-size: 1rem;
            color: #4a5568;
            line-height: 1.6;
        }
    </style>
</head>
<body>
    <div class="public-portal">
        <div class="seal-container">
            <i class="fas fa-landmark"></i>
        </div>
        <h1 class="portal-title">Barangay Bidduang</h1>
        <p class="portal-subtitle">Your digital gateway to barangay services,<br>residents, and community information.</p>
        <div class="portal-buttons">
            <a href="login.php" class="btn btn-primary">
                <i class="fas fa-sign-in-alt"></i> Sign In
            </a>
            <a href="login.php#register" class="btn btn-secondary" id="portalRegisterBtn">
                <i class="fas fa-user-plus"></i> Create Account
            </a>
        </div>
        <div class="features">
            <div class="feature-card">
                <i class="fas fa-id-card"></i>
                <h3>Document Requests</h3>
                <p>Request barangay certificates and clearances online.</p>
            </div>
            <div class="feature-card">
                <i class="fas fa-calendar-check"></i>
                <h3>Appointments</h3>
                <p>Schedule visits and track your requests easily.</p>
            </div>
            <div class="feature-card">
                <i class="fas fa-bullhorn"></i>
                <h3>Announcements</h3>
                <p>Stay updated with the latest barangay news.</p>
            </div>
        </div>
    </div>
    <script src="assets/js/main.js"></script>
</body>
</html>
