<?php
// E:\PAYROLL\contact.php
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contact Administrator</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet"
        integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"
        integrity="sha384-tViUnnbYAV00FLIhhi3v/dWt3Jxw4gZQcNoSCxCIFNJVCx7/D55/wXsrNIRANwdD" crossorigin="anonymous">
    <style>
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }

    body {
        min-height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        padding: 20px;
    }

    .contact-box {
        background: #ffffff;
        border-radius: 20px;
        padding: 40px 35px;
        max-width: 440px;
        width: 100%;
        text-align: center;
        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        animation: slideUp 0.5s ease;
    }

    @keyframes slideUp {
        from {
            opacity: 0;
            transform: translateY(40px) scale(0.95);
        }

        to {
            opacity: 1;
            transform: translateY(0) scale(1);
        }
    }

    .contact-icon {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 80px;
        height: 80px;
        background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
        border-radius: 50%;
        margin-bottom: 20px;
        font-size: 36px;
        color: white;
        box-shadow: 0 8px 24px rgba(245, 158, 11, 0.3);
    }

    .contact-box h2 {
        color: #1a1a2e;
        font-weight: 700;
        font-size: 24px;
        margin-bottom: 10px;
    }

    .contact-box p {
        color: #6c757d;
        font-size: 15px;
        line-height: 1.6;
        margin-bottom: 5px;
    }

    .contact-box .sub-text {
        color: #999;
        font-size: 13px;
        margin-bottom: 20px;
    }

    .divider {
        height: 1px;
        background: #e5e7eb;
        margin: 20px 0;
    }

    .contact-info {
        text-align: left;
        background: #f8f9fa;
        border-radius: 12px;
        padding: 16px 20px;
        margin-bottom: 20px;
    }

    .contact-info p {
        margin: 6px 0;
        font-size: 14px;
        color: #333;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .contact-info p i {
        color: #764ba2;
        font-size: 16px;
        width: 20px;
        text-align: center;
    }

    .btn-back {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        border: none;
        padding: 12px 30px;
        font-weight: 600;
        font-size: 15px;
        border-radius: 12px;
        color: white;
        cursor: pointer;
        transition: all 0.3s ease;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        text-decoration: none;
    }

    .btn-back:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 25px rgba(118, 75, 162, 0.35);
        color: white;
    }

    /* Dark mode */
    @media (prefers-color-scheme: dark) {
        .contact-box {
            background: #1a1a2e;
        }

        .contact-box h2 {
            color: #ffffff;
        }

        .contact-box p {
            color: #adb5bd;
        }

        .contact-box .sub-text {
            color: #6c7a9a;
        }

        .contact-info {
            background: #2d2d44;
        }

        .contact-info p {
            color: #e5e7eb;
        }

        .divider {
            background: #3d3d5c;
        }
    }

    /* Mobile */
    @media (max-width: 576px) {
        .contact-box {
            padding: 30px 20px;
        }

        .contact-icon {
            width: 60px;
            height: 60px;
            font-size: 28px;
        }

        .contact-box h2 {
            font-size: 20px;
        }

        .contact-box p {
            font-size: 14px;
        }

        .contact-info {
            padding: 12px 16px;
        }

        .contact-info p {
            font-size: 13px;
        }

        .btn-back {
            padding: 10px 24px;
            font-size: 14px;
            width: 100%;
            justify-content: center;
        }
    }
    </style>
</head>

<body>
    <div class="contact-box">
        <div class="contact-icon">
            <i class="bi bi-person-lock"></i>
        </div>

        <h2>Contact Administrator</h2>
        <p>Registration and password reset are currently disabled.</p>
        <p class="sub-text">Please contact the system administrator for assistance.</p>

        <div class="divider"></div>

        <div class="contact-info">
            <p>
                <i class="bi bi-envelope"></i>
                <strong>Email:</strong> null
            </p>
            <p>
                <i class="bi bi-telephone"></i>
                <strong>Phone:</strong> null
            </p>
        </div>

        <a href="index.php" class="btn-back">
            <i class="bi bi-arrow-left"></i> Go Back
        </a>
    </div>
</body>

</html>