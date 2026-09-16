<?php
require 'db.php';
session_start();

$msg = "";
$districts = $pdo->query("SELECT district_id, district_name FROM Districts ORDER BY district_name")->fetchAll();

// --- REGISTRATION LOGIC ---
if (isset($_POST['register'])) {
    $name = $_POST['name'];
    $email = $_POST['email'];
    $password = password_hash($_POST['password'], PASSWORD_BCRYPT);
    $account_type = $_POST['account_type'];
    
    $user_district = (int)$_POST['user_district'];
    
    if ($account_type === 'officer') {
        $role = 'Officer';
        $success_msg = "Application submitted! Please wait for Admin approval.";
    } else {
        $role = 'Citizen';
        $success_msg = "Registration successful! You may now log in.";
    }
    
    try {
        $pdo->beginTransaction();
        $new_user_id = $pdo->query("SELECT UUID()")->fetchColumn();
        $stmt = $pdo->prepare("INSERT INTO Users (user_id, name, email, password_hash, role, district_id) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$new_user_id, $name, $email, $password, $role, $user_district]);
        if ($role === 'Officer') {
            $pdo->prepare("INSERT INTO Officers (officer_id) VALUES (?)")->execute([$new_user_id]);
        }
        $pdo->commit();
        $msg = "<div class='alert'>$success_msg</div>";
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $msg = "<div class='alert'>Error: Email might already exist.</div>";
    }
}

// --- LOGIN LOGIC ---
if (isset($_POST['login'])) {
    $email = $_POST['email'];
    $password = $_POST['password'];
    
    $stmt = $pdo->prepare("SELECT u.*, o.is_approved FROM Users u LEFT JOIN Officers o ON o.officer_id = u.user_id WHERE u.email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    
    if ($user && password_verify($password, $user['password_hash'])) {
        if ($user['role'] === 'Officer' && !$user['is_approved']) {
            $msg = "<div class='alert'>Access Denied: Your Officer account is still pending Admin approval.</div>";
        } else {
            $_SESSION['user_id'] = $user['user_id'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['user_name'] = $user['name'];
            
            if ($user['role'] === 'Admin') header("Location: admin_dashboard.php");
            elseif ($user['role'] === 'Officer') header("Location: officer_dashboard.php");
            else header("Location: public_dashboard.php");
            exit;
        }
    } else {
        $msg = "<div class='alert'>Invalid email or password.</div>";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Traffic Management System - Gateway</title>
    <style>
        :root { --navy: #12233f; --blue: #2563eb; --blue-dark: #1d4ed8; --bg: #f4f7fb; --card: #ffffff; --border: #dbe3ef; --text: #1f2937; --muted: #64748b; --danger-bg: #fff1f2; --danger: #be123c; }
        * { box-sizing: border-box; }
        body { font-family: Inter, Segoe UI, Arial, sans-serif; background: linear-gradient(135deg, #eef4ff, #f8fafc); color: var(--text); display: flex; justify-content: center; gap: 28px; align-items: flex-start; min-height: 100vh; padding: 64px 24px; margin: 0; }
        .card { background: var(--card); border: 1px solid var(--border); border-radius: 16px; box-shadow: 0 12px 30px rgba(31, 56, 88, .10); padding: 30px; width: 360px; }
        h2 { color: var(--navy); margin: 0 0 22px; padding-bottom: 14px; border-bottom: 1px solid var(--border); font-size: 1.35rem; }
        input, select { width: 100%; padding: 11px 12px; margin: 9px 0; border: 1px solid #cbd5e1; border-radius: 8px; background: #fff; color: var(--text); outline: none; transition: border-color .2s, box-shadow .2s; }
        input:focus, select:focus { border-color: var(--blue); box-shadow: 0 0 0 3px rgba(37, 99, 235, .13); }
        button { width: 100%; padding: 11px 14px; margin-top: 8px; background: var(--blue); border: 0; border-radius: 8px; color: #fff; cursor: pointer; font-weight: 700; transition: background .2s, transform .2s; }
        button:hover { background: var(--blue-dark); transform: translateY(-1px); }
        .alert { background: var(--danger-bg); color: var(--danger); border: 1px solid #fecdd3; border-radius: 8px; padding: 11px 12px; margin-bottom: 16px; font-weight: 600; }
        .district-box { background: #f8fafc; border: 1px solid var(--border); border-radius: 10px; padding: 12px; margin-top: 10px; }
        label { color: var(--muted); font-size: .9rem; }
        @media (max-width: 800px) { body { flex-direction: column; align-items: stretch; padding: 28px 16px; } .card { width: 100%; max-width: 520px; margin: 0 auto; } }
    </style>
</head>
<body>
    <div class="card">
        <h2>Sign In</h2>
        <?php if($msg) echo $msg; ?>
        <form method="POST">
            <input type="email" name="email" placeholder="Email Address" required>
            <input type="password" name="password" placeholder="Password" required>
            <button type="submit" name="login">Secure Login</button>
        </form>
    </div>

    <div class="card">
        <h2>System Registration</h2>
        <form method="POST">
            <select name="account_type" id="account_type" required>
                <option value="public">Standard Public Account</option>
                <option value="officer">Traffic Police Officer</option>
            </select>
            
            <div id="district_box" class="district-box">
                <label style="font-weight: bold;">Select Your District</label>
                <select name="user_district" id="user_district" required>
                    <option value="">-- Select Your District --</option>
                    <?php foreach($districts as $d) echo "<option value='{$d['district_id']}'>" . htmlspecialchars($d['district_name']) . "</option>"; ?>
                </select>
            </div>

            <input type="text" name="name" placeholder="Full Name" required>
            <input type="email" name="email" placeholder="Email Address" required>
            <input type="password" name="password" placeholder="Create Password" required>
            <button type="submit" name="register">Register Account</button>
        </form>
    </div>
</body>
</html>