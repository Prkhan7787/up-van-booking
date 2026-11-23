<?php
declare(strict_types=1);

// Extension of integration.php, not an executable runner or application service.
function stageDAssert(bool $ok, string $label): void
{
    global $checks;
    if (!$ok) throw new App\Database\SafetyException('FAIL: ' . $label);
    $checks++;
    echo 'PASS: ', $label, PHP_EOL;
}

function stageDTransition(PDO $db, int $booking, ?string $from, string $to, int $actor): void
{
    $row = q($db, 'SELECT workflow_status,version FROM booking_requests WHERE id=:b FOR UPDATE', ['b'=>$booking])->fetch();
    if (!$row || $row['workflow_status'] !== ($from ?? 'draft')) throw new App\Database\SafetyException('Workflow fixture state mismatch.');
    $version = (int)$row['version'] + ($from === null ? 0 : 1);
    q($db, 'UPDATE booking_requests SET workflow_status=:s,version=:v,updated_by=:a,submitted_at=CASE WHEN :submitted=1 THEN CURRENT_TIMESTAMP(6) ELSE submitted_at END WHERE id=:b',
        ['s'=>$to,'v'=>$version,'a'=>$actor,'submitted'=>$to==='submitted'?1:0,'b'=>$booking]);
    q($db, 'INSERT INTO booking_status_history (booking_request_id,from_status,to_status,changed_by,booking_version) VALUES (:b,:f,:t,:a,:v)',
        ['b'=>$booking,'f'=>$from,'t'=>$to,'a'=>$actor,'v'=>$version]);
    q($db, "INSERT INTO audit_logs (user_id,action,entity_type,entity_id,metadata) VALUES (:a,:action,'booking',:b,:metadata)",
        ['a'=>$actor,'action'=>'test.workflow.'.$to,'b'=>(string)$booking,'metadata'=>json_encode(['from'=>$from,'to'=>$to,'version'=>$version], JSON_THROW_ON_ERROR)]);
}

