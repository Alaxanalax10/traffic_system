<?php
require 'db.php';
session_start();

$msg = "";
$districts = ["Colombo", "Gampaha", "Kandy", "Kurunegala", "Jaffna", "Kilinochchi", "Vavuniya", "Anuradhapura", "Galle", "Matara"];

// --- REGISTRATION LOGIC ---
if (isset($_POST['register'])) {
    $name = $_POST['name'];
    $email = $_POST['email'];
    $password = password_hash($_POST['password'], PASSWORD_BCRYPT);
    $account_type = $_POST['account_type'];
    
    // NEW: Capture the district (Null for public, Required for officers)
    $officer_district = ($account_type === 'officer') ? $_POST['officer_district'] : NULL;
    
    if ($account_type === 'officer') {
        $role_id = 2;
        $is_approved = 0; 
        $success_msg = "Application submitted for $officer_district district! Please wait for Admin approval.";
    } else {
        $role_id = 3;
        $is_approved = 1; 
        $success_msg = "Registration successful! You may now log in.";
    }
    
    try {
        $stmt = $pdo->prepare("INSERT INTO Users (name, email, password_hash, role_id, is_approved, officer_district) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$name, $email, $password, $role_id, $is_approved, $officer_district]);
        $msg = "<div class='alert'>$success_msg</div>";
    } catch (PDOException $e) {
        $msg = "<div class='alert'>Error: Email might already exist.</div>";
    }
}

// --- LOGIN LOGIC ---
if (isset($_POST['login'])) {
    $email = $_POST['email'];
    $password = $_POST['password'];
    
    $stmt = $pdo->prepare("SELECT * FROM Users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    
    if ($user && password_verify($password, $user['password_hash'])) {
        if ($user['is_approved'] == 0) {
            $msg = "<div class='alert'>Access Denied: Your Officer account is still pending Admin approval.</div>";
        } else {
            $_SESSION['user_id'] = $user['user_id'];
            $_SESSION['role_id'] = $user['role_id'];
            $_SESSION['user_name'] = $user['name'];
            
            if ($user['role_id'] == 1) header("Location: admin_dashboard.php");
            elseif ($user['role_id'] == 2) header("Location: officer_dashboard.php");
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
        .district-box { display: none; border: 1px dashed #000; padding: 10px; margin-top: 10px; }
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
            <select name="account_type" id="account_type" required onchange="toggleDistrictField()">
                <option value="public">Standard Public Account</option>
                <option value="officer">Traffic Police Officer</option>
            </select>
            
            <div id="district_box" class="district-box">
                <label style="font-weight: bold;">Request Duty District</label>
                <select name="officer_district" id="officer_district">
                    <option value="">-- Select Your District --</option>
                    <?php foreach($districts as $d) echo "<option value='$d'>$d</option>"; ?>
                </select>
            </div>

            <input type="text" name="name" placeholder="Full Name" required>
            <input type="email" name="email" placeholder="Email Address" required>
            <input type="password" name="password" placeholder="Create Password" required>
            <button type="submit" name="register">Register Account</button>
        </form>
    </div>

    <script>
        function toggleDistrictField() {
            var type = document.getElementById("account_type").value;
            var distBox = document.getElementById("district_box");
            var distInput = document.getElementById("officer_district");
            
            if (type === "officer") {
                distBox.style.display = "block";
                distInput.setAttribute("required", "required");
            } else {
                distBox.style.display = "none";
                distInput.removeAttribute("required");
                distInput.value = ""; // Reset
            }
        }
    </script>
</body>
</html>