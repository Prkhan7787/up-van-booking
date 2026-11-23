# Task 02 Revision — ผลตรวจสอบ

> รายงานด้านล่างบันทึกผลก่อนรับภาพแบบฟอร์มต้นฉบับ ต่อมาได้รับภาพแล้วและทำต้นแบบตาม [UI Specification](form-ui-specification.md) พร้อม [รายงาน UI](form-ui-verification.md) โดยไม่แก้ candidate migration ในงาน UI นี้ จุดต่าง enum ค่าใช้จ่ายยังต้องแก้ในงาน schema ที่อนุญาต

วันที่ 6 พฤศจิกายน 2026 (Asia/Bangkok)

## แหล่งข้อมูล

อ่านคำขอ revision จากไฟล์แนบ Pasted text.txt และตรวจรายการไฟล์ในโปรเจกต์ ไม่พบภาพ/PDF แบบฟอร์มต้นฉบับ จึงออกแบบจากรายการข้อมูลใน prompt เท่านั้น ยังตรวจข้อความต้นฉบับ เครื่องหมายบังคับ ข้อความหมายเหตุ ลายเซ็น และการเลือกแหล่งค่าใช้จ่ายหลายข้อไม่ได้ ไม่รายงานว่าเทียบแบบฟอร์มต้นฉบับครบแล้ว

## ไฟล์ที่แก้ไข (6 ไฟล์)

1. docs/task02-design.md — field mapping / Required-Optional ที่รอยืนยัน, workflow, ER, state transitions, RBAC, schema rationale และ Open Questions ฉบับปรับปรุง
2. docs/task02-concurrency.md — ไม่มี buffer, active-only overlap, preliminary check + locked recheck, one active key, cancellation/replacement/completion และ race protocol
3. database/migrations/002_booking_design.sql — ปรับ candidate เดิมที่ยังไม่เคยรัน ไม่แก้ฐานข้อมูลจริง
4. tests/task02-design.php — executable design examples และ static cross-file consistency checks
5. docs/task02-verification.md — รายงานฉบับนี้
6. README.md — ลิงก์และสถานะ revision, กติกาที่ยืนยัน และข้อจำกัดเรื่องต้นฉบับ

ไม่แก้ production controllers/services/models/routes/UI หรือ database/schema.sql ของ Task 01 ไม่มี Login/OAuth/SSO, booking endpoint ใหม่, บัญชีจริง, Git commit หรืออัปโหลดข้อมูล ไม่ใช้เครื่องมือ MySQL และไม่แตะ Lost and Found/lost_found

## ตาราง/คอลัมน์ที่ปรับ

Candidate ใหม่มี 9 ตาราง + calendar_events view (เดิม 7 ตาราง + view):

| ตาราง | เพิ่ม/เปลี่ยนจาก candidate Task 02 เดิม |
|---|---|
| vehicles / drivers | created_by, updated_by FK users สำหรับเจ้าหน้าที่ผู้สร้าง/แก้ master ไม่ใช่บัญชีคนขับ |
| booking_requests | request_number, application_date, travel_type, requester_name/position/affiliation/phone, additional_details, expense_source/details, pickup_person_name/phone, pickup_location/at, dropoff_location/at, coordinator_name/phone; cancelled_by/at/reason, cancellation_requested_by/contacted_at; created_by/updated_by |
| booking_requests constraints | เพิ่ม cancelled, expense/travel choices; ผ่อน submission CHECK ให้ไม่บังคับช่องแบบฟอร์มที่ยังรอยืนยัน; คง time-pair/count validation เมื่อมีค่า |
| booking_passengers | คงเป็น optional รายชื่อ ไม่บังคับครบจำนวน; เพิ่ม creator/editor/timestamps |
| booking_reviews (ใหม่) | booking unique, reviewed_by/at, receipt_result, review_notes, proposed_by/at, creator/editor/timestamps; แยกจาก assignment |
| approval_decisions | returned_to_officer_at และ CHECK เวลา; คง actor/authority/outcome/reason และหนึ่งผลต่อ booking |
| vehicle_assignments | active_booking_request_id generated + unique, assigned_at, updated_by/at, driver_acknowledged_at/recorded_by/recorded_at/reference, actual_return_at/recorded_by/recorded_at; คง revision chain/cancellation metadata |
| booking_notifications (ใหม่) | booking, outcome, channel, notification_reference, notified_by/at, created_at; สำเร็จทั้ง approved และ rejected ได้หลายเหตุการณ์ |
| booking_status_history | รองรับ cancelled ใน from/to; คง actor/version/history |
| calendar_events view | active/completed ของ booking assigned/completed; completed เป็นประวัติเท่านั้น ไม่ block resource |

เอา rejection_notified_* เดิมออกจาก candidate booking_requests เพื่อ normalize ลง booking_notifications ไม่มีข้อมูลจริงที่ต้องย้าย คง users/roles/user_roles/audit_logs เดิมทั้งหมด ไม่มี role ใหม่

