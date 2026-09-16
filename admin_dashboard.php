<?php
require 'db.php';
session_start();

// 1. Authorization Gate
if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 1) {
    header("Location: index.php");
    exit;
}

$msg = "";

$districts = ["Colombo", "Gampaha", "Kandy", "Kurunegala", "Jaffna", "Kilinochchi", "Vavuniya", "Anuradhapura", "Galle", "Matara"];
$days_of_week = ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday"];

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
    $off_dist = $pdo->query("SELECT district FROM Users WHERE user_id = " . intval($officer_id))->fetchColumn();
    $land_dist = $pdo->query("SELECT district FROM Landmarks WHERE landmark_id = " . intval($landmark_id))->fetchColumn();
    
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
            $overlap_query = "SELECT u.name FROM DutySchedules ds JOIN Users u ON ds.user_id = u.user_id 
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
            
            $insert_stmt = $pdo->prepare("INSERT INTO DutySchedules (user_id, landmark_id, day_of_week, start_time, end_time) VALUES (?, ?, ?, ?, ?)");
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
    $pdo->prepare("UPDATE Users SET is_approved = 1 WHERE user_id = ?")->execute([$_POST['target_id']]);
    $msg = "<div class='alert'>Officer account activated.</div>";
}
if (isset($_POST['reject_officer'])) {
    $pdo->prepare("DELETE FROM Users WHERE user_id = ?")->execute([$_POST['target_id']]);
    $msg = "<div class='alert'>Officer application rejected.</div>";
}

