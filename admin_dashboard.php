<?php
require 'db.php';
session_start();

// 1. Authorization Gate
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') {
    header("Location: index.php");
    exit;
}

$msg = "";

$districts = $pdo->query("SELECT district_id, district_name FROM Districts ORDER BY district_name")->fetchAll();
$days_of_week = [0 => "Sunday", 1 => "Monday", 2 => "Tuesday", 3 => "Wednesday", 4 => "Thursday", 5 => "Friday", 6 => "Saturday"];

// ==========================================
// HANDLE FORM SUBMISSIONS (POST REQUESTS)
// ==========================================

// --- NEW/MODIFY DUTY ROSTER SCHEDULING ---
if (isset($_POST['save_duty'])) {
    $schedule_id = $_POST['schedule_id']; 
    $officer_id = $_POST['officer_id'];
    $landmark_id = $_POST['landmark_id'];
    $start = $_POST['start_time'];
    $end = $_POST['end_time'];
    
    // Convert to array in case only one day is selected or if multiple are selected
    $days = isset($_POST['day_of_week']) ? (array)$_POST['day_of_week'] : [];
    
    // 1. Strict Validation: District Match
    $stmt = $pdo->prepare("SELECT district_id FROM Users WHERE user_id = ?");
    $stmt->execute([$officer_id]);
    $off_dist = (int)$stmt->fetchColumn();
    $stmt = $pdo->prepare("SELECT district_id FROM Landmarks WHERE landmark_id = ?");
    $stmt->execute([$landmark_id]);
    $land_dist = (int)$stmt->fetchColumn();
    
    if (empty($days)) {
        $msg = "<div class='alert'>ERROR: Please select at least one day.</div>";
    } elseif ($off_dist !== $land_dist) {
        $msg = "<div class='alert'>ASSIGNMENT BLOCKED: Officer belongs to <strong>$off_dist</strong> district. You cannot assign them to a place in <strong>$land_dist</strong>.</div>";
    } elseif ($start >= $end) {
        $msg = "<div class='alert'>INVALID TIME: Shift end time must be after the start time.</div>";
    } else {
        
        // 2. Strict Validation: Overlap Detection for Multiple Days
        $conflicts = [];
        foreach ($days as $day) {
            $overlap_query = "SELECT u.name FROM DutySchedules ds JOIN Users u ON ds.officer_id = u.user_id
                              WHERE ds.landmark_id = ? AND ds.day_of_week = ? 
                              AND ds.start_time < ? AND ds.end_time > ?";
            $params = [$landmark_id, $day, $end, $start];
            
            // If editing an existing schedule, exclude it from overlap checks
            if (!empty($schedule_id)) {
                $overlap_query .= " AND ds.schedule_id != ?";
                $params[] = $schedule_id;
            }
            
            $stmt = $pdo->prepare($overlap_query);
            $stmt->execute($params);
            $conflict_officer = $stmt->fetchColumn();
            
            if ($conflict_officer) {
                $conflicts[] = "<strong>$day</strong> (Assigned to $conflict_officer)";
            }
        }
        
        if (!empty($conflicts)) {
            $msg = "<div class='alert'>CONFLICT: The system blocked this assignment. Overlaps detected on:<br>" . implode("<br>", $conflicts) . "</div>";
        } else {
            // 3. Execution (Insert Multiple Rows)
            
            // If we are modifying an existing shift and they changed the day (or added more), 
            // the safest way is to delete the original single shift row and insert the new ones.
            if (!empty($schedule_id)) {
                $pdo->prepare("DELETE FROM DutySchedules WHERE schedule_id = ?")->execute([$schedule_id]);
            }
            
            $insert_stmt = $pdo->prepare("INSERT INTO DutySchedules (officer_id, landmark_id, day_of_week, start_time, end_time) VALUES (?, ?, ?, ?, ?)");
            foreach ($days as $day) {
                $insert_stmt->execute([$officer_id, $landmark_id, $day, $start, $end]);
            }
            
            $msg = "<div class='alert'>Duty shift(s) saved successfully.</div>";
        }
    }
}

