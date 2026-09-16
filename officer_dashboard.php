<?php
require 'db.php';
session_start();

date_default_timezone_set('Asia/Colombo');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Officer') {
    header("Location: index.php");
    exit;
}

$officer_id = $_SESSION['user_id'];
$msg = "";

$current_day = (int)date('w');
$current_time = date('H:i:s');

// A. Update Incident Status
if (isset($_POST['update_status'])) {
    $report_id = $_POST['report_id'];
    $new_status = $_POST['status'];
    $pdo->prepare("UPDATE Reports SET status = ?, investigator_id = ? WHERE report_id = ?")
        ->execute([$new_status, $officer_id, $report_id]);
    $msg = "<div class='alert'>Incident status updated.</div>";
}

// B. Issue Citation
if (isset($_POST['issue_ticket'])) {
    $vehicle_plate = strtoupper(trim($_POST['vehicle_plate'])); 
    $violation_id = $_POST['violation_id'];
    
    $stmt = $pdo->prepare("INSERT INTO Fines (vehicle_plate, violation_id, officer_id) VALUES (?, ?, ?)");
    $stmt->execute([$vehicle_plate, $violation_id, $officer_id]);
    $msg = "<div class='alert'>Citation attached to vehicle <strong>$vehicle_plate</strong>. If unregistered, it will link automatically when the owner makes an account.</div>";
}

// ==========================================
// FETCH DATA FOR UI
// ==========================================

// 1. Fetch Officer's Registered District
$officer_stmt = $pdo->prepare("SELECT u.district_id, d.district_name FROM Users u LEFT JOIN Districts d ON d.district_id = u.district_id WHERE u.user_id = ?");
$officer_stmt->execute([$officer_id]);
$officer = $officer_stmt->fetch();
$officer_dist = $officer['district_id'];
$officer_dist_name = $officer['district_name'];

