<?php
/**
 * Operation personal-flight compatibility adapter. The existing PHP process
 * templates keep their legacy variable names and if/else button behavior.
 */
function op_process_keys(): array {
    return [
        'arrival' => ['doorsopening','gpu','unloading','deboarding','arrivalcrewcar'],
        'departure' => ['gpu','crew','fueling','catering','loading','preboarding',
                        'boarding','firstpax','doorclosed','pushback','offblock']
    ];
}
function op_current_flight(mysqli $conn, int $userId, int $flightId = 0): ?array {
    if ($flightId > 0) {
        $stmt = $conn->prepare("SELECT * FROM flights WHERE id = ? AND user_id = ? AND status = 'active' AND deleted_at IS NULL LIMIT 1");
        $stmt->bind_param("ii", $flightId, $userId);
    } else {
        $stmt = $conn->prepare("SELECT * FROM flights WHERE user_id = ? AND status = 'active' AND deleted_at IS NULL ORDER BY created_at DESC, id DESC LIMIT 1");
        $stmt->bind_param("i", $userId);
    }
    $stmt->execute();
    $flight = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $flight ?: null;
}
function op_legacy_flight(mysqli $conn, array $flight, ?string $phase = null): array {
    foreach ([
        'parkposition' => 'stand',
        'arrival_date_time' => 'scheduled_arrival_at',
        'departure_date_time' => 'scheduled_departure_at',
        'arrivaldest' => 'arrival_destination',
        'departuredest' => 'departure_destination',
        'touchdown' => 'touchdown_at',
        'onblock' => 'onblock_at',
        'remainingfuel' => 'remaining_fuel',
        'blockfuel' => 'block_fuel',
        'ctot' => 'ctot_at',
        'notes' => 'arrival_note'
    ] as $old => $new) $flight[$old] = $flight[$new] ?? null;
    $stmt = $conn->prepare("SELECT phase, process_key, state, started_at, finished_at, value_datetime, value_text FROM flight_processes WHERE flight_id = ?");
    $id = (int)$flight['id'];
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $rows = $stmt->get_result();
    $flight['_process_rows'] = ['arrival' => [], 'departure' => []];
    while ($row = $rows->fetch_assoc()) {
        $flight['_process_rows'][$row['phase']][$row['process_key']] = $row;
    }
    $stmt->close();
    if ($phase !== null) {
        foreach (op_process_keys()[$phase] ?? [] as $key) {
            $row = $flight['_process_rows'][$phase][$key] ?? [];
            $flight[$key . '_status'] = $row['state'] ?? 'not_started';
            $flight[$key . '_start_time'] = $row['started_at'] ?? null;
            $flight[$key . '_end_time'] = $row['finished_at'] ?? null;
            $flight[$key] = $row['value_datetime'] ?? null;
        }
        if ($phase === 'departure') {
            foreach (['_status', '_start_time', '_end_time', ''] as $suffix)
                $flight['doorsclosed' . $suffix] = $flight['doorclosed' . $suffix] ?? null;
        }
    }
    return $flight;
}
