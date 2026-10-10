<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ExamBD — Free MCQ Examination Portal</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Hind+Siliguri:wght@400;600&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    
    <style>
        :root {
            --primary: #4f46e5;
            --primary-dark: #4338ca;
            --slate-50: #f8fafc;
            --slate-200: #e2e8f0;
            --slate-700: #334155;
            --slate-900: #0f172a;
            --shadow-xl: 0 20px 25px -5px rgb(0 0 0 / 0.1), 0 8px 10px -6px rgb(0 0 0 / 0.1);
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Inter', 'Hind Siliguri', sans-serif; background-color: var(--slate-50); color: var(--slate-900); min-height: 100vh; display: flex; flex-direction: column; }

        /* Navbar & Corner Portal Controls */
        header {
            background: white;
            border-bottom: 1px solid var(--slate-200);
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: relative;
            z-index: 50;
        }
        .logo { font-size: 1.5rem; font-weight: 700; color: var(--primary); display: flex; align-items: center; gap: 0.5rem; text-decoration: none; }
        
        .portal-trigger-btn {
            background: var(--primary);
            color: white;
            border: none;
            padding: 0.6rem 1.2rem;
            border-radius: 0.5rem;
            font-weight: 600;
            cursor: pointer;
            transition: 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        .portal-trigger-btn:hover { background: var(--primary-dark); }

        /* Main Welcome Hero Content */
        main { flex-grow: 1; display: flex; align-items: center; justify-content: center; text-align: center; padding: 2rem; }
        .hero-card { max-width: 650px; }
        .hero-card h1 { font-size: 3rem; font-weight: 800; letter-spacing: -0.05em; margin-bottom: 1rem; line-height: 1.1; }
        .hero-card p { font-size: 1.2rem; color: var(--slate-700); margin-bottom: 2rem; }

        /* Modal Overlay & Card Container */
        .modal-overlay {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(15, 23, 42, 0.4); backdrop-filter: blur(4px);
            display: flex; align-items: center; justify-content: center;
            opacity: 0; pointer-events: none; transition: opacity 0.25s ease; z-index: 100;
        }
        .modal-overlay.active { opacity: 1; pointer-events: auto; }

        .auth-card {
            background: white; border-radius: 1.25rem; width: 100%; max-width: 420px;
            padding: 2rem; box-shadow: var(--shadow-xl); border: 1px solid var(--slate-200);
            transform: scale(0.95); transition: transform 0.25s ease; position: relative;
        }
        .modal-overlay.active .auth-card { transform: scale(1); }
        .close-modal-btn { position: absolute; top: 1.25rem; right: 1.25rem; background: none; border: none; font-size: 1.25rem; cursor: pointer; color: var(--slate-700); }

        /* Tabs Switching Navigation System */
        .auth-tabs { display: flex; border-bottom: 2px solid var(--slate-200); margin-bottom: 1.5rem; gap: 1rem; }
        .auth-tab {
            flex: 1; text-align: center; padding: 0.75rem 0; font-weight: 600; font-size: 1rem;
            color: var(--slate-700); cursor: pointer; border-bottom: 2px solid transparent; margin-bottom: -2px; transition: 0.2s;
        }
        .auth-tab.active { color: var(--primary); border-bottom-color: var(--primary); }

        /* Forms Layout & Dynamic Alerts */
        .auth-form-panel { display: none; }
        .auth-form-panel.active { display: block; }
        
        .form-group { margin-bottom: 1.25rem; text-align: left; }
        .form-group label { display: block; font-size: 0.875rem; font-weight: 600; margin-bottom: 0.5rem; color: var(--slate-700); }
        .input-wrapper { position: relative; display: flex; align-items: center; }
        .input-wrapper i { position: absolute; left: 1rem; color: var(--slate-700); font-size: 1.1rem; }
        .input-wrapper input {
            width: 100%; padding: 0.75rem 1rem 0.75rem 2.7rem; border-radius: 0.5rem;
            border: 1px solid var(--slate-200); font-size: 1rem; outline: none; transition: 0.2s;
        }
        .input-wrapper input:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.15); }

        .submit-btn {
            width: 100%; background: var(--primary); color: white; border: none;
            padding: 0.75rem; border-radius: 0.5rem; font-weight: 600; font-size: 1rem;
            cursor: pointer; transition: 0.2s; margin-top: 0.5rem;
        }
        .submit-btn:hover { background: var(--primary-dark); }
        
        .alert-box { padding: 0.75rem 1rem; border-radius: 0.5rem; margin-bottom: 1rem; font-size: 0.9rem; display: none; font-weight: 500; text-align: left; }
        .alert-box.error { background: #fef2f2; color: #b91c1c; border: 1px solid #fee2e2; }
        .alert-box.success { background: #ecfdf5; color: #047857; border: 1px solid #d1fae5; }
    </style>
</head>
<body>

    <header>
        <a href="#" class="logo">
            <i class="bi bi-journal-check"></i> ExamBD
        </a>
        <button class="portal-trigger-btn" onclick="toggleModal(true)">
            <i class="bi bi-person-circle"></i> Student Portal
        </button>
    </header>

    <main>
        <div class="hero-card">
            <h1>Online MCQ Examination Portal</h1>
            <p>Access your exam tracking dashboard securely. Click the Student Portal button in the corner to register or access your ongoing test profiles instantly.</p>
            <button class="portal-trigger-btn" style="padding: 0.8rem 1.8rem; font-size: 1.1rem;" onclick="toggleModal(true)">
                Get Started Right Now
            </button>
        </div>
    </main>

    <div class="modal-overlay" id="authModal" onclick="handleOutsideClick(event)">
        <div class="auth-card">
            <button class="close-modal-btn" onclick="toggleModal(false)" title="Close Window">
                <i class="bi bi-x-lg"></i>
            </button>
            
            <div class="auth-tabs">
                <div class="auth-tab active" onclick="switchTab('login')">Login</div>
                <div class="auth-tab" onclick="switchTab('register')">Register</div>
            </div>

            <div id="alertSystem" class="alert-box"></div>

            <div id="loginPanel" class="auth-form-panel active">
                <form id="loginForm" onsubmit="executeAuthAction(event, 'login.php')">
                    <div class="form-group">
                        <label>User ID (Registered Email)</label>
                        <div class="input-wrapper">
                            <i class="bi bi-envelope"></i>
                            <input type="email" name="email" placeholder="name@example.com" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Password (Your Phone Number)</label>
                        <div class="input-wrapper">
                            <i class="bi bi-lock"></i>
                            <input type="password" name="password" placeholder="••••••••" required>
                        </div>
                    </div>
                    <button type="submit" class="submit-btn">Login to Account</button>
                </form>
            </div>

            <div id="registerPanel" class="auth-form-panel">
                <form id="registerForm" onsubmit="executeAuthAction(event, 'register.php')">
                    <div class="form-group">
                        <label>Email Address</label>
                        <div class="input-wrapper">
                            <i class="bi bi-envelope"></i>
                            <input type="email" name="email" placeholder="student@example.com" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Mobile Number (Bangladesh Format)</label>
                        <div class="input-wrapper">
                            <i class="bi bi-phone"></i>
                            <input type="tel" name="phone" placeholder="01XXXXXXXXX" pattern="01[3-9][0-9]{8}" title="Please provide a valid 11-digit Bangladeshi number" required>
                        </div>
                    </div>
                    <button type="submit" class="submit-btn">Complete Student Signup</button>
                </form>
            </div>

        </div>
    </div>

    <script>
        const modal = document.getElementById('authModal');
        const alertBox = document.getElementById('alertSystem');

        function toggleModal(show) {
            if (show) {
                modal.classList.add('active');
            } else {
                modal.classList.remove('active');
                clearAlerts();
            }
        }

        function handleOutsideClick(e) {
            if (e.target === modal) toggleModal(false);
        }

        function switchTab(target) {
            clearAlerts();
            const tabs = document.querySelectorAll('.auth-tab');
            const panels = document.querySelectorAll('.auth-form-panel');
            
            tabs[0].classList.toggle('active', target === 'login');
            tabs[1].classList.toggle('active', target === 'register');
            
            document.getElementById('loginPanel').classList.toggle('active', target === 'login');
            document.getElementById('registerPanel').classList.toggle('active', target === 'register');
        }

        function clearAlerts() {
            alertBox.style.display = 'none';
            alertBox.className = 'alert-box';
            alertBox.innerHTML = '';
        }

        function displayAlert(type, text) {
            alertBox.style.display = 'block';
            alertBox.className = `alert-box ${type}`;
            alertBox.innerHTML = (type === 'success' ? '<i class="bi bi-check-circle-fill"></i> ' : '<i class="bi bi-exclamation-triangle-fill"></i> ') + text;
        }

        async function executeAuthAction(event, targetUrl) {
            event.preventDefault();
            clearAlerts();

            const form = event.target;
            const formData = new FormData(form);

            // Basic client validation check for Bangladeshi phone mapping formats
            if (targetUrl === 'register.php') {
                const phone = formData.get('phone').replace(/\D/g, '');
                if (!/^01[3-9]\d{8}$/.test(phone)) {
                    displayAlert('error', 'Please provide a valid 11-digit Bangladesh mobile sequence.');
                    return;
                }
            }

            try {
                const response = await fetch(targetUrl, {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.status === 'success') {
                    displayAlert('success', result.message);
                    form.reset();
                    if (targetUrl === 'register.php') {
                        setTimeout(() => switchTab('login'), 2000);
                    } else {
                        // Redirect user to their exam workspace on successful authentication match
                        setTimeout(() => window.location.href = 'exam_list.php', 1500);
                    }
                } else {
                    displayAlert('error', result.message || 'An error context occurred.');
                }
            } catch (error) {
                displayAlert('error', 'System connectivity error. Please try again.');
            }
        }
    </script>
</body>
</html>