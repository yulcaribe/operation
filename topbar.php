<?php
require_once __DIR__ . '/beta_debug.php';
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (isset($_GET['logout'])) {
    session_unset();
    session_destroy();
    header("Location: login.php");
    exit();
}

require_once 'db.php';

$user_id = $_SESSION['user_id'] ?? null;
$profile_picture = 'assets/images/users/dummypp.png';

// Profil resmi henuz veritabaninda yok; varsayilan avatar kullaniliyor.
?>

<div class="container">
    <div class="navbar-header">
        <div class="d-flex align-items-center">                             
            <div class="topbar-item">
                <h4 class="fw-bold topbar-button pe-none text-uppercase mb-0">OPS COORD.</h4>
            </div>
        </div>
        <div class="d-flex align-items-center gap-1">
            <div class="topbar-item">
                <button type="button" class="topbar-button" id="light-dark-mode">
                    <iconify-icon icon="solar:moon-bold-duotone" class="fs-24 align-middle"></iconify-icon>
                </button>
            </div>

            <div class="dropdown topbar-item">
                <a class="topbar-button" id="page-header-user-dropdown" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                    <span class="d-flex align-items-center">
                        <img class="rounded-circle" width="32" src="<?php echo $profile_picture; ?>" alt="avatar-3">
                    </span>
                </a>
                <div class="dropdown-menu">
                    <h6 class="dropdown-header">Welcome</h6>
                    <a class="dropdown-item" href="dashboard.php">
                        <i class="bx bx-home text-muted fs-18 me-1"></i><span class="align-middle">Home</span>
                    </a>
                    <a class="dropdown-item" href="profile.php">
                        <i class="bx bx-user-circle text-muted fs-18 me-1"></i><span class="align-middle">Profile</span>
                    </a>
                    <a class="dropdown-item" href="flight_history.php">
                        <i class="bx bx-history text-muted fs-18 me-1"></i><span class="align-middle">Flight History</span>
                    </a>
                    <a class="dropdown-item" href="add_flight.php"><i class="bx bx-plus-circle text-muted fs-18 me-1"></i><span class="align-middle">Yeni Uçuş</span></a>
                    
                    <div class="dropdown-divider my-1"></div>
                        <a class="dropdown-item text-danger" href="logout.php">
                        <i class="bx bx-log-out fs-18 me-1"></i><span class="align-middle">Logout</span>
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>
