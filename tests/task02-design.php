<?php
declare(strict_types=1);
// Executable design examples ONLY. No application bootstrap, PDO, environment or DB.
$checks = 0;
function verify(bool $ok, string $message): void {
    global $checks;
    if (!$ok) throw new RuntimeException('FAIL: ' . $message);
    $checks++;
}
// Confirmed occupied interval rule: UTC, half-open [start, end), no buffer.
function overlaps(string $aStart, string $aEnd, string $bStart, string $bEnd): bool {
    $times = array_map(static fn(string $v): DateTimeImmutable => new DateTimeImmutable($v), [$aStart, $aEnd, $bStart, $bEnd]);
    [$as, $ae, $bs, $be] = $times;
    if ($as >= $ae || $bs >= $be) throw new InvalidArgumentException('Non-positive interval');
    return $as < $be && $ae > $bs;
}
$examples = [
    ['2026-11-06T09:00+07:00', '2026-11-06T10:00+07:00', '2026-11-06T10:00+07:00', '2026-11-06T11:00+07:00', false],
    ['2026-11-06T09:00Z', '2026-11-06T12:00Z', '2026-11-06T10:00Z', '2026-11-06T11:00Z', true],
    ['2026-11-06T09:00Z', '2026-11-06T10:00Z', '2026-11-06T09:00Z', '2026-11-06T10:00Z', true],
    ['2026-11-06T23:00+07:00', '2026-11-07T02:00+07:00', '2026-11-07T01:00+07:00', '2026-11-07T03:00+07:00', true],
    ['2026-11-06T09:00Z', '2026-11-06T10:00Z', '2026-11-07T09:00Z', '2026-11-07T10:00Z', false],
    ['2026-12-31T23:00Z', '2027-01-01T02:00Z', '2027-01-01T01:00Z', '2027-01-01T03:00Z', true],
    ['2026-11-06T09:00+07:00', '2026-11-06T10:00+07:00', '2026-11-06T02:30Z', '2026-11-06T03:30Z', true],
    ['2026-11-06T09:00:00.000000Z', '2026-11-06T10:00:00.000001Z', '2026-11-06T10:00:00Z', '2026-11-06T11:00:00Z', true],
];
foreach ($examples as [$as, $ae, $bs, $be, $expected]) {
    verify(overlaps($as, $ae, $bs, $be) === $expected, 'interval example');
    verify(overlaps($bs, $be, $as, $ae) === $expected, 'symmetric intervals');
}
foreach ([['2026-11-06T10:00Z','2026-11-06T10:00Z'], ['2026-11-07T10:00Z','2026-11-06T10:00Z']] as [$start,$end]) {
    $rejected = false;
    try { overlaps($start,$end,'2026-11-06T09:00Z','2026-11-06T11:00Z'); }
    catch (InvalidArgumentException) { $rejected = true; }
    verify($rejected, 'reject zero/negative interval');
}
// Resource conflict examples: either matching resource blocks; cancelled releases time.
function conflicts(array $existing, array $candidate): bool {
    return $existing['status'] === 'active'
        && ($existing['vehicle'] === $candidate['vehicle'] || $existing['driver'] === $candidate['driver'])
        && overlaps($existing['start'], $existing['end'], $candidate['start'], $candidate['end']);
}
$existing = ['vehicle'=>1,'driver'=>2,'status'=>'active','start'=>'2026-11-06T09:00Z','end'=>'2026-11-06T11:00Z'];
$candidate = ['vehicle'=>1,'driver'=>3,'start'=>'2026-11-06T10:00Z','end'=>'2026-11-06T12:00Z'];
verify(conflicts($existing, $candidate), 'same vehicle different driver blocks');
$candidate['vehicle'] = 9;
$candidate['driver'] = 2;
verify(conflicts($existing, $candidate), 'same driver different vehicle blocks');
$candidate['driver'] = 3;
verify(!conflicts($existing, $candidate), 'different resources do not block');
$candidate['vehicle'] = 1;
$existing['status'] = 'cancelled';
verify(!conflicts($existing, $candidate), 'cancelled allocation releases interval');
$existing['status'] = 'completed';
verify(!conflicts($existing, $candidate), 'completed allocation releases resources but preserves history');
$existing['status'] = 'active';
$candidate['start'] = $existing['end'];
$candidate['end'] = '2026-11-06T12:00Z';
verify(!conflicts($existing, $candidate), 'same resource touching boundary requires no buffer');
// Decision table specifies the confirmed state graph; guards remain service responsibilities.
$graph = [
    'draft' => ['submitted'], 'submitted' => ['under_review'],
    'under_review' => ['pending_approval'],
    'pending_approval' => ['awaiting_assignment','awaiting_rejection_notice'],
    'awaiting_assignment' => ['assigned','cancelled'],
    'awaiting_rejection_notice' => ['closed_rejected'],
    'assigned' => ['completed','cancelled'], 'completed' => [], 'closed_rejected' => [], 'cancelled' => [],
];
verify(count($graph) === 10, 'nine original states plus post-approval cancellation');
verify($graph['awaiting_rejection_notice'] === ['closed_rejected'], 'rejection only reaches officer closure');
verify($graph['completed'] === [] && $graph['closed_rejected'] === [] && $graph['cancelled'] === [], 'terminal states');
foreach ($graph as $from => $targets) foreach ($targets as $to) {
    verify(array_key_exists($to, $graph) && $from !== $to, 'valid non-self target');
}
foreach (['awaiting_assignment','awaiting_rejection_notice','assigned','completed','closed_rejected','cancelled'] as $state) {
    verify(!in_array('draft',$graph[$state],true) && !in_array('submitted',$graph[$state],true), 'no return to requester after decision');
}
$root = dirname(__DIR__);
$sql = file_get_contents($root . '/database/migrations/002_booking_design.sql');
// Static contract checks are NOT a SQL parser or proof of MySQL compatibility.
verify(!preg_match('/^\s*(?:DROP|TRUNCATE|ALTER|USE|CREATE\s+(?:DATABASE|USER)|INSERT|UPDATE|DELETE)\b/im', $sql), 'additive design DDL only');
foreach (['vehicles','drivers','booking_requests','booking_passengers','booking_reviews','approval_decisions','vehicle_assignments','booking_notifications','booking_status_history'] as $table) {
    verify((bool) preg_match('/CREATE TABLE ' . $table . '\s*\(/', $sql), 'required table ' . $table);
}
verify(substr_count($sql, 'CREATE TABLE ') === 9, 'nine tables including review and notifications');
verify(str_contains($sql, 'CREATE SQL SECURITY INVOKER VIEW calendar_events AS'), 'calendar is derived');
verify(!preg_match('/CREATE TABLE (users|roles|user_roles|audit_logs)\b/', $sql), 'Task 01 tables preserved');
$doc = file_get_contents($root . '/docs/task02-design.md');
foreach ($graph as $state => $targets) {
    $found = false;
    foreach (explode("\n",$doc) as $line) {
        $cells = array_map('trim',explode('|',trim($line)));
        if (($cells[2] ?? '') !== $state) continue;
        $found = true;
        preg_match_all('/\b(?:' . implode('|',array_keys($graph)) . ')\b/', $cells[3] ?? '', $targetMatches);
        $documentedTargets = $targetMatches[0];
        sort($documentedTargets);
        sort($targets);
        verify($documentedTargets === $targets, 'documented transitions match executable state graph: ' . $state);
        break;
    }
    verify($found, 'documented transition row exists: ' . $state);
}
foreach (array_keys($graph) as $state) {
    verify(str_contains($sql,"'" . $state . "'") && str_contains($doc,$state), 'schema/document state alignment');
}
verify(str_contains($doc, 'Open Questions'), 'unconfirmed rules recorded');

