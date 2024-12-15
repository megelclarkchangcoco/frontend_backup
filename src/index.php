<?php

session_start(); 

// Initialize database connection
$connection = mysqli_connect("localhost", "root", "", "abesamis_db");

if (!$connection) {
    die("Connection failed: " . mysqli_connect_error());
}

// Initialize variables
$alert = [];
$success = false;
$active_section = 'register-section';

// Handle form submission
if (isset($_POST['submit'])) {
    // Generate unique ID for admin
    $ran_id = rand(time(), 1000000000);

    // Sanitize inputs
    $firstname = mysqli_real_escape_string($connection, trim($_POST['firstname']));
    $lastname = mysqli_real_escape_string($connection, trim($_POST['lastname']));
    $email = mysqli_real_escape_string($connection, trim($_POST['email']));
    $contact = mysqli_real_escape_string($connection, trim($_POST['contact']));
    $gender = mysqli_real_escape_string($connection, trim($_POST['gender']));
    $password = mysqli_real_escape_string($connection, trim($_POST['password']));
    $cpassword = mysqli_real_escape_string($connection, trim($_POST['cpassword']));

    // Validate email
    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        // Validate image upload
        $allowed_types = ['image/jpeg', 'image/png', 'image/jpg'];
        $image = $_FILES['image']['name'];
        $image_size = $_FILES['image']['size'];
        $image_tmp_name = $_FILES['image']['tmp_name'];
        $image_type = $_FILES['image']['type'];
        $image_rename = uniqid() . "_" . basename($image);
        $image_folder = 'img/' . $image_rename;

        if (!in_array($image_type, $allowed_types)) {
            $alert[] = "Invalid image format!";
        } elseif ($image_size > 2000000) {
            $alert[] = "Image size is too large!";
        } elseif ($password !== $cpassword) {
            $alert[] = "Passwords do not match!";
        } else {
            // Check if email already exists
            $stmt = $connection->prepare("SELECT * FROM admin WHERE email = ?");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows > 0) {
                $alert[] = "User already exists!";
            } else {
                // Hash the password
                $hashed_password = password_hash($password, PASSWORD_BCRYPT);

                // Insert user data into the database
                $stmt = $connection->prepare("INSERT INTO admin(id, firstname, lastname, email, contact, password, gender, img) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("isssssss", $ran_id, $firstname, $lastname, $email, $contact, $hashed_password, $gender, $image_rename);

                if ($stmt->execute()) {
                    move_uploaded_file($image_tmp_name, $image_folder);
                    $success = true;
                } else {
                    $alert[] = "Database error: " . $connection->error;
                }
            }
        }
    } else {
        $alert[] = "$email is not a valid email!";
    }
}


   // Handle login form submission
    if (isset($_POST['login_submit'])) {
        $email = mysqli_real_escape_string($connection, trim($_POST['email']));
        $password = trim($_POST['password']);

        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            // Fetch user from the database
            $stmt = $connection->prepare("SELECT * FROM admin WHERE email = ?");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows > 0) {
                $row = $result->fetch_assoc();

                if (password_verify($password, $row['password'])) {
                    // Store user data in session
                    $_SESSION['id'] = $row['id'];                
                    $_SESSION['firstname'] = $row['firstname'];  
                    $_SESSION['lastname'] = $row['lastname'];    
                    $_SESSION['email'] = $email;                 
                    $_SESSION['img'] = $row['img'];              

                    // Combine firstname and lastname for fullname
                    $fullname = $_SESSION['firstname'] . ' ' . $_SESSION['lastname'];

                    // Set the profile image path
                    $profile_img = isset($_SESSION['img']) ? 'img/' . $_SESSION['img'] : 'icon/default_profile.png';

                    // Update user status to "Active Now"
                    $status = 'Active Now';
                    $update_stmt = $connection->prepare("UPDATE admin SET status = ? WHERE id = ?");
                    $update_stmt->bind_param("si", $status, $row['id']);
                    $update_stmt->execute();

                    // Redirect to homepage after successful login
                    header("Location: index.php#homepage-section");
                    exit;
                } else {
                    $alert[] = "Incorrect password!";
                }
            } else {
                $alert[] = "No user found with this email!";
            }
        } else {
            $alert[] = "$email is not a valid email!";
        }
    }

    // Ensure session variables exist before using them
    $fullname = isset($_SESSION['firstname']) && isset($_SESSION['lastname']) 
        ? $_SESSION['firstname'] . ' ' . $_SESSION['lastname'] 
        : 'Guest';

    $profile_img = isset($_SESSION['img']) ? 'img/' . $_SESSION['img'] : 'icon/default_profile.png'; 

    // Check if the user is logged in
    if (!isset($_SESSION['id'])) {
        $active_section = 'login-section'; // Activate the login section if not logged in
    } else {
        $active_section = 'homepage-section'; // Activate the homepage section if logged in
    }
 
    // Logout Handling
    if (isset($_GET['logout'])) {
        // Destroy the session and unset all session variables
        session_unset();
        session_destroy();
        
        // Redirect to login page after logout
        header("Location: index.php#login-section"); // Redirect to the login section
        exit;
    }

 
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['patient_id']) && isset($_POST['action'])) {
        $patient_id = $_POST['patient_id'];
        $action = $_POST['action'];
        $reason = $_POST['cancel_reason'] ?? '';

        // Log incoming data for debugging
        error_log("Received POST Data:");
        error_log("Patient ID: " . $patient_id);
        error_log("Action: " . $action);
        error_log("Reason: " . $reason);

        try {
            // Begin transaction for atomicity
            $connection->begin_transaction();

            // First, retrieve full details from appointment_request
            $stmt_select = $connection->prepare(
                "SELECT * FROM appointment_request WHERE patient_id = ?"
            );
            
            if ($stmt_select === false) {
                throw new Exception("Prepare statement failed for select: " . $connection->error);
            }

            $stmt_select->bind_param("s", $patient_id);
            $stmt_select->execute();
            $result = $stmt_select->get_result();
            
            if ($result->num_rows === 0) {
                throw new Exception("No appointment request found for patient ID: " . $patient_id);
            }
            
            $appointment_details = $result->fetch_assoc();

            // Log retrieved appointment details
            error_log("Retrieved Appointment Details:");
            error_log(print_r($appointment_details, true));

            // Prepare insert statement for appointment_list
            $stmt_insert = $connection->prepare(
                "INSERT INTO appointment_list (
                    patient_id, patient_name, payment_type, payment_status, 
                    requested_date, requested_time, date_of_request, 
                    requested_dentist, reason_for_booking, appointment_status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );

            if ($stmt_insert === false) {
                throw new Exception("Prepare statement failed for insert: " . $connection->error);
            }

            $status = ($action == 'approve') ? 'Approved' : 'Canceled';

            $stmt_insert->bind_param(
                "ssssssssss", 
                $patient_id,
                $appointment_details['patient_name'],
                $appointment_details['payment_type'],
                $appointment_details['payment_status'],
                $appointment_details['requested_date'],
                $appointment_details['requested_time'],
                $appointment_details['date_of_request'],
                $appointment_details['requested_dentist'],
                $appointment_details['reason_for_booking'],
                $status
            );

            // Execute insert
            $insert_result = $stmt_insert->execute();
            if (!$insert_result) {
                throw new Exception("Insert failed: " . $stmt_insert->error);
            }

            // Delete from appointment_request
            $stmt_delete = $connection->prepare("DELETE FROM appointment_request WHERE patient_id = ?");
            
            if ($stmt_delete === false) {
                throw new Exception("Prepare statement failed for delete: " . $connection->error);
            }

            $stmt_delete->bind_param("s", $patient_id);
            $delete_result = $stmt_delete->execute();

            if (!$delete_result) {
                throw new Exception("Delete failed: " . $stmt_delete->error);
            }

            // Commit transaction
            $connection->commit();

            // Return success response
            header('Content-Type: application/json');
            echo json_encode([
                'status' => 'success', 
                'message' => "Appointment " . ucfirst($action) . "d successfully"
            ]);
        } catch (Exception $e) {
            // Rollback transaction in case of error
            $connection->rollback();
            
            // Log the full error
            error_log("Full Error Details: " . $e->getMessage());
            error_log("Trace: " . $e->getTraceAsString());

            // Return error response
            header('Content-Type: application/json');
            http_response_code(500);
            echo json_encode([
                'status' => 'error', 
                'message' => "Error processing appointment: " . $e->getMessage()
            ]);
        }
    } else {
        // Log missing parameters
        error_log("Missing patient_id or action in POST data");
        header('Content-Type: application/json');
        http_response_code(400);
        echo json_encode([
            'status' => 'error', 
            'message' => "Missing required parameters"
        ]);
    }

    // Ensure script stops after processing
    exit();
}