// Landmark Management...
if (isset($_POST['add_landmark'])) {
    $pdo->prepare("INSERT INTO Landmarks (district, landmark_name) VALUES (?, ?)")->execute([$_POST['district'], trim($_POST['landmark_name'])]);
    $msg = "<div class='alert'>Landmark added.</div>";
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
if (isset($_POST['admin_delete_vehicle'])) {
    $pdo->prepare("DELETE FROM Vehicles WHERE license_plate = ?")->execute([$_POST['target_plate']]);
    $msg = "<div class='alert'>Vehicle forcibly removed from the registry.</div>";
}
if (isset($_POST['delete_user'])) {
    $t_id = $_POST['target_user_id'];
    
    $pdo->prepare("DELETE FROM DutySchedules WHERE user_id = ?")->execute([$t_id]);
    $pdo->prepare("DELETE FROM Reports WHERE reporter_id = ?")->execute([$t_id]);
    $pdo->prepare("DELETE FROM Vehicles WHERE user_id = ?")->execute([$t_id]); 
    $pdo->prepare("DELETE FROM Fines WHERE issuing_officer_id = ?")->execute([$t_id]); 
    $pdo->prepare("DELETE FROM Users WHERE user_id = ?")->execute([$t_id]);
    
    $msg = "<div class='alert'>Account deleted.</div>";
}

// ==========================================
// FETCH DATA FOR UI DISPLAY
// ==========================================

$pending_officers = $pdo->query("SELECT * FROM Users WHERE role_id = 2 AND is_approved = 0")->fetchAll();
$active_officers = $pdo->query("SELECT user_id, name, district FROM Users WHERE role_id = 2 AND is_approved = 1 ORDER BY district ASC, name ASC")->fetchAll();
$all_landmarks = $pdo->query("SELECT * FROM Landmarks ORDER BY district ASC, landmark_name ASC")->fetchAll();

// Fetch Full Roster (Officers assigned to landmarks)
$schedules = $pdo->query("
    SELECT ds.*, u.name AS officer_name, u.district, l.landmark_name, l.district AS landmark_district 
    FROM DutySchedules ds 
    JOIN Users u ON ds.user_id = u.user_id 
    JOIN Landmarks l ON ds.landmark_id = l.landmark_id
    ORDER BY FIELD(ds.day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'), ds.start_time ASC
")->fetchAll();

$all_users = $pdo->query("SELECT user_id, name, email, role_id, is_approved, district FROM Users WHERE role_id IN (2, 3) ORDER BY role_id ASC, name ASC")->fetchAll();
$all_vehicles = $pdo->query("SELECT v.license_plate, v.vehicle_model, v.registered_date, u.name AS owner_name, u.email FROM Vehicles v JOIN Users u ON v.user_id = u.user_id ORDER BY v.registered_date DESC")->fetchAll();

// Prepare JSON for Javascript Dynamic Filtering
$officer_data = [];
foreach($active_officers as $o) {
    $officer_data[$o['user_id']] = $o['district'];
}
$json_officers = json_encode($officer_data);

$landmark_data = [];
foreach($all_landmarks as $l) {
    if(!isset($landmark_data[$l['district']])) $landmark_data[$l['district']] = [];
    $landmark_data[$l['district']][] = ["id" => $l['landmark_id'], "name" => $l['landmark_name']];
}
$json_landmarks = json_encode($landmark_data);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Dashboard</title>
    <style>
        body { font-family: sans-serif; margin: 20px; background: #ffffff; color: #000000; }
        .navbar { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #000; padding-bottom: 10px; margin-bottom: 20px; }
        .navbar a { color: #000; text-decoration: none; border: 1px solid #000; padding: 5px 10px; }
        .alert { border: 1px solid #000; padding: 10px; margin-bottom: 20px; font-weight: bold; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;}
        .panel { border: 1px solid #000; padding: 15px; margin-bottom: 20px;}
        h3, h4 { border-bottom: 1px solid #000; padding-bottom: 5px; margin-top: 0; }
        label { font-weight: bold; display: block; margin-top: 15px; }
        input, select { width: 100%; padding: 8px; margin: 5px 0 15px 0; border: 1px solid #000; box-sizing: border-box; }
        button { background: #eeeeee; color: #000; border: 1px solid #000; padding: 8px 15px; cursor: pointer; }
        table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        th, td { padding: 8px; border: 1px solid #000; text-align: left; }
        .badge-role { border: 1px dashed #000; padding: 2px 5px; font-size: 0.85em; }
        .flex-container { display: flex; gap: 10px; margin-bottom: 20px; }
        .flex-child { flex: 1; }
    </style>
</head>
<body>

<div class="navbar">
    <h2>Admin Master Control</h2>
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
                <td>[<?php echo htmlspecialchars($po['district'] ?? 'N/A'); ?>]</td>
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
        <h3>Official Duty Roster Management</h3>
        <p>Schedule officers for weekly patrol slots at registered district landmarks. Overlapping shifts for the same place are physically blocked by the system.</p>
        
        <div class="grid">
            <div style="border: 1px dashed #000; padding: 15px;">
                <h4 id="form_title">Assign New Duty Slot</h4>
                <form method="POST" id="dutyForm">
                    <input type="hidden" name="schedule_id" id="edit_schedule_id" value="">
                    
                    <label>Select Police Officer</label>
                    <select name="officer_id" id="sel_officer" required onchange="filterLandmarks()">
                        <option value="">-- Choose Officer --</option>
                        <?php foreach($active_officers as $off): ?>
                            <option value="<?php echo $off['user_id']; ?>">
                                <?php echo htmlspecialchars($off['name']); ?> [<?php echo htmlspecialchars($off['district']); ?>]
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
                        <?php foreach($days_of_week as $day) echo "<option value='$day'>$day</option>"; ?>
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
                    
                    <button type="submit" name="save_duty" id="btn_save" style="width: 100%;">Publish Shift</button>
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
                                <small><?php echo htmlspecialchars($s['district']); ?> Dist.</small>
                            </td>
                            <td><strong><?php echo htmlspecialchars($s['landmark_name']); ?></strong></td>
                            <td>
                                <strong><?php echo $s['day_of_week']; ?></strong><br>
                                <small><?php echo date("g:i A", strtotime($s['start_time'])) . ' - ' . date("g:i A", strtotime($s['end_time'])); ?></small>
                            </td>
                            <td>
                                <button type="button" onclick="editDuty(<?php echo $s['schedule_id']; ?>, <?php echo $s['user_id']; ?>, <?php echo $s['landmark_id']; ?>, '<?php echo $s['day_of_week']; ?>', '<?php echo $s['start_time']; ?>', '<?php echo $s['end_time']; ?>')">Edit</button>
                                
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
                    <?php foreach($districts as $d) echo "<option value='$d'>$d</option>"; ?>
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
                <tr><th>District</th><th>Landmark Town</th><th>Delete</th></tr>
                <?php if (empty($all_landmarks)): ?>
                    <tr><td colspan="3">No landmarks added yet.</td></tr>
                <?php else: ?>
                    <?php foreach($all_landmarks as $l): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($l['district']); ?></td>
                        <td><strong><?php echo htmlspecialchars($l['landmark_name']); ?></strong></td>
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
        <h3>Global Vehicle Registry</h3>
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
                            <button type="submit" name="admin_delete_vehicle">Override Delete</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
    </div>

    <div class="panel">
        <h3>Global Account Management</h3>
        <div style="overflow-x: auto;">
            <table>
                <tr><th>ID</th><th>Full Name</th><th>Email</th><th>Role</th><th>Action</th></tr>
                <?php foreach ($all_users as $u): ?>
                <tr>
                    <td>#<?php echo $u['user_id']; ?></td>
                    <td><strong><?php echo htmlspecialchars($u['name']); ?></strong></td>
                    <td><?php echo htmlspecialchars($u['email']); ?></td>
                    <td>
                        <?php if ($u['role_id'] == 2): ?>
                            <span class="badge-role">Officer</span>
                            <?php if ($u['is_approved'] == 0) echo " <small>(Pending)</small>"; ?>
                            <br><small>Dist: <?php echo htmlspecialchars($u['district'] ?? 'N/A'); ?></small>
                        <?php else: ?>
                            <span class="badge-role">Citizen</span>
                            <br><small>Dist: <?php echo htmlspecialchars($u['district'] ?? 'N/A'); ?></small>
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
            selDay.options[i].selected = (selDay.options[i].value === day_of_week);
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
        
        document.getElementById('btn_save').innerText = "Publish Shift";
        document.getElementById('btn_cancel').style.display = "none";
    }
</script>
</body>
</html>