// Static table extraction for this candidate's formatting, NOT a general SQL parser.
preg_match_all('/CREATE TABLE (\w+) \((.*?)\) ENGINE=/s', $sql, $matches, PREG_SET_ORDER);
$tables = [];
foreach ($matches as $match) $tables[$match[1]] = $match[2];
verify(count($tables) === 9, 'all candidate tables extracted');
$base = file_get_contents($root . '/database/schema.sql');
preg_match_all('/CREATE TABLE (\w+) \((.*?)\) ENGINE=/s', $base, $baseMatches, PREG_SET_ORDER);
$allTables = $tables;
foreach ($baseMatches as $match) $allTables[$match[1]] = $match[2];
foreach ($tables as $table => $body) {
    preg_match_all('/FOREIGN KEY \((\w+)\) REFERENCES (\w+)\((\w+)\)/', $body, $foreignKeys, PREG_SET_ORDER);
    foreach ($foreignKeys as [, $column, $parent, $parentColumn]) {
        verify(isset($allTables[$parent]), "FK target exists: $table.$column");
        preg_match('/^\s*' . $column . '\s+(BIGINT UNSIGNED|SMALLINT UNSIGNED)/m', $body, $childType);
        preg_match('/^\s*' . $parentColumn . '\s+(BIGINT UNSIGNED|SMALLINT UNSIGNED)/m', $allTables[$parent], $parentType);
        verify(isset($childType[1],$parentType[1]) && $childType[1] === $parentType[1], 'FK integer type alignment');
        // Every FK pair must have a relationship in the Mermaid ER (actor FKs grouped).
        verify((bool) preg_match('/^\s*' . $parent . '\s+[^\s]+--[^\s]+\s+' . $table . '\s*:/m', $doc), "ER covers FK pair $parent -> $table");
    }
}
$booking = $tables['booking_requests'];
foreach (['request_number','application_date','travel_type','requester_name','requester_position','requester_affiliation','requester_phone',
          'purpose','destination','additional_details','passenger_count','requested_start_at','requested_end_at','expense_source','expense_source_details',
          'pickup_person_name','pickup_person_phone','pickup_location','pickup_at','dropoff_location','dropoff_at','coordinator_name','coordinator_phone'] as $field) {
    verify((bool) preg_match('/^\s*' . $field . '\s+[^\n]+\bNULL\b/m', $booking), "form field supports NULL pending requiredness: $field");
    verify(str_contains($doc, $field), "field mapping documented: $field");
}
foreach (['institute_budget','project_budget','other_expenses','driver_extra_pay','within_province','outside_province'] as $value) {
    verify(str_contains($booking,"'" . $value . "'") && str_contains($doc,$value), 'form option schema/docs alignment');
}
verify(str_contains($tables['vehicle_assignments'], "CASE WHEN status = 'active' THEN booking_request_id ELSE NULL END"), 'active-only generated booking key');
verify(str_contains($tables['vehicle_assignments'], 'UNIQUE KEY uq_assignment_one_active_booking (active_booking_request_id)'), 'one-active DB unique declaration');
foreach (['vehicle_id','driver_id'] as $resource) {
    verify((bool) preg_match('/' . $resource . ' BIGINT UNSIGNED NOT NULL/', $tables['vehicle_assignments']), 'one required resource per assignment');
}
verify(str_contains($tables['vehicle_assignments'], 'replaces_assignment_id BIGINT UNSIGNED NULL UNIQUE'), 'unique replacement chain');
foreach (['driver_acknowledged_at','driver_acknowledgement_recorded_by','driver_acknowledgement_recorded_at','actual_return_at','actual_return_recorded_by','actual_return_recorded_at'] as $field) {
    verify(str_contains($tables['vehicle_assignments'],$field) && str_contains($doc,$field), 'driver event field documented');
}
foreach (['vehicles','drivers','booking_requests','booking_passengers','booking_reviews'] as $table) {
    foreach (['created_by','updated_by','created_at','updated_at'] as $column) {
        verify(str_contains($tables[$table],$column), 'mutable table attribution');
    }
}
verify(str_contains($tables['approval_decisions'],'returned_to_officer_at'), 'both outcomes return to officer queue');
verify(!str_contains($booking,'rejection_notified_'), 'notifications normalized without duplicate booking fields');
verify(!preg_match('/^\s*(?:user_id|password_hash|role_id)\s+/m',$tables['drivers']), 'drivers have no login account columns; creator/editor may be staff users');
verify(!preg_match('/(?:INSERT|UPDATE|DELETE)\s+(?:INTO\s+)?roles/i',$sql), 'no new roles or role mutation');