?>


<!DOCTYPE html>
<html lang="en">
    <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/admin.css">
    <link rel="stylesheet" href="css/alert.css">
    <link rel="stylesheet" href="css/adminhomepage.css">
    <link rel="stylesheet" href="css/appointment_requests.css">
    <link rel="stylesheet" href="css/appoinment_listview.css">
    <link rel="stylesheet" href="css/appointmetn_calendar.css">
    <link rel="stylesheet" href="css/patient.css">
    <link rel="stylesheet" href="css/modal.css">

    <!---JS chart-->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <!--JS calendar-->
    <script src='https://cdn.jsdelivr.net/npm/fullcalendar/index.global.min.js'></script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>


    <title>Daborder's</title>

</head>
<body>
        <!-- Login Section --->
        <div id="login-section" class="section <?php echo ($active_section == 'login-section') ? 'active' : ''; ?>">
        <div class="header">
                <img src="icon/logo.png" alt="">
            </div>
            <div class="container">
                <div class="background-overlay"></div>
                <div class="form-container">
                    <form action="" method="POST">
                        <div class="tabs">
                            <a href="#" class="active" onclick="showSection('login-section')">SIGN IN</a>
                            <a href="#" onclick="showSection('register-section')">SIGN UP</a>
                        </div>
                        <h3>Sign in to your Account</h3>
                        <p class="subtitle">Book an appointment and access medical records, anytime, anywhere.</p>
                        <div class="input-group">
                            <!-- Display alert messages -->
                            <?php 
                            if (!empty($alert)) {
                                foreach ($alert as $message) {
                                    echo '<h3 class="alert">' . htmlspecialchars($message) . '</h3>';
                                }
                            }
                            ?>
                            <input type="email" name="email" placeholder="Email" class="box" required>
                            <input type="password" name="password" placeholder="Password" class="box" required>
                        </div>
                        <button type="submit" name="login_submit" class="btn">Submit</button>
                        <div class="footer-links">
                            <a href="#">Privacy Policy</a>
                            <a href="#">Terms of Use</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>


        <!-- Register Section -->
         <div id="register-section" class="section">
            <div class="header">
                <img src="icon/logo.png" alt="">
            </div>
            <div class="register-container">
                <div class="register-background-overlay"></div>
                <div class="register-form-container">
                    <form action="" method="post" enctype="multipart/form-data">
                        <div class="register-tabs">
                            <a href="#" onclick="showSection('login-section')">SIGN IN</a>
                            <a href="#" class="active" onclick="showSection('register-section')">SIGN UP</a>    
                        </div>
                        <h3>Create Account</h3>
                        <p class="register-subtitle">Book an appointment and access medical records, anytime, anywhare</p>
                        <div class="register-input-group">
                             <!-- Display error alerts -->
                             <?php if (!empty($alert)): ?>
                                <?php foreach ($alert as $message): ?>
                                    <h3 class="register-alert"><?php echo $message; ?></h3>
                                <?php endforeach; ?>
                            <?php endif; ?>

                            <!-- Success alert -->
                            <?php if ($success): ?>
                                <div class="success-alert" id="successAlert">
                                    <div class="icon"></div>
                                    <h2>Registration Successful!</h2>
                                    <p>Your account has been created!</p>
                                    <button type="button" onclick="proceedToLogin()">OK</button>
                                </div>
                            <?php endif; ?>
                            <input type="text" name="firstname" placeholder="Enter First Name" class="box" required>
                            <input type="text" name="lastname" placeholder="Enter Last Name" class="box" required>
                            <input type="email" name="email" placeholder="Enter Email" class="box" required>
                            <input type="text" name="contact" placeholder="Enter Contact Number" class="box" required>
                            <input type="password" name="password" placeholder="Enter Password" class="box" required>
                            <input type="password" name="cpassword" placeholder="Enter Confirm Passowrd" class="box" required>
                            <select name="gender" class="box" required>
                                <option value="" disabled selected>Select Gender</option>
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                            </select>
                            <input type="file" name="image" class="register-logo" accept="image/*">
                            <button type="submit" name="submit" class="register-btn">Submit</button>
                        </div>
                        <div class="register-footer-links">
                            <a href="#">Privacy Policy</a>
                            <a href="#">Terms of Use</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- admin Homepage Section -->
        <?php
            
            $active_section = isset($_SESSION['id']) ? 'homepage-section' : 'login-section';
        ?>
        <div id="homepage-section" class="section <?php echo $active_section === 'homepage-section' ? 'active' : ''; ?>">

            <div class="wrapper">
                <!-- Left Panel -->
                <div class="left_panel">
                    <img id="logo" src="icon/logo.png" alt="Logo">
                    <label><a href="#" onclick="showSection('homepage-section')"><img src="icon/dashboard_icon.png" alt="Dashboard"> Dashboard</a></label>
                    <label><a href="#" onclick="showSection('appointment-section')"><img src="icon/Appointment_icon.png" alt="Appointments"> Appointments</a></label>
                    <label><a href="#" onclick="showSection('patient-section')"><img src="icon/Patient_icon.png" alt="Patients"> Patients</a></label>
                    <label><a href="index.php?logout=true" onclick="showSection('login-section')"><img src="icon/signout_icon.png" alt="Sign Out"> Sign Out</a></label>
                </div>
    
                <!-- Right Panel -->
                <div class="right_panel">
                <div id="header">
                <div id="info">
                    <p id="fullname"><?php echo htmlspecialchars($fullname); ?></p>
                    <p id="status">Admin</p>
                </div>
                <img id="profile_icon" src="<?php echo htmlspecialchars($profile_img); ?>" alt="Profile Icon">
            </div>

                    <div class="main_content">
                        <h1>Dashboard</h1>
                        <p>October 18, 2024 &nbsp;&nbsp; 09:32:07 AM</p>
                    
                        <div class="content_wrapper">
                            <div class="stats_and_income">
                                <div class="stats">
                                    <div class="stat">
                                        <h2>₱5,310,000</h2>
                                        <p>Total Income</p>
                                    </div>
                                    <div class="stat">
                                        <h2>7</h2>
                                        <p>Number of equipment that needs restocking</p>
                                    </div>
                                    <div class="stat">
                                        <h2>39</h2>
                                        <p>Equipment available</p>
                                    </div>
                                    <div class="stat">
                                        <h2>12</h2>
                                        <p>Expired consumables</p>
                                    </div>
                                </div>
                    
                                <div class="income_distribution">
                                    <h3>Income Distribution</h3>
                                    <div id="chart-container" class="chart-contaienr">
                                        <button class="chart-button" onclick="showChart('yearly', this)">Yearly</button>
                                        <button class="chart-button" onclick="showChart('monthly', this)">Monthly</button>
                                        <button class="chart-button" onclick="showChart('weekly', this)">Weekly</button>
                                        <canvas id="incomeChart"></canvas>
                                    </div>
                                </div>
                            </div>
                    
                            <div class="kpi">
                                <h3>KEY PERFORMANCE INDICATOR</h3>
                                <div class="kpi-item">
                                    <p>156</p>
                                    <p>Registered dentists</p>
                                </div>
                                <div class="kpi-item">
                                    <p>13</p>
                                    <p>Dental assistants on duty</p>
                                </div>
                                <div class="kpi-item">
                                    <p>120</p>
                                    <p>Available staffs</p>
                                </div>
                                <div class="kpi-item">
                                    <p>12,234</p>
                                    <p>Patients</p>
                                </div>
                                <div class="kpi-item">
                                    <p>34</p>
                                    <p>Pending patients</p>
                                </div>
                                <div class="kpi-item">
                                    <p>23</p>
                                    <p>New Patient Registrations (This Month)</p>
                                </div>
                            </div>
                        </div>
                    
                        <div class="details">
                                <div class="top5_appointment_types">
                                    <h3>Top 5 Most Requested Appointment Types</h3>
                                    <p>1. Routine Check-up: 48 appointments</p>
                                    <p>2. Cleaning: 32 appointments</p>
                                    <p>3. Consultation: 20 appointments</p>
                                    <p>4. Follow-up: 15 appointments</p>
                                    <p>5. Surgical Procedure: 10 appointments</p>
                                </div>
                                <div class="request_order">
                                    <h3>Request Order</h3>
                                    <p>1. Composite Filling Kits</p>
                                    <p>2. Mouth Rinses</p>
                                    <p>3. Dental Cements</p>
                                    <p>4. Dental Bibs</p>
                                    <p>5. Latex Gloves</p>
                                    <p>6. Cotton Rolls</p>
                                </div>
                                <div class="top5_used_items">
                                    <h3>Top 5 Used Items</h3>
                                    <p>1. Latex Gloves: 540 units</p>
                                    <p>2. Cotton Rolls: 300 units</p>
                                    <p>3. Disposable Masks: 250 units</p>
                                    <p>4. Dental Bibs: 200 units</p>
                                    <p>5. Anesthetic Cartridges: 150 units</p>
                                </div>
                                <div class="usage_category">
                                    <h3>Usage by Category (Monthly):</h3>
                                    <p>Consumables: 275 usages</p>
                                    <p>Apparatus: 115 usages</p>
                                    <p>Large Equipment: 60 usages</p>
                                </div>
                            </div>
                        </div>
                    
                    </div>
                </div>
            </div>

            <!-- admin appointment page -->
            <div id="appointment-section" class="section">
                <div class="appointment_wrapper">
                    <!-- Left Panel -->
                    <div class="appointmet_left_panel">
                        <img id="logo" src="icon/logo.png" alt="Logo">
                        <label><a href="#" onclick="showSection('homepage-section')"><img src="icon/dashboard_icon.png" alt="Dashboard"> Dashboard</a></label>
                        <label><a href="#" onclick="showSection('appointment-section')"><img src="icon/Appointment_icon.png" alt="Appointments"> Appointments</a></label>
                        <label><a href="#" onclick="showSection('patient-section')"><img src="icon/Patient_icon.png" alt="Patients"> Patients</a></label>
                        <label><a href="index.php?logout=true" onclick="showSection('login-section')"><img src="icon/signout_icon.png" alt="Sign Out"> Sign Out</a></label>
                    </div>

                    <!-- Right Panel -->
                    <div class="appointment_right_panel">
                        <div id="appointment_header">
                            <div class="sub-navigation">
                                <a href="#" onclick="showRightPanelSection('request_section')">Request</a>
                                <a href="#" onclick="showRightPanelSection('list_view_section')">List View</a>
                                <a href="#" onclick="showRightPanelSection('calendar_view_section')">Calendar View</a>
                            </div>
                            <div id="appointment_info">
                                <p id="appointment_fullname"><?php echo htmlspecialchars($fullname); ?></p>
                                <p id="appointment_status">Admin</p>
                            </div>
                            <img id="profile_icon" src="<?php echo htmlspecialchars($profile_img); ?>"alt="Profile Icon">
                        </div>
                        <!-- request sectio-->
                        <div id="request_section" class="section">

                        <!-- Request sections -->
            <div class="main_content">
                <h1>Appointment Requests</h1>
                <table>
                    <thead>
                        <tr>
                            <th>Patient ID</th>
                            <th>Patient Name</th>
                            <th>Payment Type</th>
                            <th>Payment Status</th>
                            <th>Requested Date</th>
                            <th>Requested Time</th>
                            <th>Date of Request</th>
                            <th>Requested Dentist</th>
                            <th>Reason for Booking the Appointment</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        // Fetch data from appointment_request table
                        $query = "SELECT * FROM appointment_request";
                        $result = $connection->query($query);

                        if ($result->num_rows > 0) {
                            // Loop through and display each row
                            while ($row = $result->fetch_assoc()) {
                                echo '<tr>';
                                echo '<td>' . htmlspecialchars($row['patient_id']) . '</td>';
                                echo '<td>' . htmlspecialchars($row['patient_name']) . '</td>';
                                echo '<td>' . htmlspecialchars($row['payment_type']) . '</td>';
                                echo '<td>' . htmlspecialchars($row['payment_status']) . '</td>';
                                echo '<td>' . htmlspecialchars($row['requested_date']) . '</td>';
                                echo '<td>' . htmlspecialchars($row['requested_time']) . '</td>';
                                echo '<td>' . htmlspecialchars($row['date_of_request']) . '</td>';
                                echo '<td>' . htmlspecialchars($row['requested_dentist']) . '</td>';
                                echo '<td>' . htmlspecialchars($row['reason_for_booking']) . '</td>';
                                echo '<td class="actions">';
                                echo '<button class="approve" data-patient-id="' . htmlspecialchars($row['patient_id']) . '">Approve</button>';
                                echo '<button class="cancel" data-patient-id="' . htmlspecialchars($row['patient_id']) . '">Cancel</button>';
                                echo '</td>';
                                echo '</tr>';
                            }
                        } else {
                            echo '<tr><td colspan="10">No appointment requests found.</td></tr>';
                        }
                        ?>
                    </tbody>
                </table>
            </div>

            <!-- Modal for Cancel Appointment -->
            <div id="cancelModal" class="modal" style="display: none;">
                <div class="modal-content">
                    <span class="close" onclick="closeModal()">&larr; Return</span>
                    <h2>Cancel Appointment</h2>
                    <p>Reason for Cancelling</p>
                    <form id="cancelForm">
                        <label><input type="radio" name="reason" value="personal"> Personal Reasons</label><br>
                        <label><input type="radio" name="reason" value="transportation"> Transportation Issues</label><br>
                        <label><input type="radio" name="reason" value="work"> Work-related Issues</label><br>
                        <label><input type="radio" name="reason" value="change_of_mind"> Change of Mind</label><br>
                        <textarea id="additionalDetails" placeholder="Add more details..."></textarea><br>
                        <button type="button" onclick="submitCancel()">CONFIRM</button>
                    </form>
                </div>
            </div>

                        <!-- List View Sectio-->
                        <div id="list_view_section" class="section">
                            <div class="main_content">
                                <h1>Appointment List</h1>
                                <div class="search_filter">
                                    <input type="text" placeholder="Search appointments" class="search-bar">
                                    <button class="search_button">SEARCH</button>
                                    <button class="filter_button">FILTER</button>
                                </div>
                                <table class="appointments-table">
                                    <thead>
                                        <tr>
                                            <th>Patient ID</th>
                                            <th>Patient Name</th>
                                            <th>Payment Type</th>
                                            <th>Payment Status</th>
                                            <th>Requested Date</th>
                                            <th>Requested Time</th>
                                            <th>Date of Request</th>
                                            <th>Requested Dentist</th>
                                            <th>Appointment Status</th>
                                            <th>Reason for Booking</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php
                                        $query = "SELECT * FROM appointment_request";
                                        $result = $connection->query($query);

                                        if ($result->num_rows > 0) {
                                            while ($row = $result->fetch_assoc()) {
                                                echo '<tr>';
                                                echo '<td>' . htmlspecialchars($row['patient_id']) . '</td>';
                                                echo '<td>' . htmlspecialchars($row['patient_name']) . '</td>';
                                                echo '<td>' . htmlspecialchars($row['payment_type']) . '</td>';
                                                echo '<td>' . htmlspecialchars($row['payment_status']) . '</td>';
                                                echo '<td>' . htmlspecialchars($row['requested_date']) . '</td>';
                                                echo '<td>' . htmlspecialchars($row['requested_time']) . '</td>';
                                                echo '<td>' . htmlspecialchars($row['date_of_request']) . '</td>';
                                                echo '<td>' . htmlspecialchars($row['requested_dentist']) . '</td>';
                                                echo '<td>' . htmlspecialchars($row['reason_for_booking']) . '</td>';
                                                echo '<td>';
                                                echo '<form method="POST" action="process_appointment.php">';
                                                echo '<input type="hidden" name="patient_id" value="' . htmlspecialchars($row['patient_id']) . '">';
                                                echo '<button type="submit" name="action" value="cancel">Cancel</button>';
                                                echo '<button type="submit" name="action" value="approve">Approve</button>';
                                                echo '</form>';
                                                echo '</td>';
                                                echo '</tr>';
                                            }
                                        } else {
                                            echo '<tr><td colspan="10">No appointment requests found.</td></tr>';
                                        }
                                        ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                

                        <!-- Calendar View Section -->
                        <div id="calendar_view_section" class="section">
                            <h2>Calendar View</h2>
                            <div id="calendar">
                            </div>

                        </div>
                      
                    </div>
                </div>
            </div>

            <div id="patient-section" class="section">
                <div class="wrapper">
                    <!-- Left Panel -->
                    <div class="left_panel">
                        <img id="logo" src="icon/logo.png" alt="Logo">
                        <label><a href="#" onclick="showSection('homepage-section')"><img src="icon/dashboard_icon.png" alt="Dashboard"> Dashboard</a></label>
                        <label><a href="#" onclick="showSection('appointment-section')"><img src="icon/Appointment_icon.png" alt="Appointments"> Appointments</a></label>
                        <label><a href="#" onclick="showSection('patient-section')"><img src="icon/Patient_icon.png" alt="Patients"> Patients</a></label>
                        <label><a href="index.php?logout=true" onclick="showSection('login-section')"><img src="icon/signout_icon.png" alt="Sign Out"> Sign Out</a></label>
                    </div>
        
                    <!-- Right Panel -->
                    <div class="right_panel">
                        <div id="header">
                            <div id="info">
                                <p id="fullname"><?php echo htmlspecialchars($fullname); ?></p>
                                <p id="status">Admin</p>
                            </div>
                            <img id="profile_icon" src="<?php echo htmlspecialchars($profile_img); ?>" alt="Profile Icon">
                        </div>
                        <div id="content_panel">
                            <div class="search-bar">
                                <h1 style="color: hsl(22, 62%, 50%); font-size: 24px;  margin-bottom: 20px;">Patient Info</h1>
                                <div class="search_filter">
                                    <input type="text" placeholder="Search appointments" class="search-bar">
                                    <button class="search_button">SEARCH</button>
                                    <button class="filter_button">FILTER</button>
                                </div>
                            </div>  
                            <main>
                                <div class="patient-content">
                                    <table class="table table-striped">
                                      <thead>
                                        <tr>
                                          <th>Patient ID</th>
                                          <th>Patient Name</th>
                                          <th>Sex</th>
                                          <th>Age</th>
                                          <th>Contact Details</th>
                                          <th>Email</th>
                                          <th>Action</th>
                                        </tr>
                                      </thead>
                                      <tbody>
                                        <tr>
                                          <td>PAT00123</td>
                                          <td>Amanda R. Flores</td>
                                          <td>F</td>
                                          <td>34</td>
                                          <td>0930-555-0123</td>
                                          <td>amanda@gmail.com</td>
                                          <td>
                                            <div class="btn-group">
                                              <button class="btn btn-info" onclick="openModal('viewModal')">View</button>
                                              <button class="btn btn-warning" onclick="openModal('editModal')">Edit</button>
                                              <button class="btn btn-danger" onclick="openModal('deleteModal')">Delete</button>
                                            </div>
                                          </td>
                                        </tr>
                                      </tbody>
                                    </table>
                                  
                                
                                    <!-- View Modal -->
                                    <div id="viewModal" class="modal">
                                        <div class="modal-content">
                                            <div class="modal-header">
                                                <h5>View Patient</h5>
                                                <span class="close" onclick="closeModal('viewModal')">&times;</span>
                                            </div>
                                            <div class="modal-body">
                                                <div class="profile-picture">
                                                    <img id="profile-image" src="#" alt="Profile Picture" width="100" height="100">
                                                </div>
                                                <form>
                                                    <label>Patient Name:</label>
                                                    <input type="text" value="Amanda R. Flores"><br><br>
                                    
                                                    <label>Sex:</label>
                                                    <select>
                                                        <option selected>F</option>
                                                        <option>M</option>
                                                    </select><br><br>
                                    
                                                    <label>Age:</label>
                                                    <input type="number" value="34"><br><br>
                                    
                                                    <label>Contact Details:</label>
                                                    <input type="text" value="0930-555-0123"><br><br>
                                    
                                                    <label>Email:</label>
                                                    <input type="email" value="amanda@gmail.com"><br><br>
                                    
                                                    <label>Password:</label>
                                                    <input type="password" value="password123"><br>
                                                </form>
                                            </div>
                                            <div class="modal-footer">
                                                <button class="btn btn-secondary" onclick="closeModal('viewModal')">Close</button>
                                            </div>
                                        </div>
                                    </div>
                                
                                    <!-- Edit Modal -->
                                    <div id="editModal" class="modal">
                                        <div class="modal-content">
                                            <div class="modal-header">
                                                <h5>Edit Patient</h5>
                                                <span class="close" onclick="closeModal('editModal')">&times;</span>
                                            </div>
                                            <div class="modal-body">
                                                <div class="profile-picture">
                                                    <img id="profile-image" src="#" alt="Profile Picture" width="100" height="100">
                                                    <div class="file-input">
                                                        <input type="file" id="profile-file" onchange="updateProfileImage(this)" style="display: none;">
                                                        <label for="profile-file" class="btn btn-secondary">Choose File</label>
                                                    </div>
                                                </div>
                                                <form>
                                                    <label>Patient Name:</label>
                                                    <input type="text" value="Amanda R. Flores"><br><br>
                                
                                                    <label>Sex:</label>
                                                    <select>
                                                        <option selected>F</option>
                                                        <option>M</option>
                                                    </select><br><br>
                                
                                                    <label>Age:</label>
                                                    <input type="number" value="34"><br><br>
                                
                                                    <label>Contact Details:</label>
                                                    <input type="text" value="0930-555-0123"><br><br>
                                
                                                    <label>Email:</label>
                                                    <input type="email" value="amanda@gmail.com"><br><br>
                                
                                                    <label>Password:</label>
                                                    <input type="password" value="password123"><br>
                                                </form>
                                            </div>
                                            <div class="modal-footer">
                                                <button class="btn btn-secondary" onclick="closeModal('editModal')">Close</button>
                                                <button class="btn btn-primary">Save Changes</button>
                                            </div>
                                        </div>
                                    </div>
                                
                                    <!-- Delete Modal -->
                                    <div id="deleteModal" class="modal">
                                        <div class="modal-content">
                                            <div class="modal-header">
                                                <h5>Delete Patient</h5>
                                                <span class="close" onclick="closeModal('deleteModal')">&times;</span>
                                            </div>
                                            <div class="modal-body">
                                                Are you sure you want to delete this patient?
                                            </div>
                                            <div class="modal-footer">
                                                <button class="btn btn-secondary" onclick="closeModal('deleteModal')">No</button>
                                                <button class="btn btn-danger">Yes</button>
                                            </div>
                                        </div>
                                    </div>
                            </main>    
                        </div>
                    </div>    

                </div>       
            </div>
            
            
            
        </div>

        <!-- Script for  display div in one frame-->
         <script>
            // Function to display a specific section and hide others
            function showSection(sectionId) {
                document.querySelectorAll('.section').forEach(section => {
                    section.classList.remove('active');
                });
                document.getElementById(sectionId).classList.add('active');
            }

            // Function to handle the transition to the login section
            function proceedToLogin() {
                    document.getElementById('successAlert').style.display = 'none'; // hide alert message
                    showSection('login-section'); // switch to the login
                }
        </script>
        
        <!-- Script for display div in one frame (Appointment Section Specific) -->
        <script>
            // Function to display a specific section inside the right panel and hide others
            function showRightPanelSection(sectionId) {
                // Hide all sections in the right panel
                const sections = document.querySelectorAll('.appointment_right_panel .section');
                sections.forEach(section => {
                    section.style.display = 'none'; // Hide each section
                });
        
                // Show the selected section inside the right panel
                const selectedSection = document.getElementById(sectionId);
                if (selectedSection) {
                    selectedSection.style.display = 'block'; // Show the selected section
                }
            }
        
            // Initialize the sections to be hidden on page load and show the first section (Request)
            window.onload = function() {
                // function to hide all sections initially in the right panel
                const sections = document.querySelectorAll('.appointment_right_panel .section');
                sections.forEach(section => {
                    section.style.display = 'none';
                });
        
                // function to show the default section (Request section)
                const defaultSection = document.getElementById('request_section');
                if (defaultSection) {
                    defaultSection.style.display = 'block';
                }
            }
        </script>
        

        

        <!-- Script for chart-->>
        <script>
                    const ctx = document.getElementById('incomeChart').getContext('2d');
                    let incomeChart;
            
                    const data = {
                        yearly: {
                            labels: ['2018', '2019', '2020', '2021', '2022'],
                            datasets: [{
                                label: 'Yearly Income',
                                data: [50000, 55000, 60000, 65000, 70000],
                                backgroundColor: 'skyblue'
                            }]
                        },
                        monthly: {
                            labels: ['2018', '2019', '2020', '2021', '2022'],
                            datasets: [{
                                label: 'Monthly Income',
                                data: [50000 / 12, 55000 / 12, 60000 / 12, 65000 / 12, 70000 / 12],
                                backgroundColor: 'lightgreen'
                            }]
                        },
                        weekly: {
                            labels: ['2018', '2019', '2020', '2021', '2022'],
                            datasets: [{
                                label: 'Weekly Income',
                                data: [50000 / 52, 55000 / 52, 60000 / 52, 65000 / 52, 70000 / 52],
                                backgroundColor: 'lightcoral'
                            }]
                        }
                    };
            
                    function showChart(period) {
                        if (incomeChart) {
                            incomeChart.destroy();
                        }
                        incomeChart = new Chart(ctx, {
                            type: 'bar',
                            data: data[period],
                            options: {
                                responsive: true,
                                scales: {
                                    y: {
                                        beginAtZero: true
                                    }
                                }
                            }
                        });
                    }
            
                    // Show yearly chart by default
                    showChart('yearly');
                </script>

        <!-- Script for js calendar-->
        <script>
                document.addEventListener('DOMContentLoaded', function() {
                var calendarEl = document.getElementById('calendar');
                var calendar = new FullCalendar.Calendar(calendarEl, {
                    initialView: 'dayGridMonth',
                    headerToolbar: {
                        left: 'prev,next today',
                        center: 'title',
                        right: 'dayGridMonth,timeGridWeek,timeGridDay'
                    },
                    events: [
                        {
                            title: 'General dental checkup',
                            start: '2024-12-20T09:00:00',
                            end: '2024-12-20T10:00:00'
                        },
                        {
                            title: 'Appointment 2',
                            start: '2024-12-04T10:00:00',
                            end: '2024-12-04T11:00:00'
                        },
                        {
                            title: 'Appointment 3',
                            start: '2024-12-06T10:00:00',
                            end: '2024-12-06T11:00:00'
                        },
                        {
                            title: 'Appointment 4',
                            start: '2024-12-07T11:00:00',
                            end: '2024-12-07T12:00:00'
                        },
                        {
                            title: 'Appointment 5',
                            start: '2024-12-09T12:00:00',
                            end: '2024-12-09T13:00:00'
                        },
                        {
                            title: 'Appointment 6',
                            start: '2024-12-11T13:00:00',
                            end: '2024-12-11T14:00:00'
                        },
                        {
                            title: 'Appointment 7',
                            start: '2024-12-12T13:00:00',
                            end: '2024-12-12T14:00:00'
                        }
                    ]
                });
                calendar.render();
            });
        </script>
         
         <!--JS for modal-->
         <script>
            function openModal(id) {
                document.getElementById(id).style.display = "block";
            }
    
            function closeModal(id) {
                document.getElementById(id).style.display = "none";
            }
        </script>

        <!---Js for Cancel open modal -->
        <script>
            document.addEventListener('DOMContentLoaded', function() {
            // Approve and Cancel Event Listeners
            document.querySelectorAll('.approve, .cancel').forEach(button => {
                button.addEventListener('click', function() {
                    const row = button.closest('tr');
                    const patientId = row.querySelector('td').textContent.trim();
                    const action = button.classList.contains('approve') ? 'approve' : 'cancel';
                    
                    if (action === 'approve') {
                        submitAction(patientId, 'approve');
                    } else {
                        showCancelModal(patientId);
                    }
                });
            });

            // Show Cancel Modal Function
            function showCancelModal(patientId) {
                const cancelModal = document.getElementById('cancelModal');
                cancelModal.style.display = 'flex';
                cancelModal.setAttribute('data-patient-id', patientId);
            }

            // Submit Action Function
            function submitAction(patientId, action, cancelReason = '') {
                const xhr = new XMLHttpRequest();
                xhr.open('POST', 'index.php', true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.onload = function() {
                    try {
                        const response = JSON.parse(xhr.responseText);
                        if (response.status === 'success') {
                            alert(response.message);
                            location.reload();
                        } else {
                            alert("Error: " + response.message);
                            console.error(response.message);
                        }
                    } catch (e) {
                        alert("An unexpected error occurred.");
                        console.error("Response parsing error:", e);
                    }
                };
                xhr.onerror = function() {
                    alert("Network error occurred");
                };
                xhr.send('patient_id=' + encodeURIComponent(patientId) + 
                        '&action=' + encodeURIComponent(action) + 
                        '&cancel_reason=' + encodeURIComponent(cancelReason));
            }

            // Submit Cancel Function
            window.submitCancel = function() {
                const patientId = document.getElementById('cancelModal').getAttribute('data-patient-id');
                const reason = document.querySelector('input[name="reason"]:checked');
                const additionalDetails = document.getElementById('additionalDetails').value.trim();

                if (!reason) {
                    alert("Please select a reason for cancellation.");
                    return;
                }

                let cancelReason = reason.value;
                if (additionalDetails) {
                    cancelReason += ' - ' + additionalDetails;
                }

                submitAction(patientId, 'cancel', cancelReason);
                document.getElementById('cancelModal').style.display = 'none';
            }

            // Close Modal Function
            window.closeModal = function() {
                document.getElementById('cancelModal').style.display = 'none';
            }
        });
        </script>

    <script>
        //   handling cancel action (you can adapt the logic)
        const cancelButtons = document.querySelectorAll('.cancel-btn');

        cancelButtons.forEach(button => {
            button.addEventListener('click', function () {
                const patientId = this.closest('tr').querySelector('td').textContent;  // Get Patient ID from the row
                if (confirm("Are you sure you want to cancel this appointment?")) {
                    // Send the cancel request to the server
                    let xhr = new XMLHttpRequest();
                    xhr.open('POST', '', true);
                    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                    xhr.onload = function () {
                        alert(xhr.responseText);  // Show the response
                        location.reload();  // Reload the page to update the table
                    };
                    xhr.send('action=cancel&patient_id=' + patientId + '&cancel_reason=User canceled the appointment');
                }
            });
        });
    </script>
</body>
</html>