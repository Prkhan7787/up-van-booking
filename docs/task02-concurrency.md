# Task 02 Revision — เวลาใช้งานและ Transaction

เอกสารออกแบบ ไม่ได้รัน SQL หรือทดสอบ MySQL จริง กติกาที่ยืนยัน: ไม่มี Buffer Time ใช้ช่วง [start, end) วันที่และเวลาเต็ม รวมข้ามวัน/ปี แปลง Bangkok เป็น UTC ก่อนตรวจ

```text
existing_start < new_end AND existing_end > new_start
```

สิ้นสุด 10:00 และเริ่ม 10:00 ไม่ทับซ้อน ไม่เพิ่มเวลาเตรียมรถ/พักก่อนหรือหลัง ไม่มีการขยายช่วงอัตโนมัติ

## 1. แหล่งข้อมูลและการครองทรัพยากร

- ใช้ vehicle_assignments.starts_at/ends_at เป็นช่วงเวลาที่จัดไว้ (เสนอให้ตรง requested departure/return ที่ได้รับอนุมัติ; ความหมายจากต้นฉบับยังรอยืนยัน) pickup_at/dropoff_at และ actual_return_at ไม่ใช่ช่วงเวลาจองทดแทน
- เฉพาะ assignment status = active จองรถและคนขับ completed/cancelled ไม่จองต่อ ทั้ง future และ historical overlap check ใช้ active ตามกติกานี้ หากต้องตรวจความถูกต้องประวัติให้เป็นรายงานแยก ไม่ใช้ประวัติมาบล็อกทรัพยากร
- ต้องเปลี่ยน booking และ assignment เป็น completed/cancelled พร้อมกันใน transaction และห้ามเหลือ active ใต้ terminal booking ไม่กรอง booking status เพื่อซ่อน active ที่ค้างผิดสถานะ: ให้ตรวจและแจ้ง inconsistency ห้ามข้าม lock หรือปล่อยให้ active orphan จองต่อเงียบ ๆ
- actual_return_at เก็บเหตุการณ์จริงแยกจาก scheduled ends_at ไม่ต่ออายุการจองอัตโนมัติ หากมีการขยายช่วงจริงต้องตรวจ overlap ใหม่และจัดการกรณีชนตามนโยบายก่อนบันทึก; completed ต้องปล่อยทรัพยากรตามสถานะ ไม่มี auto-complete โดยนาฬิกาที่ผู้ใช้ยังไม่ยืนยัน
- calendar_events แสดง active และ completed เพื่อเก็บปฏิทินย้อนหลัง แต่ conflict query ใช้ active เท่านั้น cancelled ถูกซ่อนจากปฏิทินและยังอยู่ในประวัติ/audit

## 2. การตรวจสองขั้นและ Lock protocol

ตรวจ preliminary availability ก่อนบันทึกเพื่อ feedback ได้ แต่ไม่ถือเป็นการจองและใช้รับประกันไม่ได้ ต้องตรวจซ้ำภายใน transaction เสมอ เสนอ InnoDB READ COMMITTED + explicit parent resource locks ทุก writer ต้องใช้ protocol เดียวกัน รวม background/import/master updates

1. นอก transaction ตรวจรูปแบบวันเวลา, start < end, preliminary conflict รถและคนขับด้วย prepared statements; ไม่ถือว่าผ่านแล้วบันทึกได้ทันที
2. ตั้ง UTC connection และ READ COMMITTED แล้ว BEGIN; SELECT booking_requests WHERE id=:booking_id FOR UPDATE ตรวจ role/ownership/scope/state/approved decision/expected version และ idempotency ของคำสั่ง
3. อ่าน assignment เก่าภายใต้ booking lock (ข้อมูลนอก transaction ใช้เตรียมได้แต่ห้ามใช้ตัดสิน) คำนวณ union รถ/คนขับเก่าและใหม่ แล้วล็อก vehicles ทุกแถวตาม id น้อยไปมาก ทีละ primary-key SELECT FOR UPDATE ต่อด้วย drivers ทุกแถวตาม id เช่นกัน master ที่เปลี่ยน active/capacity ต้องล็อก resource เดียวกัน ตรวจรถ/คนขับพร้อมใช้งาน
4. อ่าน assignments ของ booking ภายใต้ lock ตรวจว่าไม่มี active อีกตัว, ไม่มี terminal booking, และ revision ยังตรง version รวม approved decision/จำนวนผู้โดยสาร/ความจุและ window ที่อนุญาต
5. ตรวจ conflict ใหม่ด้วย current locking reads SELECT ... FOR UPDATE แยก query รถและคนขับหลังได้ resource locks แล้ว แม้ไม่มี assignment เดิมก็ยังล็อก master rows ได้ จึงไม่พึ่ง gap locks ของ query ที่ผลว่าง
6. หาก conflict หรือสิทธิ์/สถานะผิด ให้ rollback ทั้งหมด ไม่เผยรายละเอียดผู้โดยสารหรือคำขออื่นเกินสิทธิ์ หากผ่านจึง INSERT assignment + UPDATE booking/version/updated_by + INSERT history เมื่อ state เปลี่ยน + audit ใน transaction เดียว COMMIT แล้ว calendar view จึงแสดงผล
7. exception ต้อง rollback เสมอ Deadlock/lock timeout ให้ retry ทั้ง transaction แบบจำกัดครั้งและ jitter ไม่ retry INSERT เดี่ยว ถ้าผล commit ไม่แน่ชัดต้องใช้ idempotency key; protocol idempotency ยังต้อง implement ใน Task 03