// Delete Duty Shift
if (isset($_POST['delete_duty'])) {
    $pdo->prepare("DELETE FROM DutySchedules WHERE schedule_id = ?")->execute([$_POST['schedule_id']]);
    $msg = "<div class='alert'>Shift removed from the roster.</div>";
}

// Admin approvals and rejections...
if (isset($_POST['approve_officer'])) {
    $pdo->prepare("UPDATE Officers SET is_approved = 1 WHERE officer_id = ?")->execute([$_POST['target_id']]);
    $msg = "<div class='alert'>Officer account activated.</div>";
}
if (isset($_POST['reject_officer'])) {
    $pdo->prepare("DELETE FROM Users WHERE user_id = ?")->execute([$_POST['target_id']]);
    $msg = "<div class='alert'>Officer application rejected.</div>";
}

// Landmark Management...
if (isset($_POST['add_landmark'])) {
    $pdo->prepare("INSERT INTO Landmarks (district_id, landmark_name) VALUES (?, ?)")->execute([(int)$_POST['district'], trim($_POST['landmark_name'])]);
    $msg = "<div class='alert'>Landmark added.</div>";
}
if (isset($_POST['edit_landmark'])) {
    $landmark_name = trim($_POST['landmark_name']);
    if ($landmark_name !== '') {
        $pdo->prepare("UPDATE Landmarks SET landmark_name = ? WHERE landmark_id = ?")
            ->execute([$landmark_name, $_POST['landmark_id']]);
        $msg = "<div class='alert'>Landmark updated.</div>";
    }
}
if (isset($_POST['delete_landmark'])) {
    $pdo->prepare("DELETE FROM DutySchedules WHERE landmark_id = ?")->execute([$_POST['landmark_id']]);
    $pdo->prepare("DELETE FROM Landmarks WHERE landmark_id = ?")->execute([$_POST['landmark_id']]);
    $msg = "<div class='alert'>Landmark removed.</div>";
}

// Standard Deletions
if (isset($_POST['delete_report'])) {
    $pdo->prepare("DELETE FROM Reports WHERE report_id = ?")->execute([$_POST['report_id']]);
    $msg = "<div class='alert'>False report purged.</div>";
}
if (isset($_POST['update_report_status'])) {
    $report_statuses = ['Pending', 'Investigating', 'Resolved', 'Dismissed'];
    $report_id = $_POST['report_id'];
    $new_status = $_POST['status'];

    if (in_array($new_status, $report_statuses, true)) {
        $pdo->prepare("UPDATE Reports SET status = ? WHERE report_id = ?")
            ->execute([$new_status, $report_id]);
        $msg = "<div class='alert'>Hazard report status updated.</div>";
    }
}
if (isset($_POST['admin_delete_vehicle'])) {
    $pdo->prepare("DELETE FROM Vehicles WHERE license_plate = ?")->execute([$_POST['target_plate']]);
    $msg = "<div class='alert'>Vehicle forcibly removed from the registry.</div>";
}
if (isset($_POST['delete_user'])) {
    $t_id = $_POST['target_user_id'];
    
    $pdo->prepare("DELETE FROM Users WHERE user_id = ?")->execute([$t_id]);
    
    $msg = "<div class='alert'>Account deleted.</div>";
}

// ==========================================
// FETCH DATA FOR UI DISPLAY
// ==========================================

$pending_officers = $pdo->query("SELECT u.*, o.is_approved, d.district_name FROM Users u JOIN Officers o ON o.officer_id = u.user_id LEFT JOIN Districts d ON d.district_id = u.district_id WHERE u.role = 'Officer' AND o.is_approved = 0")->fetchAll();
$active_officers = $pdo->query("SELECT u.user_id, u.name, u.district_id, d.district_name FROM Users u JOIN Officers o ON o.officer_id = u.user_id LEFT JOIN Districts d ON d.district_id = u.district_id WHERE u.role = 'Officer' AND o.is_approved = 1 ORDER BY d.district_name, u.name")->fetchAll();
$all_landmarks = $pdo->query("SELECT l.*, d.district_name FROM Landmarks l JOIN Districts d ON d.district_id = l.district_id ORDER BY d.district_name, l.landmark_name")->fetchAll();