// In-memory examples of the intended unique-key behavior; not database enforcement tests.
function oneActivePerBooking(array $rows): bool {
    $seen = [];
    foreach ($rows as $row) {
        if ($row['status'] !== 'active') continue;
        if (isset($seen[$row['booking']])) return false;
        $seen[$row['booking']] = true;
    }
    return true;
}
verify(!oneActivePerBooking([['booking'=>1,'status'=>'active'],['booking'=>1,'status'=>'active']]), 'reject two active rows for same booking');
verify(oneActivePerBooking([['booking'=>1,'status'=>'active'],['booking'=>2,'status'=>'active']]), 'distinct bookings have independent active keys');
verify(oneActivePerBooking([['booking'=>1,'status'=>'cancelled'],['booking'=>1,'status'=>'cancelled'],['booking'=>1,'status'=>'completed'],['booking'=>1,'status'=>'active']]), 'multiple history revisions and single active');
verify(!in_array('cancelled',$graph['pending_approval'],true) && !in_array('cancelled',$graph['draft'],true), 'no unconfirmed pre-approval cancellation');

// Read the actual documented RBAC matrix so permission changes cannot silently drift.
function matrixRow(string $doc, string $label): array {
    foreach (explode("\n", $doc) as $line) {
        $cells = array_map('trim', explode('|', trim($line)));
        if (($cells[1] ?? '') === $label) return array_slice($cells,2,4);
    }
    throw new RuntimeException('Missing RBAC row ' . $label);
}
$approval = matrixRow($doc, 'อนุมัติ/ไม่อนุมัติ');
verify($approval[0] === 'ไม่ได้' && $approval[1] === 'ไม่ได้' && $approval[3] === 'ไม่ได้', 'only approver may decide');
verify(str_contains($approval[2],'เมื่อมีอำนาจ'), 'approver role is insufficient without authority');
$assignment = matrixRow($doc,'จัดรถ/คนขับ/ปฏิทิน');
verify($assignment === ['ไม่ได้','หลังอนุมัติเท่านั้น','ไม่ได้','ไม่ได้'], 'officer assignment only after approval');
$cancel = matrixRow($doc,'ยกเลิกหลังอนุมัติ');
verify(str_contains($cancel[0],'ไม่มีสิทธิ์ยกเลิกตรง') && str_contains($cancel[1],'บันทึกเหตุผล'), 'cancellation routed through officer with reason');
verify(matrixRow($doc,'จัดการบัญชี/Role/การตั้งค่า') === ['ไม่ได้','ไม่ได้','ไม่ได้','ได้'], 'admin account and settings permission only');
$concurrency = file_get_contents($root . '/docs/task02-concurrency.md');
verify(str_contains($concurrency,"AND status = 'active'") && !str_contains($concurrency,"IN ('active','completed')"), 'conflict SQL active only');
verify(str_contains($concurrency,'existing_start < new_end AND existing_end > new_start'), 'strict half-open SQL overlap predicate');
verify(str_contains($concurrency,'ไม่มี Buffer Time') && str_contains($concurrency,'ตรวจซ้ำภายใน transaction'), 'confirmed no buffer and locked recheck documented');
echo "PASS: {$checks} design contract checks; no database connection.\n";