ลำดับล็อก: booking → vehicles เรียง id → drivers เรียง id → assignment writes ช่วยลด deadlockแต่ยังต้องมี retry ห้ามถือ lock ระหว่างผู้ใช้เลือก/ส่งข้อความ ห้ามใช้ SKIP LOCKED เพื่อตัดสินทรัพยากรว่าง

## 3. SQL/PDO pseudocode สำหรับ conflict check

ตัวอย่างนี้ไม่ใช่ service ที่เปิดใช้ และยังไม่ใช่โค้ดจัดรถครบทุก guard:

```php
$pdo->exec("SET time_zone = '+00:00'"); // static SQL
$pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
$pdo->beginTransaction();
try {
    // Lock booking, authorize, validate state/decision/version.
    // Lock all old/new vehicle IDs sorted; then all driver IDs sorted.
    $query = $pdo->prepare(
        "SELECT id FROM vehicle_assignments
         WHERE vehicle_id = :vehicle_id AND status = 'active'
           AND starts_at < :new_end AND ends_at > :new_start FOR UPDATE"
    );
    $query->execute(['vehicle_id' => $vehicleId, 'new_end' => $endUtc, 'new_start' => $startUtc]);
    // Reject if any row exists. Repeat in a separate prepared query for driver_id.
    // INSERT assignment + booking/version/history/audit atomically.
    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $error;
}
```

driver query ใช้ driver_id = :driver_id กับ status = 'active' และเวลาเงื่อนไขเดียวกัน ใช้ named placeholder ไม่ซ้ำและ PDO native prepares ไม่ประกอบข้อมูลภายนอกลง SQL indexes (vehicle_id,status,starts_at,ends_at) และ (driver_id,status,starts_at,ends_at) ช่วย scan แต่ไม่ใช่ exclusion constraint

## 4. หนึ่ง active ต่อคำขอ และ Race Condition

SQL เพิ่ม active_booking_request_id เป็น generated column ให้มี booking_request_id เฉพาะ status active แล้ว unique index uq_assignment_one_active_booking ประวัติ completed/cancelled ได้ NULL จึงเก็บหลาย revision ได้ ไม่ใช้ UNIQUE(booking_request_id,status) เพราะจะขัดประวัติ cancelled หลายครั้ง

- คนละ booking รถเดียวกัน: A ล็อกรถ 7 ส่วน B ต้องรอ แม้ตาราง assignments ว่าง เมื่อ A commit แล้ว B ได้ lock และ query ใหม่เห็น conflict จึงปฏิเสธ
- รถต่างคนขับเดียวกัน: รอที่ driver lock แล้วตรวจใหม่
- booking เดียวกันแต่รถต่างกัน: รอ booking lock; state/version ไม่ผ่าน หรือ unique generated key ป้องกัน active ตัวที่สอง
- DB unique ป้องกันเฉพาะ active ซ้ำใน booking ไม่กัน overlap ระหว่าง bookings ต้องใช้ resource-lock protocol ร่วมกัน

## 5. แก้ไข เปลี่ยนสถานะ ยกเลิก และสิ้นสุด