// Fetch Full Roster (Officers assigned to landmarks)
$schedules = $pdo->query("
    SELECT ds.*, u.name AS officer_name, d.district_name, l.landmark_name, l.district_id AS landmark_district
    FROM DutySchedules ds 
    JOIN Users u ON ds.officer_id = u.user_id
    JOIN Landmarks l ON ds.landmark_id = l.landmark_id
    JOIN Districts d ON d.district_id = u.district_id
    ORDER BY ds.day_of_week, ds.start_time ASC
")->fetchAll();

$all_users = $pdo->query("SELECT u.user_id, u.name, u.email, u.role, o.is_approved, d.district_name FROM Users u LEFT JOIN Officers o ON o.officer_id = u.user_id LEFT JOIN Districts d ON d.district_id = u.district_id WHERE u.role IN ('Officer', 'Citizen') ORDER BY u.role, u.name")->fetchAll();
$all_vehicles = $pdo->query("SELECT v.license_plate, v.vehicle_model, v.registered_date, u.name AS owner_name, u.email FROM Vehicles v JOIN Users u ON v.user_id = u.user_id ORDER BY v.registered_date DESC")->fetchAll();
$hazard_reports = $pdo->query("
    SELECT r.report_id, r.hazard_type, r.description, r.status, r.created_at, r.updated_at,
           d.district_name,
           reporter.name AS reporter_name, reporter.email AS reporter_email,
           investigator.name AS investigator_name
    FROM Reports r
    JOIN Districts d ON d.district_id = r.district_id
    JOIN Users reporter ON reporter.user_id = r.reporter_id
    LEFT JOIN Officers assigned_officer ON assigned_officer.officer_id = r.investigator_id
    LEFT JOIN Users investigator ON investigator.user_id = assigned_officer.officer_id
    ORDER BY FIELD(r.status, 'Pending', 'Investigating', 'Resolved', 'Dismissed'), r.created_at DESC
")->fetchAll();

// Prepare JSON for Javascript Dynamic Filtering
$officer_data = [];
foreach($active_officers as $o) {
    $officer_data[$o['user_id']] = $o['district_id'];
}
$json_officers = json_encode($officer_data);

$landmark_data = [];
foreach($all_landmarks as $l) {
    if(!isset($landmark_data[$l['district_id']])) $landmark_data[$l['district_id']] = [];
    $landmark_data[$l['district_id']][] = ["id" => $l['landmark_id'], "name" => $l['landmark_name']];
}
$json_landmarks = json_encode($landmark_data);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Dashboard</title>
    <style>
        :root { --navy: #12233f; --blue: #2563eb; --blue-dark: #1d4ed8; --bg: #f4f7fb; --card: #fff; --border: #dbe3ef; --text: #1f2937; --muted: #64748b; }
        * { box-sizing: border-box; }
        body { font-family: Inter, Segoe UI, Arial, sans-serif; margin: 0; padding: 28px; background: var(--bg); color: var(--text); }
        .navbar { display: flex; justify-content: space-between; align-items: center; background: var(--navy); color: #fff; border-radius: 14px; padding: 18px 24px; margin-bottom: 24px; box-shadow: 0 8px 20px rgba(18, 35, 63, .16); }
        .navbar h2 { margin: 0; font-size: 1.35rem; }
        .navbar a { color: #fff; text-decoration: none; background: rgba(255,255,255,.12); border: 1px solid rgba(255,255,255,.3); border-radius: 8px; padding: 9px 14px; }
        .navbar a:hover { background: rgba(255,255,255,.22); }
        .alert { background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; border-radius: 9px; padding: 12px 14px; margin-bottom: 20px; font-weight: 600; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px; }
        .panel { background: var(--card); border: 1px solid var(--border); border-radius: 14px; padding: 20px; margin-bottom: 20px; box-shadow: 0 6px 18px rgba(31, 56, 88, .06); }
        h3, h4 { color: var(--navy); border-bottom: 1px solid var(--border); padding-bottom: 10px; margin-top: 0; }
        label { color: var(--muted); font-weight: 700; display: block; margin-top: 15px; }
        input, select { width: 100%; padding: 10px 12px; margin: 5px 0 15px; border: 1px solid #cbd5e1; border-radius: 8px; background: #fff; color: var(--text); }
        button { background: var(--blue); color: #fff; border: 0; border-radius: 8px; padding: 9px 15px; cursor: pointer; font-weight: 700; }
        button:hover { background: var(--blue-dark); }
        table { width: 100%; border-collapse: separate; border-spacing: 0; overflow: hidden; margin-top: 15px; border: 1px solid var(--border); border-radius: 10px; }
        th, td { padding: 11px 12px; border-bottom: 1px solid var(--border); text-align: left; }
        th { background: #eef4ff; color: var(--navy); font-size: .85rem; text-transform: uppercase; letter-spacing: .03em; }
        tr:last-child td { border-bottom: 0; }
        tr:hover td { background: #f8fbff; }
        .badge-role { background: #dbeafe; color: #1d4ed8; border: 0; border-radius: 999px; padding: 4px 9px; font-size: .8em; font-weight: 700; }
        .status-badge { display: inline-block; border-radius: 999px; padding: 4px 9px; font-size: .78em; font-weight: 700; white-space: nowrap; }
        .status-pending { background: #fef3c7; color: #92400e; }
        .status-investigating { background: #dbeafe; color: #1d4ed8; }
        .status-resolved { background: #dcfce7; color: #166534; }
        .status-dismissed { background: #f1f5f9; color: #475569; }
        .report-description { min-width: 260px; max-width: 420px; white-space: pre-line; color: #475569; }
        .report-meta { color: var(--muted); font-size: .85rem; line-height: 1.5; }
        .flex-container { display: flex; gap: 10px; margin-bottom: 20px; }
        .flex-child { flex: 1; }
        @media (max-width: 850px) { body { padding: 16px; } .grid { grid-template-columns: 1fr; } .navbar { gap: 12px; } .panel { overflow-x: auto; } }
    </style>
</head>
<body>

<div class="navbar">
    <h2>Admin Panel</h2>
    <a href="logout.php">Logout</a>
</div>

<div>
    <?php if($msg) echo $msg; ?>

    <?php if (!empty($pending_officers)): ?>
    <div class="panel">
        <h3>Pending Officer Account Approvals</h3>
        <table>
            <tr><th>Applicant Name</th><th>Email Address</th><th>Requested District</th><th>Action</th></tr>
            <?php foreach ($pending_officers as $po): ?>
            <tr>
                <td><strong><?php echo htmlspecialchars($po['name']); ?></strong></td>
                <td><?php echo htmlspecialchars($po['email']); ?></td>
                <td><?php echo htmlspecialchars($po['district_name'] ?? 'N/A'); ?></td>
                <td>
                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="target_id" value="<?php echo $po['user_id']; ?>">
                        <button type="submit" name="approve_officer">Approve</button>
                    </form>
                    <form method="POST" style="display:inline;" onsubmit="return confirm('Reject and delete this application?');">
                        <input type="hidden" name="target_id" value="<?php echo $po['user_id']; ?>">
                        <button type="submit" name="reject_officer">Reject</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
    <?php endif; ?>

    <div class="panel">
        <h3>Officer Duty Management</h3>
        <p>Schedule officers for weekly patrol slots at registered district landmarks. Overlapping shifts for the same place are physically blocked by the system.</p>
        
        <div class="grid">
            <div style="border: 1px solid #dbe3ef; background: #f8fafc; border-radius: 10px; padding: 15px;">
                <h4 id="form_title">Assign New Duty Slot</h4>
                <form method="POST" id="dutyForm">
                    <input type="hidden" name="schedule_id" id="edit_schedule_id" value="">
                    
                    <label>Select Police Officer</label>
                    <select name="officer_id" id="sel_officer" required onchange="filterLandmarks()">
                        <option value="">-- Choose Officer --</option>
                        <?php foreach($active_officers as $off): ?>
                            <option value="<?php echo $off['user_id']; ?>">
                                <?php echo htmlspecialchars($off['name']); ?> [<?php echo htmlspecialchars($off['district_name']); ?>]
                            </option>
                        <?php endforeach; ?>
                    </select>
                    
                    <label>Assigned Place / Landmark</label>
                    <select name="landmark_id" id="sel_landmark" required>
                        <option value="">-- Select Officer First --</option>
                    </select>
                    
                    <label>Day(s) of the Week <small>(Hold CTRL or CMD to select multiple)</small></label>
                    <!-- UPDATED: Added name="[]", multiple attribute, and height for visibility -->
                    <select name="day_of_week[]" id="sel_day" multiple required style="height: 140px;">
                        <?php foreach($days_of_week as $day_number => $day_name) echo "<option value='$day_number'>$day_name</option>"; ?>
                    </select>
                    
                    <div class="flex-container">
                        <div class="flex-child">
                            <label>Start Time</label>
                            <input type="time" name="start_time" id="sel_start" required>
                        </div>
                        <div class="flex-child">
                            <label>End Time</label>
                            <input type="time" name="end_time" id="sel_end" required>
                        </div>
                    </div>
                    
                    <button type="submit" name="save_duty" id="btn_save" style="width: 100%;">Publish</button>
                    <button type="button" id="btn_cancel" onclick="cancelEdit()" style="width: 100%; margin-top: 10px; display: none;">Cancel Edit</button>
                </form>
            </div>
            
            <div style="overflow-y: auto; max-height: 520px;">
                <table style="margin-top:0;">
                    <tr><th>Officer</th><th>Location</th><th>Shift Window</th><th>Manage</th></tr>
                    <?php if (empty($schedules)): ?>
                        <tr><td colspan="4">No duty shifts scheduled.</td></tr>
                    <?php else: ?>
                        <?php foreach($schedules as $s): ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($s['officer_name']); ?></strong><br>
                                <small><?php echo htmlspecialchars($s['district_name']); ?></small>
                            </td>
                            <td><strong><?php echo htmlspecialchars($s['landmark_name']); ?></strong></td>
                            <td>
                                <strong><?php echo htmlspecialchars($days_of_week[(int)$s['day_of_week']]); ?></strong><br>
                                <small><?php echo date("g:i A", strtotime($s['start_time'])) . ' - ' . date("g:i A", strtotime($s['end_time'])); ?></small>
                            </td>
                            <td>
                                <button type="button" onclick="editDuty(<?php echo $s['schedule_id']; ?>, '<?php echo $s['officer_id']; ?>', <?php echo $s['landmark_id']; ?>, '<?php echo $s['day_of_week']; ?>', '<?php echo $s['start_time']; ?>', '<?php echo $s['end_time']; ?>')">Edit</button>
                                
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Remove this shift?');">
                                    <input type="hidden" name="schedule_id" value="<?php echo $s['schedule_id']; ?>">
                                    <button type="submit" name="delete_duty">Del</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>

    <div class="panel">
        <h3>District Landmark Management</h3>
        <p>Add specific towns or landmarks to a single district. This maps places for duty assignments and citizen reports.</p>
        
        <form method="POST" class="flex-container" style="align-items: flex-end;">
            <div style="flex:1;">
                <label>Select Target District</label>
                <select name="district" required style="margin:0;">
                    <option value="">-- District --</option>
                    <?php foreach($districts as $d) echo "<option value='{$d['district_id']}'>" . htmlspecialchars($d['district_name']) . "</option>"; ?>
                </select>
            </div>
            <div style="flex:2;">
                <label>Town/Landmark Name</label>
                <input type="text" name="landmark_name" placeholder="e.g. Murikandy" required style="margin:0;">
            </div>
            <button type="submit" name="add_landmark" style="margin:0;">Add Location</button>
        </form>

        <div style="max-height: 250px; overflow-y: auto;">
            <table style="margin-top: 0;">
                <tr><th>District</th><th>Landmark Town</th><th>Actions</th></tr>
                <?php if (empty($all_landmarks)): ?>
                    <tr><td colspan="3">No landmarks added yet.</td></tr>
                <?php else: ?>
                    <?php foreach($all_landmarks as $l): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($l['district_name']); ?></td>
                        <td>
                            <form method="POST" style="display:flex; gap:8px; margin:0;">
                                <input type="hidden" name="landmark_id" value="<?php echo $l['landmark_id']; ?>">
                                <input type="text" name="landmark_name" value="<?php echo htmlspecialchars($l['landmark_name']); ?>" required style="margin:0; min-width:180px;">
                                <button type="submit" name="edit_landmark">Save</button>
                            </form>
                        </td>
                        <td>
                            <form method="POST" style="margin:0;">
                                <input type="hidden" name="landmark_id" value="<?php echo $l['landmark_id']; ?>">
                                <button type="submit" name="delete_landmark">X</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </table>
        </div>
    </div>

    <div class="panel">
        <h3>Reported Hazards & Incident Management</h3>
        <p class="report-meta">Review every citizen report, monitor its progress, and keep the response status up to date.</p>
        <div style="overflow-x: auto;">
            <table>
                <tr>
                    <th>Hazard</th>
                    <th>Location / Details</th>
                    <th>Reporter</th>
                    <th>Status</th>
                    <th>Investigator</th>
                    <th>Reported</th>
                    <th>Actions</th>
                </tr>
                <?php if (empty($hazard_reports)): ?>
                    <tr><td colspan="7">No hazard reports have been submitted.</td></tr>
                <?php else: ?>
                    <?php foreach ($hazard_reports as $report): ?>
                    <?php $status_class = 'status-' . strtolower($report['status']); ?>
                    <?php
                    $report_landmark = 'Not specified';
                    if (preg_match('/^Location:\s*(.+?)(?:\R|$)/', $report['description'], $location_match)) {
                        $report_landmark = trim($location_match[1]);
                    }
                    $report_details = preg_replace('/^Location:\s*.+?(?:\R|$)/', '', $report['description']);
                    ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($report['hazard_type']); ?></strong></td>
                        <td class="report-description">
                        <strong><?php echo htmlspecialchars($report['district_name']); ?> District</strong><br>
                        <span class="report-meta">Landmark: <?php echo htmlspecialchars($report_landmark); ?></span><br>
                        <?php echo htmlspecialchars(trim($report_details)); ?>
                        </td>
                        <td>
                            <strong><?php echo htmlspecialchars($report['reporter_name']); ?></strong><br>
                            <small class="report-meta"><?php echo htmlspecialchars($report['reporter_email']); ?></small>
                        </td>
                        <td><span class="status-badge <?php echo $status_class; ?>"><?php echo htmlspecialchars($report['status']); ?></span></td>
                        <td><?php echo htmlspecialchars($report['investigator_name'] ?? 'Not assigned'); ?></td>
                        <td class="report-meta"><?php echo date('M d, Y', strtotime($report['created_at'])); ?><br><?php echo date('g:i A', strtotime($report['created_at'])); ?></td>
                        <td>
                            <form method="POST" style="margin-bottom: 8px;">
                                <input type="hidden" name="report_id" value="<?php echo htmlspecialchars($report['report_id']); ?>">
                                <select name="status" style="margin:0 0 6px 0; min-width: 145px;" aria-label="Update hazard status">
                                    <?php foreach (['Pending', 'Investigating', 'Resolved', 'Dismissed'] as $status): ?>
                                        <option value="<?php echo $status; ?>" <?php echo $report['status'] === $status ? 'selected' : ''; ?>><?php echo $status; ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" name="update_report_status">Update Status</button>
                            </form>
                            <form method="POST" onsubmit="return confirm('Delete this hazard report permanently?');">
                                <input type="hidden" name="report_id" value="<?php echo htmlspecialchars($report['report_id']); ?>">
                                <button type="submit" name="delete_report" style="background:#be123c;">Delete Report</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </table>
        </div>
    </div>

    <div class="panel">
        <h3>Users Vehicle Management</h3>
        <div style="overflow-x: auto;">
            <table>
                <tr><th>License Plate</th><th>Vehicle Model</th><th>Registered Owner</th><th>Date Added</th><th>Admin Action</th></tr>
                <?php foreach ($all_vehicles as $v): ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($v['license_plate']); ?></strong></td>
                    <td><?php echo htmlspecialchars($v['vehicle_model']); ?></td>
                    <td><?php echo htmlspecialchars($v['owner_name']); ?><br><small><?php echo htmlspecialchars($v['email']); ?></small></td>
                    <td><?php echo date('M d, Y', strtotime($v['registered_date'])); ?></td>
                    <td>
                        <form method="POST" onsubmit="return confirm('WARNING: Are you sure you want to delete this vehicle from the owner\'s registry?');">
                            <input type="hidden" name="target_plate" value="<?php echo htmlspecialchars($v['license_plate']); ?>">
                            <button type="submit" name="admin_delete_vehicle">Delete</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
    </div>

    <div class="panel">
        <h3>User Account Management</h3>
        <div style="overflow-x: auto;">
            <table>
                <tr><th>Full Name</th><th>Email</th><th>Role</th><th>Action</th></tr>
                <?php foreach ($all_users as $u): ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($u['name']); ?></strong></td>
                    <td><?php echo htmlspecialchars($u['email']); ?></td>
                    <td>
                        <?php if ($u['role'] === 'Officer'): ?>
                            <span class="badge-role">Officer</span>
                            <?php if (!$u['is_approved']) echo " <small>(Pending)</small>"; ?>
                            <br><small><?php echo htmlspecialchars($u['district_name'] ?? 'N/A'); ?></small>
                        <?php else: ?>
                            <span class="badge-role">Citizen</span>
                            <br><small><?php echo htmlspecialchars($u['district_name'] ?? 'N/A'); ?></small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <form method="POST" onsubmit="return confirm('WARNING: Are you sure you want to completely erase this user?');">
                            <input type="hidden" name="target_user_id" value="<?php echo $u['user_id']; ?>">
                            <button type="submit" name="delete_user">Delete User</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
    </div>
</div>

<script>
    const officerDistricts = <?php echo $json_officers; ?>;
    const dbLandmarks = <?php echo $json_landmarks; ?>;

    function filterLandmarks(selectedLandmarkId = null) {
        const offId = document.getElementById('sel_officer').value;
        const landSelect = document.getElementById('sel_landmark');
        
        landSelect.innerHTML = "<option value=''>-- Select Place --</option>";
        
        if (offId && officerDistricts[offId]) {
            const dist = officerDistricts[offId];
            if (dbLandmarks[dist] && dbLandmarks[dist].length > 0) {
                dbLandmarks[dist].forEach(function(place) {
                    let opt = document.createElement('option');
                    opt.value = place.id;
                    opt.text = place.name + " (" + dist + ")";
                    if (selectedLandmarkId && place.id == selectedLandmarkId) {
                        opt.selected = true;
                    }
                    landSelect.appendChild(opt);
                });
            } else {
                landSelect.innerHTML = "<option value=''>No landmarks added to " + dist + " yet</option>";
            }
        } else {
            landSelect.innerHTML = "<option value=''>-- Select Officer First --</option>";
        }
    }

    function editDuty(schedule_id, officer_id, landmark_id, day_of_week, start_time, end_time) {
        document.getElementById('form_title').innerText = "Modify Duty Shift";
        document.getElementById('edit_schedule_id').value = schedule_id;
        
        document.getElementById('sel_officer').value = officer_id;
        filterLandmarks(landmark_id);
        
        // Handle selecting the correct day in the new multi-select box
        let selDay = document.getElementById('sel_day');
        for(let i = 0; i < selDay.options.length; i++) {
            selDay.options[i].selected = (selDay.options[i].value === String(day_of_week));
        }
        
        document.getElementById('sel_start').value = start_time;
        document.getElementById('sel_end').value = end_time;
        
        document.getElementById('btn_save').innerText = "Update Duty Shift";
        document.getElementById('btn_cancel').style.display = "block";
        
        window.scrollTo({ top: document.getElementById('dutyForm').offsetTop - 50, behavior: 'smooth' });
    }

    function cancelEdit() {
        document.getElementById('form_title').innerText = "Assign New Duty Slot";
        document.getElementById('edit_schedule_id').value = "";
        document.getElementById('dutyForm').reset();
        filterLandmarks();
        
        document.getElementById('btn_save').innerText = "Publish";
        document.getElementById('btn_cancel').style.display = "none";
    }
</script>
</body>
</html>