<?php
session_start();

// If user is already logged in, redirect to appropriate dashboard
if (isset($_SESSION['user_id'])) {
    if ($_SESSION['role'] === 'supra_admin') {
        header("Location: supra_admin_dashboard.php");
    } else {
        header("Location: dashboard.php");
    }
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SmartCare — Candaba Municipal Hospital</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            min-height: 100vh;
            padding: 20px;
            position: relative;
            background: none;
        }
        body::before {
            content: "";
            position: fixed;
            inset: 0;
            background: linear-gradient(rgba(255,255,255,0.78), rgba(255,255,255,0.78)), url('wmremove-transformed.jpeg') center center / cover no-repeat;
            filter: blur(6px) brightness(1) saturate(1.05);
            transform: scale(1.05);
            z-index: -1;
        }
        .navbar-blur {
            backdrop-filter: saturate(180%) blur(10px);
            background-color: rgba(255,255,255,0.7);
            border-bottom: 1px solid rgba(0,0,0,0.06);
        }
        .brand {
            font-weight: 700;
            letter-spacing: .3px;
            color: #dc3545 !important;
        }
        .hero {
            padding-top: 120px;
            padding-bottom: 60px;
        }
        .hero .headline {
            color: #12263f;
            font-weight: 800;
            line-height: 1.15;
        }
        .hero .subhead {
            color: #4a5568;
        }
        .pill {
            display: inline-flex;
            align-items: center;
            gap: .5rem;
            background: rgba(255,255,255,0.98);
            border: 1px solid rgba(220,53,69,0.2);
            padding: .55rem 1rem;
            border-radius: 999px;
            font-weight: 600;
            box-shadow: 0 8px 24px rgba(0,0,0,0.12), 0 0 0 6px rgba(255,255,255,0.55);
            backdrop-filter: blur(2px) saturate(120%);
        }
        .btn-primary {
            padding: 12px 20px;
            border-radius: 10px;
            font-weight: 600;
            letter-spacing: 0.3px;
            background: linear-gradient(135deg, #dc3545 0%, #ff4d4d 100%);
            border: none;
            box-shadow: 0 10px 20px rgba(220, 53, 69, 0.2);
        }
        .btn-primary:hover {
            background: linear-gradient(135deg, #ff4d4d 0%, #dc3545 100%);
        }
        .btn-outline-light {
            padding: 12px 20px;
            border-radius: 10px;
            font-weight: 600;
            backdrop-filter: blur(4px);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            line-height: 1;
        }
        .glass {
            background: rgba(255,255,255,0.9);
            border: 1px solid rgba(0,0,0,0.06);
            border-radius: 16px;
            box-shadow: 0 15px 40px rgba(0,0,0,0.12);
        }
        .feature {
            transition: transform .2s ease, box-shadow .2s ease;
        }
        .feature:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 26px rgba(0,0,0,0.08);
        }
        .icon-badge {
            width: 52px;
            height: 52px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 12px;
            background: #fff5f5;
            color: #dc3545;
            border: 1px solid rgba(220,53,69,0.15);
        }
        .stats {
            background: rgba(255,255,255,0.9);
            border: 1px solid rgba(0,0,0,0.06);
            border-radius: 16px;
        }
        .footer {
            color: #334155;
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-light navbar-blur fixed-top">
        <div class="container">
            <a class="navbar-brand brand" href="#">
                <i class="fas fa-hospital me-2"></i> Candaba Municipal Hospital
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navContent" aria-controls="navContent" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navContent">
                <ul class="navbar-nav ms-auto align-items-lg-center">
                    <li class="nav-item me-lg-2"><a class="nav-link" href="#features">Features</a></li>
                    <li class="nav-item me-lg-3"><a class="nav-link" href="#about">About</a></li>
                    <li class="nav-item me-lg-2">
                        <a class="btn btn-outline-light text-dark border-0" href="register.php"><i class="fas fa-user-plus me-2"></i>Register</a>
                    </li>
                    <li class="nav-item">
                        <a class="btn btn-primary" href="login.php"><i class="fas fa-sign-in-alt me-2"></i>Login</a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <main class="hero">
        <div class="container">
            <div class="row align-items-center g-4">
                <div class="col-lg-7">
                    <span class="pill mb-3"><i class="fas fa-shield-heart"></i> Compassionate Care, Modern Systems</span>
                    <h1 class="headline display-5 mb-3">Your Health, Our Priority</h1>
                    <p class="subhead fs-5 mb-4">
                        SmartCare powers Candaba Municipal Hospital with secure records, real-time bed tracking,
                        and seamless patient services.
                    </p>
                    <div class="d-flex flex-wrap gap-3">
                        <a href="login.php" class="btn btn-primary">
                            <i class="fas fa-right-to-bracket me-2"></i>Login to Portal
                        </a>
                        <a href="register.php" class="btn btn-outline-light text-dark border-0">
                            <i class="fas fa-user-plus me-2"></i>Create Account
                        </a>
                    </div>
                </div>
                <div class="col-lg-5">
                    <div class="glass p-4">
                        <div class="row g-3">
                            <div class="col-12">
                                <div class="d-flex align-items-center gap-3">
                                    <div class="icon-badge"><i class="fas fa-bed"></i></div>
                                    <div>
                                        <div class="fw-bold">Real-time Bed Availability</div>
                                        <small class="text-muted">Manage admissions efficiently</small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-12">
                                <div class="d-flex align-items-center gap-3">
                                    <div class="icon-badge"><i class="fas fa-notes-medical"></i></div>
                                    <div>
                                        <div class="fw-bold">Secure Patient Records</div>
                                        <small class="text-muted">Encrypted and access-controlled</small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-12">
                                <div class="d-flex align-items-center gap-3">
                                    <div class="icon-badge"><i class="fas fa-calendar-check"></i></div>
                                    <div>
                                        <div class="fw-bold">Appointments & Queues</div>
                                        <small class="text-muted">Reduce wait times and improve flow</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <section id="features" class="mt-5">
                <div class="row g-4">
                    <div class="col-md-6 col-lg-4">
                        <div class="glass p-4 feature h-100">
                            <div class="icon-badge mb-3"><i class="fas fa-file-medical"></i></div>
                            <h5 class="mb-2">Electronic Medical Records</h5>
                            <p class="text-muted mb-0">Centralized EMR for faster, safer clinical decisions.</p>
                        </div>
                    </div>
                    <div class="col-md-6 col-lg-4">
                        <div class="glass p-4 feature h-100">
                            <div class="icon-badge mb-3"><i class="fas fa-vial"></i></div>
                            <h5 class="mb-2">Diagnostics & Lab</h5>
                            <p class="text-muted mb-0">Track lab requests, results, and imaging reports.</p>
                        </div>
                    </div>
                    <div class="col-md-6 col-lg-4">
                        <div class="glass p-4 feature h-100">
                            <div class="icon-badge mb-3"><i class="fas fa-pills"></i></div>
                            <h5 class="mb-2">Pharmacy & Inventory</h5>
                            <p class="text-muted mb-0">Medication dispensing with stock monitoring.</p>
                        </div>
                    </div>
                    <div class="col-md-6 col-lg-4">
                        <div class="glass p-4 feature h-100">
                            <div class="icon-badge mb-3"><i class="fas fa-cash-register"></i></div>
                            <h5 class="mb-2">Billing & Claims</h5>
                            <p class="text-muted mb-0">Streamlined billing with audit trails.</p>
                        </div>
                    </div>
                    <div class="col-md-6 col-lg-4">
                        <div class="glass p-4 feature h-100">
                            <div class="icon-badge mb-3"><i class="fas fa-user-nurse"></i></div>
                            <h5 class="mb-2">Ward & Nurse Station</h5>
                            <p class="text-muted mb-0">Bed-side charts, vitals, and task delegation.</p>
                        </div>
                    </div>
                    <div class="col-md-6 col-lg-4">
                        <div class="glass p-4 feature h-100">
                            <div class="icon-badge mb-3"><i class="fas fa-people-arrows"></i></div>
                            <h5 class="mb-2">Patient Portal</h5>
                            <p class="text-muted mb-0">Patients can view records and appointments.</p>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </main>

    <footer class="footer mt-4 mb-3">
        <div class="container text-center">
            <div class="small">
                <i class="fas fa-location-dot me-1"></i> Candaba, Pampanga
                <span class="mx-2">•</span>
                <i class="fas fa-phone me-1"></i> (045) 123-4567
                <span class="mx-2">•</span>
                <i class="fas fa-envelope me-1"></i> info@candabahospital.ph
            </div>
            <div class="small text-muted mt-1">© <?php echo date('Y'); ?> Candaba Municipal Hospital. All rights reserved.</div>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    // Improve mobile hamburger reliability and UX
    document.addEventListener('DOMContentLoaded', function () {
        var nav = document.getElementById('navContent');
        var toggler = document.querySelector('.navbar-toggler');
        if (nav && toggler && window.bootstrap && bootstrap.Collapse) {
            toggler.addEventListener('click', function () {
                var instance = bootstrap.Collapse.getOrCreateInstance(nav);
                instance.toggle();
            });
            // Auto-close after clicking a menu item on small screens
            nav.querySelectorAll('a').forEach(function (a) {
                a.addEventListener('click', function () {
                    if (window.innerWidth < 992) {
                        var instance = bootstrap.Collapse.getOrCreateInstance(nav);
                        instance.hide();
                    }
                });
            });
        }
    });
    </script>
</body>
</html>