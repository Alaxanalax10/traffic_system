<?php
require 'db.php';
session_start();

date_default_timezone_set('Asia/Colombo');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Citizen') {
    header("Location: index.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$msg = "";

// ==========================================
// 1. GEOGRAPHIC PATHFINDING ENGINE
// ==========================================
function getRoutePath($start, $end) {
    $graph = [
        "Colombo" => ["Gampaha", "Galle"],
        "Gampaha" => ["Colombo", "Kandy", "Kurunegala"],
        "Kandy" => ["Gampaha"],
        "Kurunegala" => ["Gampaha", "Anuradhapura"],
        "Anuradhapura" => ["Kurunegala", "Vavuniya"],
        "Vavuniya" => ["Anuradhapura", "Kilinochchi"],
        "Kilinochchi" => ["Vavuniya", "Jaffna"],
        "Jaffna" => ["Kilinochchi"],
        "Galle" => ["Colombo", "Matara"],
        "Matara" => ["Galle"]
    ];
    
    if ($start === $end) return [$start];
    
    $queue = [[$start]];
    $visited = [$start => true];

    while (!empty($queue)) {
        $path = array_shift($queue);
        $node = end($path);

        if ($node === $end) return $path;

        foreach ($graph[$node] ?? [] as $neighbor) {
            if (!isset($visited[$neighbor])) {
                $visited[$neighbor] = true;
                $new_path = $path;
                $new_path[] = $neighbor;
                $queue[] = $new_path;
            }
        }
    }
    return [$start, $end]; 
}

// ==========================================
// 2. HANDLE FORM SUBMISSIONS
// ==========================================

if (isset($_POST['register_vehicle'])) {
    $plate = strtoupper(trim($_POST['license_plate']));
    $model = trim($_POST['vehicle_model']);
    try {
        $pdo->prepare("INSERT INTO Vehicles (user_id, license_plate, vehicle_model) VALUES (?, ?, ?)")->execute([$user_id, $plate, $model]);
        $msg = "<div class='alert'>Vehicle <strong>$plate</strong> registered.</div>";
    } catch (PDOException $e) {
        $msg = "<div class='alert'>Registration Failed: License plate already registered.</div>";
    }
}

if (isset($_POST['remove_vehicle'])) {
    $pdo->prepare("DELETE FROM Vehicles WHERE license_plate = ? AND user_id = ?")->execute([$_POST['remove_plate'], $user_id]);
    $msg = "<div class='alert'>Vehicle removed from your garage.</div>";
}

if (isset($_POST['edit_vehicle'])) {
    $vehicle_model = trim($_POST['vehicle_model']);
    if ($vehicle_model !== '') {
        $pdo->prepare("UPDATE Vehicles SET vehicle_model = ? WHERE license_plate = ? AND user_id = ?")
            ->execute([$vehicle_model, $_POST['vehicle_plate'], $user_id]);
        $msg = "<div class='alert'>Vehicle details updated.</div>";
    }
}

if (isset($_POST['pay_fine'])) {
    $fine_id = $_POST['fine_id'];
    $pdo->beginTransaction();
    $pdo->prepare("INSERT INTO Payments (fine_id, amount) SELECT fine_id, v.fine_amount FROM Fines f JOIN Violations v ON v.violation_id = f.violation_id WHERE f.fine_id = ? AND f.status = 'Unpaid'")->execute([$fine_id]);
    $pdo->prepare("UPDATE Fines SET status = 'Paid' WHERE fine_id = ? AND status = 'Unpaid'")->execute([$fine_id]);
    $pdo->commit();
    $msg = "<div class='alert'>Payment processed successfully!</div>";
}

if (isset($_POST['report_issue'])) {
    $hazard = $_POST['hazard_type']; 
    $desc = $_POST['description']; 
    $incident_district = (int)$_POST['incident_district'];
    $landmark = $_POST['specific_landmark'];
    
    $district_name = $pdo->prepare("SELECT district_name FROM Districts WHERE district_id = ?");
    $district_name->execute([$incident_district]);
    $district_name = $district_name->fetchColumn();
    $desc = "Location: " . $landmark . "\n\n" . $desc;
    $pdo->prepare("INSERT INTO Reports (reporter_id, district_id, hazard_type, description) VALUES (?, ?, ?, ?)")
        ->execute([$user_id, $incident_district, $hazard, $desc]);
    $msg = "<div class='alert'>Hazard reported successfully at <strong>" . htmlspecialchars($landmark) . ", " . htmlspecialchars($district_name) . "</strong>. Dispatchers notified.</div>";
}

// ==========================================
// 3. FETCH DATA FOR UI
// ==========================================

$incidents = [];
$calculated_route = [];

if (isset($_GET['search_route'])) {
    $from = $_GET['from'];
    $to = $_GET['to'];
    
    $calculated_route = getRoutePath($from, $to);
    $in_placeholders = str_repeat('?,', count($calculated_route) - 1) . '?';
    
    $sql = "
        SELECT r.*, d.district_name
        FROM Reports r
        JOIN Districts d ON d.district_id = r.district_id
        WHERE d.district_name IN ($in_placeholders)
        AND r.status != 'Resolved'
        ORDER BY r.created_at DESC
    ";
    
    $stmt = $pdo->prepare($sql);
    
    $params = $calculated_route;
    $stmt->execute($params);
    $incidents = $stmt->fetchAll();
}

$vehicles = $pdo->prepare("SELECT * FROM Vehicles WHERE user_id = ? ORDER BY registered_date DESC");
$vehicles->execute([$user_id]); 
$vehicles = $vehicles->fetchAll();

$my_fines = $pdo->prepare("SELECT f.*, v.violation_name, v.fine_amount FROM Fines f JOIN Violations v ON f.violation_id = v.violation_id WHERE f.vehicle_plate IN (SELECT license_plate FROM Vehicles WHERE user_id = ?) ORDER BY f.status DESC, f.issued_date DESC");
$my_fines->execute([$user_id]); 
$my_fines = $my_fines->fetchAll();

$districts = $pdo->query("SELECT district_id, district_name FROM Districts ORDER BY district_name")->fetchAll();
$district_names = array_column($districts, 'district_name');

$db_landmarks = $pdo->query("SELECT * FROM Landmarks")->fetchAll();
$js_district_matrix = [];
foreach($db_landmarks as $l) {
    $dist = $l['district_id'];
    if(!isset($js_district_matrix[$dist])) $js_district_matrix[$dist] = [];
    $js_district_matrix[$dist][] = $l['landmark_name'];
}
$json_landmarks = json_encode($js_district_matrix);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Public Dashboard</title>
    <style>
        :root { --navy: #12233f; --blue: #2563eb; --blue-dark: #1d4ed8; --bg: #f4f7fb; --card: #fff; --border: #dbe3ef; --text: #1f2937; --muted: #64748b; }
        * { box-sizing: border-box; }
        body { font-family: Inter, Segoe UI, Arial, sans-serif; margin: 0; padding: 28px; background: var(--bg); color: var(--text); }
        .navbar { display: flex; justify-content: space-between; align-items: center; background: var(--navy); color: #fff; border-radius: 14px; padding: 18px 24px; margin-bottom: 24px; box-shadow: 0 8px 20px rgba(18, 35, 63, .16); }
        .navbar h2 { margin: 0; font-size: 1.35rem; }
        .navbar a { color: #fff; text-decoration: none; background: rgba(255,255,255,.12); border: 1px solid rgba(255,255,255,.3); border-radius: 8px; padding: 9px 14px; }
        .alert { background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; border-radius: 9px; padding: 12px 14px; margin-bottom: 20px; font-weight: 600; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px; }
        .panel { background: var(--card); border: 1px solid var(--border); border-radius: 14px; padding: 20px; box-shadow: 0 6px 18px rgba(31, 56, 88, .06); }
        h3, h4 { color: var(--navy); border-bottom: 1px solid var(--border); padding-bottom: 10px; margin-top: 0; }
        label { color: var(--muted); font-weight: 700; display: block; margin-top: 10px; }
        input, select, textarea { width: 100%; padding: 10px 12px; margin: 5px 0 10px; border: 1px solid #cbd5e1; border-radius: 8px; background: #fff; }
        button { background: var(--blue); color: #fff; border: 0; border-radius: 8px; padding: 9px 15px; cursor: pointer; font-weight: 700; }
        button:hover { background: var(--blue-dark); }
        table { width: 100%; border-collapse: separate; border-spacing: 0; margin-top: 15px; border: 1px solid var(--border); border-radius: 10px; overflow: hidden; }
        th, td { padding: 10px 12px; border-bottom: 1px solid var(--border); text-align: left; }
        th { background: #eef4ff; color: var(--navy); font-size: .85rem; }
        tr:last-child td { border-bottom: 0; }
        .list-item { background: #f8fbff; border: 1px solid var(--border); border-radius: 9px; padding: 10px; margin-bottom: 10px; display: flex; justify-content: space-between; align-items: center; }
        .route-path { background: #eef4ff; color: var(--navy); border: 1px solid #cfe0fb; border-radius: 9px; padding: 11px; text-align: center; font-weight: 700; margin-bottom: 15px; }
        .incident-box { background: #f8fbff; border: 1px solid #cfe0fb; border-left: 4px solid var(--blue); border-radius: 10px; padding: 13px; margin: 10px 0; }
        .incident-details { margin-top: 5px; margin-bottom: 10px; font-style: italic; }
        .officer-box { margin-top: 10px; padding-top: 10px; border-top: 1px dashed #cbd5e1; color: var(--muted); }
        .flex-container { display: flex; gap: 20px; }
        .flex-child-1 { flex: 1; }
        .flex-child-2 { flex: 2; }
        @media (max-width: 850px) { body { padding: 16px; } .grid { grid-template-columns: 1fr; } .flex-container { flex-direction: column; } .panel { overflow-x: auto; } }
    </style>
</head>
<body>

<div class="navbar">
    <h2>Welcome, <?php echo htmlspecialchars($_SESSION['user_name']); ?></h2>
    <a href="logout.php">Logout</a>
</div>

<div>
    <?php if($msg) echo $msg; ?>

    <div class="panel" style="margin-bottom: 20px;">
        <h3>Vehicle Management and Fines</h3>
        <div class="flex-container">
            <div class="flex-child-1">
                <h4>Add Vehicle</h4>
                <form method="POST">
                    <input type="text" name="license_plate" placeholder="License Plate" required>
                    <input type="text" name="vehicle_model" placeholder="Model" required>
                    <button type="submit" name="register_vehicle" style="width:100%;">Register Plate</button>
                </form>
                
                <h4 style="margin-top: 20px;">My Garage</h4>
                <ul style="list-style: none; padding: 0;">
                    <?php foreach($vehicles as $v): ?>
                        <li class="list-item">
                            <div>
                                <strong><?php echo htmlspecialchars($v['license_plate']); ?></strong><br>
                                <form method="POST" style="display:flex; gap:8px; margin-top:8px;">
                                    <input type="hidden" name="vehicle_plate" value="<?php echo htmlspecialchars($v['license_plate']); ?>">
                                    <input type="text" name="vehicle_model" value="<?php echo htmlspecialchars($v['vehicle_model']); ?>" required aria-label="Vehicle model" style="margin:0; min-width:150px;">
                                    <button type="submit" name="edit_vehicle">Save</button>
                                </form>
                            </div>
                            <form method="POST" onsubmit="return confirm('Remove this vehicle?');" style="margin:0;">
                                <input type="hidden" name="remove_plate" value="<?php echo htmlspecialchars($v['license_plate']); ?>">
                                <button type="submit" name="remove_vehicle">Remove</button>
                            </form>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            
            <div class="flex-child-2">
                <h4>Outstanding Fines</h4>
                <?php if (empty($my_fines)): ?>
                    <p>No fines detected.</p>
                <?php else: ?>
                    <table>
                        <tr><th>Plate</th><th>Violation</th><th>Date</th><th>Amount</th><th>Status</th></tr>
                        <?php foreach($my_fines as $fine): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($fine['vehicle_plate']); ?></strong></td>
                            <td><?php echo htmlspecialchars($fine['violation_name']); ?></td>
                            <td><?php echo date('M d', strtotime($fine['issued_date'])); ?></td>
                            <td>Rs. <?php echo number_format($fine['fine_amount'], 2); ?></td>
                            <td>
                                <?php if($fine['status'] == 'Unpaid'): ?>
                                    <form method="POST">
                                        <input type="hidden" name="fine_id" value="<?php echo $fine['fine_id']; ?>">
                                        <button type="submit" name="pay_fine">Pay Now</button>
                                    </form>
                                <?php else: ?>
                                    <span>Paid</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="grid">
        
        <div class="panel">
            <h3>1. Check Highway Route Updates</h3>
            <p>Select your start and end destinations to automatically scan all districts along that path.</p>
            <form method="GET">
                <div style="display: flex; gap: 10px;">
                    <select name="from" required style="flex: 1;">
                        <option value="">-- Start District --</option>
                        <?php foreach($district_names as $d) echo "<option value='" . htmlspecialchars($d) . "'>" . htmlspecialchars($d) . "</option>"; ?>
                    </select>
                    <select name="to" required style="flex: 1;">
                        <option value="">-- End District --</option>
                        <?php foreach($district_names as $d) echo "<option value='" . htmlspecialchars($d) . "'>" . htmlspecialchars($d) . "</option>"; ?>
                    </select>
                </div>
                <button type="submit" name="search_route" style="width: 100%;">Scan Entire Route</button>
            </form>
            
            <?php if (isset($_GET['search_route'])): ?>
                <hr style="margin-top:20px; border: 0; border-top: 1px solid #dbe3ef;">
                
                <div class="route-path">
                    Scanning Path: <?php echo implode(" -> ", $calculated_route); ?>
                </div>

                <?php if (empty($incidents)): ?>
                    <p style="text-align: center;">Entire Route Clear.</p>
                <?php else: ?>
                    <?php foreach ($incidents as $inc): ?>
                        <div class="incident-box">
                            <strong><?php echo htmlspecialchars($inc['hazard_type']); ?></strong><br>
                            
                            <div>
                                <strong><?php echo htmlspecialchars($inc['district_name']); ?></strong> District
                            </div>
                            
                            <div class="incident-details">
                                "<?php echo htmlspecialchars($inc['description']); ?>"
                            </div>
                            
                            <div class="officer-box">
                                <em>Traffic officers have been notified for this district.</em>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <div class="panel">
            <h3>2. Live Road Hazard Reporting</h3>
            <form method="POST">
                <label>Hazard Category</label>
                <select name="hazard_type" required>
                    <option value="Accident">Accident</option>
                    <option value="Pothole">Pothole</option>
                    <option value="Signal Failure">Signal Failure</option>
                    <option value="Traffic Jam">Traffic Jam</option>
                    <option value="Other">Other</option>
                </select>
                
                <label>Incident District</label>
                <select name="incident_district" id="report_district" required onchange="updateLandmarks()">
                    <option value="">-- Select Incident District --</option>
                    <?php foreach($districts as $d) echo "<option value='{$d['district_id']}'>" . htmlspecialchars($d['district_name']) . "</option>"; ?>
                </select>
                
                <label>Specific Town / Landmark</label>
                <select name="specific_landmark" id="dynamic_landmark" required>
                    <option value="">-- Select District First --</option>
                </select>
                
                <label>Exact Description</label>
                <textarea name="description" rows="3" placeholder="e.g. 200m north side from town crossing..." required></textarea>
                
                <button type="submit" name="report_issue" style="width: 100%;">Add Hazard Alert</button>
            </form>
        </div>
    </div>
</div>

<script>
    const dbLandmarks = <?php echo $json_landmarks; ?>;

    function updateLandmarks() {
        const district = document.getElementById('report_district').value;
        const landmarkSelect = document.getElementById('dynamic_landmark');
        
        landmarkSelect.innerHTML = "<option value=''>-- Select Target Town --</option>";
        
        if (district) {
            if (dbLandmarks[district] && dbLandmarks[district].length > 0) {
                dbLandmarks[district].forEach(function(town) {
                    let option = document.createElement('option');
                    option.value = town;
                    option.text = town;
                    landmarkSelect.appendChild(option);
                });
                landmarkSelect.innerHTML += "<option value='Other'>Other / Unlisted Location</option>";
            } else {
                landmarkSelect.innerHTML = "<option value='Local City Limits'>Local City Limits</option>";
                landmarkSelect.innerHTML += "<option value='Other'>Other (Specify in Description)</option>";
            }
        } else {
            landmarkSelect.innerHTML = "<option value=''>-- Select District First --</option>";
        }
    }
</script>

</body>
</html>