**ทุกการเปลี่ยนสถานะที่กระทบ assignments การแก้ช่วงเวลา และเปลี่ยนรถหรือคนขับต้องตรวจ overlap ใหม่ภายใต้ locks** รวมการเปิด active/การต่อเวลา ห้ามเปิด active ใน completed/cancelled/closed_rejected การเข้าสู่ completed/cancelled ให้ตรวจ active consistency และรายการของ resources อีกครั้งก่อน release; conflict ที่พบต้องรายงาน/บันทึกเหตุการณ์ ไม่ทำให้ release ถูกบล็อกจนรายการค้าง

### เปลี่ยนรถ/คนขับ/เวลา (โครงสร้างรองรับ ยังรอยืนยันสิทธิ์และเงื่อนไข)

ล็อก booking และ union resources เก่า-ใหม่ตามลำดับ → เปลี่ยนแถวเก่าเป็น cancelled พร้อม reason/actor/time → ตรวจ active conflicts อีกครั้ง → INSERT แถวใหม่ที่ replaces_assignment_id อ้างแถวเก่า → audit/version → commit ไม่ UPDATE ทับ vehicle/driver/window เดิม ถ้าเกิด conflict หรือ error rollback จะคืน active เดิม ไม่สูญเสียรถที่จองไว้ UNIQUE ของ replacement ป้องกันแตกสาย service ตรวจเป็น booking เดียวกัน ไม่เกิด cycle

ห้าม ignore ทั้ง booking ตอน query ใช้การยกเลิกเฉพาะแถวเก่าใน transaction; แถวอื่นที่ active ต้องยังถูกตรวจ กรณีคง vehicle/driver/window แต่เปลี่ยน metadata ก็ตรวจ state/locks และความสอดคล้องตามเดิม

### ยกเลิกหลังอนุมัติ

ผู้ขอไม่มี endpoint ยกเลิกโดยตรง ต้องติดต่อ officer ผู้มีสิทธิ์ ตรวจการติดต่อว่าเป็นเจ้าของ/booking ที่อนุมัติและอยู่ awaiting_assignment หรือ assigned (ขอบเขตหลังเริ่มเดินทางยังรอยืนยัน) บันทึก booking.cancelled_by/at/reason และ cancellation_requested_by/contacted_at; ถ้ามี active ให้เปลี่ยน cancelled พร้อม actor/reason/time ใน transaction เดียวกับ booking cancelled/history/audit ไม่มี DELETE ผล decision เดิมยัง approved ปฏิทินไม่แสดง cancelled

การยกเลิก assignment อย่างเดียวเพื่อให้ booking กลับรอจัดรถ **ยังไม่มี transition ที่ยืนยัน** ไม่เปิดคำสั่งนี้โดยอัตโนมัติ ใช้ atomic replacement เมื่อกติกาอนุญาต หรือยกเลิก booking ผ่าน officer เท่านั้น กรณีก่อนอนุมัติยังเป็น Open Question

### สิ้นสุดการเดินทาง

เสนอให้ officer บันทึก actual_return_at/actor/time และยืนยัน completed ตามหลักฐานที่จะตกลง ล็อก booking/resources และเปลี่ยน active → completed กับ booking → completed ใน transaction เดียว ไม่เก็บ active ค้างเพียงเพราะเวลาจบเปลี่ยน; ประวัติ calendar ยังอยู่แต่ไม่ block resources

## 6. ข้อจำกัดการรับประกันและการทดสอบต่อ

ทุก writer ต้องปฏิบัติตาม protocol; privileged direct SQL สามารถข้าม service ได้ CHECK ข้ามตารางไม่ได้ จึงต้องจำกัดบัญชีและมี audit/reconciliation ตรวจ terminal booking มี active หรือ decision ผิด ห้ามอ้างว่า unique หรือ unit tests พิสูจน์ race-free แล้ว

ต้องทดสอบ MySQL 8.4 สอง connections: ว่างพร้อมกัน, รถซ้ำ, คนขับซ้ำ, booking ซ้ำต่างรถ, เปลี่ยน resource สลับกัน, rollback replacement, cancel/complete พร้อมจัดใหม่, การต่อเวลาชน, deadlock retry และ generated unique enforcement โดยยังไม่ทำใน Task นี้

อ้างอิงหลักการ: [MySQL Locking Reads](https://dev.mysql.com/doc/refman/8.4/en/innodb-locking-reads.html), [Isolation Levels](https://dev.mysql.com/doc/refman/8.4/en/innodb-transaction-isolation-levels.html), [Generated-column UNIQUE indexes](https://dev.mysql.com/doc/refman/8.4/en/create-table-secondary-indexes.html) resource mutex เป็นการออกแบบสำหรับโปรเจกต์นี้ตามหลักการดังกล่าว