// 2. Fetch the Weekly Landmark Duty Schedule
$schedules = $pdo->prepare("
    SELECT ds.day_of_week, ds.start_time, ds.end_time, l.landmark_name, d.district_name
    FROM DutySchedules ds 
    JOIN Landmarks l ON ds.landmark_id = l.landmark_id 
    JOIN Districts d ON d.district_id = l.district_id
    WHERE ds.officer_id = ?
    ORDER BY ds.day_of_week, ds.start_time ASC
");
$schedules->execute([$officer_id]);
$schedules = $schedules->fetchAll();

// 3. Determine if Officer is CURRENTLY on duty right now
$active_duty_stmt = $pdo->prepare("
    SELECT l.landmark_name 
    FROM DutySchedules ds 
    JOIN Landmarks l ON ds.landmark_id = l.landmark_id 
    WHERE ds.officer_id = ? AND ds.day_of_week = ? AND ds.start_time <= ? AND ds.end_time >= ?
");
$active_duty_stmt->execute([$officer_id, $current_day, $current_time, $current_time]);
$active_duty = $active_duty_stmt->fetch();

$duty_incidents = [];
$other_incidents = [];

// 4. Split Incidents into Duty Location vs Remaining District
if ($active_duty) {
    $active_landmark = $active_duty['landmark_name'];
    
    // Hazards exactly at the officer's current post
    $stmt = $pdo->prepare("SELECT r.*, d.district_name FROM Reports r JOIN Districts d ON d.district_id = r.district_id WHERE r.district_id = ? AND r.description LIKE ? AND r.status != 'Resolved' ORDER BY r.created_at DESC");
    $stmt->execute([$officer_dist, '%' . $active_landmark . '%']);
    $duty_incidents = $stmt->fetchAll();
    
    // Remaining hazards in the rest of the district
    $stmt2 = $pdo->prepare("SELECT r.*, d.district_name FROM Reports r JOIN Districts d ON d.district_id = r.district_id WHERE r.district_id = ? AND r.description NOT LIKE ? AND r.status != 'Resolved' ORDER BY r.created_at DESC");
    $stmt2->execute([$officer_dist, '%' . $active_landmark . '%']);
    $other_incidents = $stmt2->fetchAll();
} else {
    // If not on duty, all district hazards fall into the "other" category
    $stmt = $pdo->prepare("SELECT r.*, d.district_name FROM Reports r JOIN Districts d ON d.district_id = r.district_id WHERE r.district_id = ? AND r.status != 'Resolved' ORDER BY r.created_at DESC");
    $stmt->execute([$officer_dist]);
    $other_incidents = $stmt->fetchAll();
}

$violations = $pdo->query("SELECT * FROM Violations")->fetchAll();
$registered_vehicles = $pdo->query("SELECT license_plate, vehicle_model FROM Vehicles ORDER BY license_plate ASC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Officer Terminal</title>
    <style>
        body { font-family: sans-serif; margin: 20px; background: #ffffff; color: #000000; }
        .navbar { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #000; padding-bottom: 10px; margin-bottom: 20px; }
        .navbar a { color: #000; text-decoration: none; border: 1px solid #000; padding: 5px 10px; }
        .alert { border: 1px solid #000; padding: 10px; margin-bottom: 20px; font-weight: bold; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .panel { border: 1px solid #000; padding: 15px; margin-bottom: 20px; }
        h3 { border-bottom: 1px solid #000; padding-bottom: 5px; margin-top: 0; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { padding: 8px; border: 1px solid #000; text-align: left; }
        label { font-weight: bold; display: block; margin-top: 15px; }
        input, select { width: 100%; padding: 8px; margin: 5px 0; border: 1px solid #000; box-sizing: border-box; }
        button { background: #eeeeee; color: #000; border: 1px solid #000; padding: 8px; cursor: pointer; width: 100%; }
        .incident-card { border: 1px solid #000; padding: 10px; margin-bottom: 15px; }
        .status-badge { border: 1px solid #000; padding: 2px 5px; font-size: 0.85em; float: right; }
        .location-box { border: 1px dashed #000; padding: 5px; margin: 8px 0; }
        .description-box { font-style: italic; margin: 10px 0; }
        .flex-form { display: flex; gap: 10px; margin-top: 10px; }
        .flex-form select { margin: 0; width: 70%; }
        .flex-form button { margin: 0; width: 30%; }
    </style>
</head>
<body>

<div class="navbar">
    <h2>Officer Terminal: <?php echo htmlspecialchars($_SESSION['user_name']); ?></h2>
    <a href="logout.php">End Shift</a>
</div>

<div>
    <?php if($msg) echo $msg; ?>

    <div class="grid">
        <!-- LEFT COLUMN -->
        <div>
            <div class="panel">
                <h3>Issue Traffic Citation</h3>
                <form method="POST">
                    
                    <label>Vehicle License Plate (Type new or select from list)</label>
                    <input list="plate_list" name="vehicle_plate" placeholder="e.g. WP CAA-1234" required autocomplete="off">
                    <datalist id="plate_list">
                        <?php foreach($registered_vehicles as $rv): ?>
                            <option value="<?php echo htmlspecialchars($rv['license_plate']); ?>">
                                <?php echo htmlspecialchars($rv['vehicle_model']); ?>
                            </option>
                        <?php endforeach; ?>
                    </datalist>
                    
                    <label>Violation Type</label>
                    <select name="violation_id" required>
                        <option value="">-- Select Offense --</option>
                        <?php foreach($violations as $v): ?>
                            <option value="<?php echo $v['violation_id']; ?>">
                                <?php echo htmlspecialchars($v['violation_name']); ?> (Rs. <?php echo number_format($v['fine_amount'], 2); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    
                    <button type="submit" name="issue_ticket">Issue Official Ticket</button>
                </form>
            </div>

            <div class="panel">
                <h3>My Assigned Weekly Duty Roster</h3>
                <p>Jurisdiction: <strong><?php echo htmlspecialchars($officer_dist); ?> District</strong></p>
                <?php if (empty($schedules)): ?>
                    <p>No upcoming shifts assigned by Admin.</p>
                <?php else: ?>
                    <table>
                        <tr><th>Day</th><th>Time Window</th><th>Duty Location</th></tr>
                        <?php foreach($schedules as $shift): ?>
                        <tr>
                            <td><strong><?php echo date('l', strtotime('Sunday +' . (int)$shift['day_of_week'] . ' days')); ?></strong></td>
                            <td>
                                <?php echo date("g:i A", strtotime($shift['start_time'])); ?> - <br>
                                <?php echo date("g:i A", strtotime($shift['end_time'])); ?>
                            </td>
                            <td>
                                <strong><?php echo htmlspecialchars($shift['landmark_name']); ?></strong><br>
                                <small><?php echo htmlspecialchars($shift['district_name']); ?></small>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <!-- RIGHT COLUMN -->
        <div>
            <!-- ACTIVE POST HAZARDS -->
            <div class="panel">
                <h3>Active Post Emergencies</h3>
                
                <?php if ($active_duty): ?>
                    <div style="margin-bottom: 15px; border: 1px solid #000; padding: 5px;">
                        <strong>ON DUTY:</strong> <?php echo htmlspecialchars($active_duty['landmark_name']); ?>
                    </div>
                    
                    <?php if (empty($duty_incidents)): ?>
                        <p>Your current post is clear.</p>
                    <?php else: ?>
                        <?php foreach($duty_incidents as $inc): ?>
                            <div class="incident-card">
                                <strong><?php echo htmlspecialchars($inc['hazard_type']); ?></strong> 
                                <span class="status-badge">
                                    <?php echo $inc['status']; ?>
                                </span><br>
                                
                                <div class="location-box">
                                    District: <?php echo htmlspecialchars($inc['district_name']); ?>
                                </div>

                                <div class="description-box">
                                    "<?php echo htmlspecialchars($inc['description']); ?>"
                                </div>
                                
                                <form method="POST" class="flex-form">
                                    <input type="hidden" name="report_id" value="<?php echo $inc['report_id']; ?>">
                                    <select name="status" required>
                                        <option value="Investigating" <?php if($inc['status'] == 'Investigating') echo 'selected'; ?>>Investigating</option>
                                        <option value="Resolved">Mark as Resolved</option>
                                    </select>
                                    <button type="submit" name="update_status">Update</button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                <?php else: ?>
                    <div style="margin-bottom: 15px; border: 1px solid #000; padding: 5px;">
                        <strong>STATUS:</strong> OFF-DUTY / PATROL
                    </div>
                    <p>You are not currently scheduled at a specific landmark.</p>
                <?php endif; ?>
            </div>

            <!-- REMAINING DISTRICT HAZARDS -->
            <div class="panel">
                <h3>Remaining District Hazards</h3>
                <p>Showing unresolved emergencies in the rest of the <strong><?php echo htmlspecialchars($officer_dist_name); ?></strong> district.</p>
                
                <?php if (empty($other_incidents)): ?>
                    <p>District clear.</p>
                <?php else: ?>
                    <?php foreach($other_incidents as $inc): ?>
                        <div class="incident-card">
                            <strong><?php echo htmlspecialchars($inc['hazard_type']); ?></strong> 
                            <span class="status-badge">
                                <?php echo $inc['status']; ?>
                            </span><br>
                            
                            <div class="location-box">
                                District: <?php echo htmlspecialchars($inc['district_name']); ?>
                            </div>

                            <div class="description-box">
                                "<?php echo htmlspecialchars($inc['description']); ?>"
                            </div>
                            
                            <form method="POST" class="flex-form">
                                <input type="hidden" name="report_id" value="<?php echo $inc['report_id']; ?>">
                                <select name="status" required>
                                    <option value="Investigating" <?php if($inc['status'] == 'Investigating') echo 'selected'; ?>>Investigating</option>
                                    <option value="Resolved">Mark as Resolved</option>
                                </select>
                                <button type="submit" name="update_status">Update</button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

        </div>
    </div>
</div>
</body>
</html>