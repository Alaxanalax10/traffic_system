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
        body { font-family: sans-serif; background: #ffffff; color: #000000; display: flex; justify-content: center; gap: 50px; align-items: flex-start; padding-top: 100px; margin: 0; }
        .card { border: 1px solid #000; padding: 20px; width: 320px; }
        h2 { margin-top: 0; border-bottom: 1px solid #000; padding-bottom: 5px; }
        input, select { width: 100%; padding: 8px; margin: 10px 0; border: 1px solid #000; box-sizing: border-box; }
        button { width: 100%; padding: 10px; background: #eeeeee; border: 1px solid #000; color: #000; cursor: pointer; font-weight: bold; }
        .alert { border: 1px solid #000; padding: 10px; margin-bottom: 15px; font-weight: bold; }
        .district-box { border: 1px dashed #000; padding: 10px; margin-top: 10px; }
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