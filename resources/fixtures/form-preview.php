<?php
declare(strict_types=1);
// Fabricated examples only. No real users, signatures, bookings, or credentials.
$requester = [
    'travel_type' => 'within_province', 'application_day' => '10', 'application_month' => 'พฤศจิกายน', 'application_year' => '2569',
    'requester_name' => 'นางสาวตัวอย่าง ใจดี', 'requester_position' => 'นักวิชาการศึกษา',
    'requester_affiliation' => 'สถาบันนวัตกรรม', 'requester_affiliation_continued' => 'การเรียนรู้',
    'destination' => 'ศูนย์การเรียนรู้ จังหวัดพะเยา', 'purpose' => 'เข้าร่วมประชุมการจัดการเรียนรู้',
    'passenger_count' => '8', 'departure_date' => '15 พฤศจิกายน 2569', 'departure_time' => '08:00',
    'return_date' => '15 พฤศจิกายน 2569', 'return_time' => '16:30', 'expense_source' => 'institute_budget',
    'requester_printed_name' => 'นางสาวตัวอย่าง ใจดี', 'requester_phone' => '000-000-0000',
    'pickup_person_name' => 'นายบุคคล ตัวอย่าง', 'pickup_person_phone' => '000-000-0000',
    'pickup_location' => 'อาคารเรียนรู้', 'pickup_day' => '15', 'pickup_month' => 'พฤศจิกายน', 'pickup_year' => '2569', 'pickup_time' => '08:00',
    'dropoff_location' => 'อาคารเรียนรู้', 'dropoff_day' => '15', 'dropoff_month' => 'พฤศจิกายน', 'dropoff_year' => '2569', 'dropoff_time' => '16:30',
    'coordinator_name' => 'นางผู้ประสานงาน ตัวอย่าง', 'coordinator_phone' => '000-000-0000',
];
$assigned = $requester + [
    'document_number_prefix' => 'ตัวอย่าง', 'request_number_suffix' => '2569', 'vehicle_registration' => 'นข 1234 พะเยา', 'driver_name' => 'นายทดสอบ ใจดี',
    'officer_printed_name' => 'นายเจ้าหน้าที่ ตัวอย่าง', 'officer_day' => '12', 'officer_month' => '11', 'officer_year' => '2569',
    'approval_outcome' => 'approved', 'approver_printed_name' => 'นางผู้พิจารณา ตัวอย่าง',
    'approver_position' => 'ผู้อำนวยการ', 'decision_day' => '11', 'decision_month' => '11', 'decision_year' => '2569',
];
$rejected = $requester + [
    'document_number_prefix' => 'จำลอง', 'request_number_suffix' => '2569', 'approval_outcome' => 'rejected',
    'rejection_reason' => 'เหตุผลประกอบ', 'rejection_reason_continued' => 'ตัวอย่างผลพิจารณา ไม่ใช่คำขอจริง',
    'approver_printed_name' => 'นางผู้พิจารณา ตัวอย่าง', 'approver_position' => 'ผู้อำนวยการ',
    'decision_day' => '11', 'decision_month' => '11', 'decision_year' => '2569',
];
$completed = $assigned + [
    'acknowledging_driver_name' => 'นายทดสอบ ใจดี', 'actual_return_day' => '15',
    'actual_return_month' => 'พฤศจิกายน', 'actual_return_year' => '2569', 'actual_return_time' => '16:20',
];
return ['blank' => [], 'requester' => $requester, 'assigned' => $assigned, 'rejected' => $rejected, 'completed' => $completed];