function stageDWorkflowCases(PDO $db, string $tag, int $officer, int $vehicle, int $driver, array &$runUsers, array &$runBookings): void
{
    $actors = ['vehicle_officer'=>$officer];
    foreach (['requester','director','delegate'] as $kind) {
        q($db, 'INSERT INTO users (email,display_name,password_hash) VALUES (:e,:n,NULL)', ['e'=>$tag.'.'.$kind.'@example.invalid','n'=>'Synthetic '.$kind]);
        $actors[$kind] = (int)$db->lastInsertId();
        $runUsers[] = $actors[$kind];
    }
    foreach ($actors as $kind=>$id) {
        $role = in_array($kind, ['director','delegate'], true) ? 'approver' : $kind;
        $roleId = q($db, 'SELECT id FROM roles WHERE name=:n', ['n'=>$role])->fetchColumn();
        if (!$roleId) throw new App\Database\SafetyException('Required reference role is absent.');
        q($db, 'INSERT INTO user_roles (user_id,role_id) VALUES (:u,:r)', ['u'=>$id,'r'=>$roleId]);
    }
    stageDAssert((int)q($db, 'SELECT COUNT(*) FROM user_roles WHERE user_id IN ('.implode(',',array_map('intval',array_values($actors))).')')->fetchColumn()===4, 'workflow actor role fixtures');

    foreach (['director','delegate'] as $authority) foreach (['approved','rejected'] as $outcome) {
        $label = $authority.'.'.$outcome;
        $requester = $actors['requester']; $decider = $actors[$authority];
        q($db, "INSERT INTO booking_requests (requester_id,request_number,passenger_count,requested_start_at,requested_end_at,created_by,updated_by) VALUES (:r,:n,1,'2099-01-01 09:00:00','2099-01-01 10:00:00',:a,:u)", ['r'=>$requester,'n'=>$tag.'.'.$label,'a'=>$requester,'u'=>$requester]);
        $b = (int)$db->lastInsertId(); $runBookings[] = $b;
        $expected = [];
        $step = function (?string $from, string $to, int $actor) use ($db,$b,$label,&$expected): void {
            stageDTransition($db,$b,$from,$to,$actor);
            $expected[] = [$from,$to,$actor,count($expected)+1];
            $row = q($db,'SELECT workflow_status,updated_by,version FROM booking_requests WHERE id=:b',['b'=>$b])->fetch();
            stageDAssert($row['workflow_status']===$to && (int)$row['updated_by']===$actor && (int)$row['version']===count($expected), $label.'.state.'.$to);
        };
        $step(null,'draft',$requester);
        q($db, "INSERT INTO booking_passengers (booking_request_id,display_name,affiliation,created_by,updated_by) VALUES (:b,'Synthetic passenger','Test affiliation',:a,:u)", ['b'=>$b,'a'=>$requester,'u'=>$requester]);
        $p = q($db,'SELECT display_name,affiliation,created_by,updated_by FROM booking_passengers WHERE booking_request_id=:b',['b'=>$b])->fetch();
        stageDAssert($p['display_name']==='Synthetic passenger' && $p['affiliation']==='Test affiliation' && (int)$p['created_by']===$requester && (int)$p['updated_by']===$requester && (int)q($db,'SELECT passenger_count FROM booking_requests WHERE id=:b',['b'=>$b])->fetchColumn()===1, $label.'.passenger');
        expectError($db,fn()=>q($db,'UPDATE booking_passengers SET display_name=NULL WHERE booking_request_id=:b',['b'=>$b]),1048);
        stageDAssert(true,$label.'.passenger.not_null');
        expectError($db,fn()=>q($db,'UPDATE booking_requests SET passenger_count=0 WHERE id=:b',['b'=>$b]),3819);
        stageDAssert(true,$label.'.passenger.positive_count');
        expectError($db,fn()=>q($db,'UPDATE booking_passengers SET updated_by=18446744073709551614 WHERE booking_request_id=:b',['b'=>$b]),1452);
        stageDAssert(true,$label.'.passenger.actor_fk');
        $step('draft','submitted',$requester);
        $step('submitted','under_review',$officer);
        q($db,"INSERT INTO booking_reviews (booking_request_id,reviewed_by,reviewed_at,receipt_result,created_by,updated_by) VALUES (:b,:a,'2098-01-01','received',:c,:u)",['b'=>$b,'a'=>$officer,'c'=>$officer,'u'=>$officer]);
        q($db,"UPDATE booking_reviews SET receipt_result='ready_for_approval',proposed_at='2098-01-02',proposed_by=:a WHERE booking_request_id=:b",['a'=>$officer,'b'=>$b]);
        $step('under_review','pending_approval',$officer);
        $review=q($db,'SELECT * FROM booking_reviews WHERE booking_request_id=:b',['b'=>$b])->fetch();
        stageDAssert($review['receipt_result']==='ready_for_approval' && (int)$review['reviewed_by']===$officer && (int)$review['proposed_by']===$officer && $review['proposed_at'] >= $review['reviewed_at'], $label.'.officer_review');
        $reference=$authority==='delegate'?'Synthetic delegation '.$tag:null;
        $reason=$outcome==='rejected'?'Synthetic rejection reason':null;
        q($db,"INSERT INTO approval_decisions (booking_request_id,decided_by,authority_kind,authority_reference,outcome,reason,decided_at,returned_to_officer_at) VALUES (:b,:a,:k,:r,:o,:reason,'2098-01-03','2098-01-03')",['b'=>$b,'a'=>$decider,'k'=>$authority,'r'=>$reference,'o'=>$outcome,'reason'=>$reason]);
        $waiting=$outcome==='approved'?'awaiting_assignment':'awaiting_rejection_notice';
        $step('pending_approval',$waiting,$decider);
        $decision=q($db,'SELECT * FROM approval_decisions WHERE booking_request_id=:b',['b'=>$b])->fetch();
        stageDAssert((int)$decision['decided_by']===$decider && $decision['authority_kind']===$authority && $decision['authority_reference']===$reference && $decision['outcome']===$outcome && $decision['reason']===$reason && $decision['returned_to_officer_at'] >= $decision['decided_at'], $label.'.decision_and_officer_queue');
        expectError($db,fn()=>q($db,"UPDATE approval_decisions SET authority_kind='invalid' WHERE booking_request_id=:b",['b'=>$b]),3819);
        stageDAssert(true,$label.'.authority_constraint');
        if($authority==='delegate') {
            expectError($db,fn()=>q($db,"UPDATE approval_decisions SET authority_reference=' ' WHERE booking_request_id=:b",['b'=>$b]),3819);
            stageDAssert(true,$label.'.delegate_reference_required');
        }
        if($outcome==='rejected') {
            expectError($db,fn()=>q($db,"UPDATE approval_decisions SET reason=' ' WHERE booking_request_id=:b",['b'=>$b]),3819);
            stageDAssert(true,$label.'.rejection_reason_required');
        }
        q($db,"INSERT INTO booking_notifications (booking_request_id,outcome,channel,notification_reference,notified_at,notified_by) VALUES (:b,:o,'synthetic',:r,'2098-01-04',:a)",['b'=>$b,'o'=>$outcome,'r'=>$tag.'.'.$label,'a'=>$officer]);
        $notice=q($db,'SELECT * FROM booking_notifications WHERE booking_request_id=:b',['b'=>$b])->fetch();
        stageDAssert($notice['outcome']===$decision['outcome'] && (int)$notice['notified_by']===$officer && $notice['channel']==='synthetic' && $notice['notification_reference']===$tag.'.'.$label && $notice['notified_at'] >= $decision['returned_to_officer_at'], $label.'.officer_notification');
        expectError($db,fn()=>q($db,"UPDATE booking_notifications SET channel=' ' WHERE booking_request_id=:b",['b'=>$b]),3819);
        stageDAssert(true,$label.'.notification_channel_constraint');
        if($outcome==='approved') {
            $assignment=assign($db,$b,$vehicle,$driver,$officer);
            $step('awaiting_assignment','assigned',$officer);
            $event=q($db,'SELECT e.*,a.assigned_by FROM calendar_events e JOIN vehicle_assignments a ON a.id=e.assignment_id WHERE e.assignment_id=:id',['id'=>$assignment])->fetch();
            stageDAssert($event && (int)$event['assigned_by']===$officer && (int)$event['booking_request_id']===$b && (int)$event['vehicle_id']===$vehicle && (int)$event['driver_id']===$driver && $event['starts_at']==='2099-01-01 09:00:00.000000' && $event['ends_at']==='2099-01-01 10:00:00.000000' && $event['assignment_status']==='active' && $event['workflow_status']==='assigned', $label.'.calendar_active');
            cancel($db,$assignment,$officer);
            stageDAssert(!q($db,'SELECT assignment_id FROM calendar_events WHERE assignment_id=:id',['id'=>$assignment])->fetchColumn(), $label.'.calendar_cancelled_hidden');
            $replacement=assign($db,$b,$vehicle,$driver,$officer,$assignment);
            q($db,"UPDATE vehicle_assignments SET status='completed' WHERE id=:id",['id'=>$replacement]);
            $step('assigned','completed',$officer);
            $event=q($db,'SELECT assignment_status,workflow_status FROM calendar_events WHERE assignment_id=:id',['id'=>$replacement])->fetch();
            stageDAssert($event===['assignment_status'=>'completed','workflow_status'=>'completed'], $label.'.calendar_completed');
        } else {
            $step('awaiting_rejection_notice','closed_rejected',$officer);
            stageDAssert((int)q($db,'SELECT COUNT(*) FROM vehicle_assignments WHERE booking_request_id=:b',['b'=>$b])->fetchColumn()===0 && (int)q($db,'SELECT COUNT(*) FROM calendar_events WHERE booking_request_id=:b',['b'=>$b])->fetchColumn()===0, $label.'.no_assignment_or_calendar');
        }
        $history=q($db,'SELECT from_status,to_status,changed_by,booking_version FROM booking_status_history WHERE booking_request_id=:b ORDER BY booking_version',['b'=>$b])->fetchAll();
        $actual=array_map(static fn(array $r):array=>[$r['from_status'],$r['to_status'],(int)$r['changed_by'],(int)$r['booking_version']],$history);
        stageDAssert($actual===$expected,$label.'.exact_status_history');
        $audit=q($db,"SELECT user_id,action,metadata FROM audit_logs WHERE entity_type='booking' AND entity_id=:b ORDER BY id",['b'=>(string)$b])->fetchAll();
        $auditValid=count($audit)===count($expected);
        foreach($audit as $i=>$row) {
            $e=$expected[$i]??null; $m=json_decode($row['metadata'],true,512,JSON_THROW_ON_ERROR);
            $auditValid=$auditValid && $e!==null && (int)$row['user_id']===$e[2] && $row['action']==='test.workflow.'.$e[1] && ($m['from']??null)===$e[0] && ($m['to']??null)===$e[1] && ($m['version']??null)===$e[3];
        }
        stageDAssert($auditValid,$label.'.exact_audit_log');
    }
}
