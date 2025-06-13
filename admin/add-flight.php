<?php
session_start();
require_once 'db.php';

$flight_types = $conn->query("SELECT id, name FROM flight_types ORDER BY name ASC")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Add New Flight</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Add new flight entry" />
    <meta name="author" content="Larkon Admin" />
    <link rel="shortcut icon" href="assets/images/favicon.ico">
    <link href="assets/css/vendor.min.css" rel="stylesheet" type="text/css" />
    <link href="assets/css/icons.min.css" rel="stylesheet" type="text/css" />
    <link href="assets/css/app.min.css" rel="stylesheet" type="text/css" />
    <script src="assets/js/config.js"></script>
</head>
<body data-layout-mode="light">
<div class="wrapper">
    <header class="topbar"><?php include 'topbar.php'; ?></header>
    <?php include 'sidebar.php'; ?>
    <div class="page-content">
        <div class="container-xxl">
            <div class="row">
                <div class="col-lg-8 mx-auto">
                    <div class="card">
                        <div class="card-header">
                            <h4 class="card-title">Add New Flight</h4>
                        </div>
                        <div class="card-body">
                            <form action="save_flight.php" method="POST">
                                <div class="mb-3">
                                    <label class="form-label">Flight Type</label>
                                    <select name="flight_type_id" class="form-control" required>
                                        <option value="">Select Type</option>
                                        <?php foreach ($flight_types as $type): ?>
                                            <option value="<?= $type['id'] ?>"><?= htmlspecialchars($type['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">ICAO Code</label>
                                        <input type="text" name="icao_code" class="form-control">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">IATA Code</label>
                                        <input type="text" name="iata_code" class="form-control">
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Arrival Flight Number</label>
                                        <input type="text" name="arrival_flight_number" class="form-control">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Departure Flight Number</label>
                                        <input type="text" name="departure_flight_number" class="form-control">
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Arrival Destination</label>
                                        <input type="text" name="arrivaldest" class="form-control">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Departure Destination</label>
                                        <input type="text" name="departuredest" class="form-control">
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Scheduled Arrival Time</label>
                                        <input type="datetime-local" name="arrival_date_time" class="form-control">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Estimated Arrival Time</label>
                                        <input type="datetime-local" name="estimated_arrival_date_time" class="form-control">
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Scheduled Departure Time</label>
                                        <input type="datetime-local" name="departure_date_time" class="form-control">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Estimated Departure Time</label>
                                        <input type="datetime-local" name="estimated_departure_date_time" class="form-control">
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Tail Number</label>
                                        <input type="text" name="tail_number" class="form-control">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Park Position</label>
                                        <input type="text" name="parkposition" class="form-control">
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">AC Type</label>
                                    <input type="text" name="ac_type" class="form-control">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Note</label>
                                    <textarea name="note" class="form-control" rows="3"></textarea>
                                </div>
                                <div class="text-end">
                                    <button type="submit" class="btn btn-primary">Save Flight</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="assets/js/vendor.js"></script>
<script src="assets/js/app.js"></script>
</body>
</html>