## State Transitions ฉบับปรับปรุง

```text
draft → submitted → under_review → pending_approval
pending_approval → awaiting_assignment (decision approved กลับเจ้าหน้าที่)
pending_approval → awaiting_rejection_notice (decision rejected กลับเจ้าหน้าที่)
awaiting_assignment → assigned (จัดรถ + ปฏิทิน)
assigned → บันทึกการแจ้ง approved (สถานะคงเดิม) → completed
awaiting_rejection_notice → แจ้งเหตุผล → closed_rejected
awaiting_assignment / assigned → cancelled (เจ้าหน้าที่หลังผู้ขอติดต่อเท่านั้น)
```

completed/closed_rejected/cancelled เป็น terminal ไม่มี rejected กลับให้ requester แก้ ไม่มี requester cancellation endpoint และไม่มี pre-approval cancellation ที่ยังไม่ได้ยืนยัน ผลพิจารณาแยกจากสถานะดำเนินงาน การยกเลิกไม่ลบหรือแก้ approved decision

## ทดสอบจริงและผ่าน

- PHP lint ทั้ง 16 ไฟล์: ไม่พบ syntax error
- php tests/task02-design.php: **283 design contract checks ผ่าน** ไม่โหลด bootstrap/config/.env และไม่มี DB connection
- php tests/smoke.php: **7 regression smoke checks ผ่าน**

ครอบคลุม design examples ของเวลาเท่าปลายช่วง (ไม่มี buffer), ครอบช่วง, เวลาตรงกัน, ข้ามวัน/ปี, timezone/microseconds, รถเดียว/คนขับเดียว, resources ต่างกัน, completed/cancelled ปล่อยทรัพยากร และตัวอย่างหนึ่ง active ต่อ booking พร้อมหลาย history rows

Static consistency checks ตรวจตาราง/คอลัมน์ตามแบบฟอร์ม, nullable fields ที่ยังรอยืนยัน, generated unique declaration, FK target/ชนิด integer และเส้นความสัมพันธ์ใน Mermaid, ตาราง state transitions เทียบ graph, RBAC จาก matrix ในเอกสาร, ไม่มี role/login driver และ active-only SQL ใน concurrency doc

ระหว่างปรับ tests มี assertion เดิมที่มอง FK created_by/updated_by ของ drivers ว่าเป็นบัญชีคนขับ จึงแก้ให้ตรวจการไม่มี user_id/password_hash/role_id แทน ผลสุดท้ายด้านบนเป็นการรันหลังแก้แล้ว

## ยังไม่ได้ทดสอบ / ขอบเขตหลักฐาน

- **ไม่เชื่อมต่อ MySQL ไม่สร้าง/แก้ฐานข้อมูล และไม่รัน migration** ตามข้อจำกัด
- Static extraction ใช้ตรวจ candidate format ไม่ใช่ SQL parser หรือหลักฐานว่า MySQL 8.4 ยอมรับ DDL จริง
- generated unique key/NULL semantics, FK/CHECK/view execution และ query plans ยังต้อง integration test บน MySQL 8.4 ที่ได้รับอนุญาตใน Task ใหม่
- ไม่ทดสอบสอง connection, deadlock/retry, rollback, DB race, completion/cancellation แข่งกับ assignment หรือ database enforcement จริง
- Tests เป็น design contracts ไม่ใช่การทดสอบ service จัดรถ/authorization จริง ซึ่งยังไม่ได้พัฒนา
- Mermaid source ตรวจความสัมพันธ์เทียบ FK แบบ static แล้ว แต่ยังไม่ได้ render ด้วย Mermaid engine
- ไม่ทำ HTTP/IIS/browser test ใหม่เพราะไม่แก้ runtime/UI ผลเดิม Task 01 อยู่ docs/verification.md

## Open Questions และความพร้อม Task 03

พร้อมเป็นฐานสำหรับ review และวางงาน Task 03 แต่ยังต้องยืนยัน field requiredness/ความหมายต้นฉบับ, expense selection, เลขคำขอ, review result, การมอบอำนาจและ scope, กติกาแก้ไข/ยกเลิกก่อนอนุมัติและหลังออกเดินทาง, วิธีบันทึกรับทราบ/เวลากลับจริง/completion, notification evidence และการกลับเกินกำหนด ดูรายละเอียด 10 กลุ่มใน task02-design.md

ไม่ต้องถามซ้ำเรื่องหนึ่งคัน ไม่มี buffer ปลายช่วงติดกันได้ และยกเลิกหลังอนุมัติโดยติดต่อเจ้าหน้าที่ซึ่งยืนยันแล้ว ก่อน implementation ส่วน policy/validation ให้ปิดคำถามที่เกี่ยวข้องก่อน ส่วน migration/integration tests ต้องมี scope อนุญาตใหม่ การทำ Task นี้ไม่ใช่การอนุญาตให้รัน migration ใน Task ถัดไปโดยอัตโนมัติ
