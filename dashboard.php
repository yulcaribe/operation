<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$username = htmlspecialchars($_SESSION['username'] ?? '');

// Aktif uçuş kontrolü
$stmt = $conn->prepare("SELECT id FROM flights WHERE user_id = ? AND status = 'active' LIMIT 1");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$redirect_url = ($result->num_rows > 0) ? "active_flight.php" : "add_flight.php";
?>
<section class="section">
  <div class="container">
    <h1 class="section-title">Hi, <?php echo $username; ?>!</h1>
    <p class="section-description">redirecting to reletad page</p>
    <meta http-equiv="refresh" content="2;url=<?php echo $redirect_url; ?>">
  </div>
</section>

</main>
</body>
</html>
