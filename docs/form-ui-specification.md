# UI Specification — ใบขออนุญาตใช้รถตู้ตามเอกสารต้นฉบับ

Task 02: ต้นแบบสำหรับตรวจหน้าตาและการกรอกเท่านั้น ไม่มี persistence, Login, OAuth, SSO, การอนุมัติจริง หรือ migration ใหม่

## 1. Visual source และหลักการถอดแบบ

ใช้ภาพที่ผู้ใช้แนบ `codex-clipboard-0da7ce40-d4f1-4fc6-96a2-53300444c40c.png` เป็นหลัก สำเนาไฟล์ไม่ดัดแปลงอยู่ `docs/reference/original-form.png` (673 × 895 px) ข้อความบนภาพเป็นเนื้อหาเอกสาร ไม่ใช่คำสั่งให้ระบบออกเดินทาง อนุมัติ หรือดำเนินการอื่น

ถอดข้อความคงที่จากภาพโดยตรง รวม “ไปปฏิบัติงานภายในจังหวัดพะเยา”, “ค่าใช้จ่าย ค่าเบี้ยเลี้ยงและค่าน้ำมันเชื้อเพลิง”, “เงินเพิ่มพิเศษพนักงานขับรถ”, “ไม่สามารถจัดรถให้ได้ เนื่องจาก”, หมายเหตุ เอกสารแนบ และข้อควรจำทั้งสามข้อ ไม่เปลี่ยนเป็น Card/Dashboard Form ไม่ใส่คำอธิบายทางเทคนิคในเนื้อเอกสาร

ทะเบียนและชื่อคนขับที่มีอยู่ในภาพเป็นค่าที่กรอกไว้ ไม่ใช่ข้อความคงที่ของแบบฟอร์ม จึงไม่ใช้เป็น default หรือบัญชีจริง ช่องว่างของหน้าต้นแบบเริ่มต้นยังว่าง ส่วนตัวอย่างใช้ชื่อ/ทะเบียน/โทรศัพท์จำลองและไม่ปลอมลายเซ็น

## 2. ตำแหน่งและ typography

เอกสาร A4 แนวตั้ง 210 × 297 mm พื้นขาว ข้อความต้นฉบับและเส้นสีดำ ส่วนข้อมูลที่เติมใช้หมึกน้ำเงินตามคำขอเพิ่มเติม ใช้หน่วย mm สำหรับ layout และ pt สำหรับ font คงสัดส่วนบน desktop/mobile ไม่ใช้ Tailwind preflight กับหน้านี้ เพื่อไม่ให้ reset เปลี่ยนกรอบหรือ typography

| องค์ประกอบ | ตำแหน่ง/ขนาดตั้งต้น |
|---|---|
| ขอบเนื้อหา | ซ้าย 14.8 mm, ขวา 10 mm; title เริ่มประมาณ 3 mm จากบน |
| หัวเรื่อง | TH Sarabun New 20.2 pt bold; อยู่กึ่งกลางเนื้อหา |
| ตัวเนื้อหา | TH Sarabun New 15 pt; fallback TH SarabunPSK / Angsana New / serif |
| กรอบหลักส่วน 1–3 | x ประมาณ 14.8 mm, y 23.8 mm, กว้าง 183.4 mm, สูงขั้นต่ำ 149 mm |
| แนวตั้งแบ่งสองคอลัมน์ | 51.4% / 48.6% ของกรอบหลัก |
| ส่วนที่ 2 | คอลัมน์ขวา สูงขั้นต่ำ 67.7 mm |
| ส่วนที่ 3 | ต่อจากส่วนที่ 2 สูงขั้นต่ำ 80.7 mm |
| ช่องคนขับ | สองคอลัมน์ กว้าง 185.3 mm สูงขั้นต่ำ 25.5 mm |
| เส้นกรอบ | solid 0.25 mm สีดำ |
| เส้นกรอก | dotted 0.2 mm สีดำ |
| ตัวเลือกเดินทาง | radio แสดงเป็นกรอบสี่เหลี่ยมตามภาพ เลือกได้หนึ่งประเภท |
| ตัวเลือกค่าใช้จ่าย | radio รูปวงรีตามภาพ; single-choice เป็นข้อเสนอที่ยังต้องยืนยันกติกา |

Screenshot ต้นฉบับมีพื้นที่กระดาษประมาณ x=7..660, y=2..891 และถูกครอปสั้นกว่าสัดส่วน A4 จึง normalize ตามความกว้างโดยไม่ยืดตัวอักษร และเติมพื้นที่ขาวล่างเมื่อเทียบ A4 ดู `scripts/compare-form.ps1` ไม่ใช้การยืดภาพเพื่ออ้างว่าทุก pixel ตรงกัน

## 3. ส่วนที่ผู้ขอกรอกได้

ใช้ input วางบนเส้นประโดยตรง ทุก input มี aria-label ไม่เพิ่ม label ใหม่ที่เปลี่ยนต้นฉบับ ไม่มี `<form>` หรือปุ่ม submit จึงไม่ส่งข้อมูลเข้า URL/POST และไม่บันทึก localStorage/sessionStorage การเปลี่ยนหน้า/รีโหลดทำให้ข้อมูลที่พิมพ์หายตามขอบเขตต้นแบบ

ส่วนที่ 1 และหมายเหตุมี 33 ช่องข้อความ พร้อม radio เดินทาง 2 ตัวและค่าใช้จ่าย 4 ตัว ช่องวันเวลาใช้ข้อความแยกตามต้นฉบับแทน native date picker ที่จะเปลี่ยน layout ยังไม่มี required validation ทางธุรกิจ

| ตำแหน่ง / input name | การเชื่อมข้อมูลใน Task ถัดไป |
|---|---|
| application_day / application_month / application_year | ประกอบเป็น booking_requests.application_date; เดือนภาษาไทย/พ.ศ. ต้อง normalize ฝั่ง server |
| travel_type | booking_requests.travel_type ตาม within_province/outside_province |
| requester_name | booking_requests.requester_name snapshot; requester_id ต้องมาจาก actor ที่ตรวจสอบแล้ว |
| requester_position | booking_requests.requester_position |
| requester_affiliation / requester_affiliation_continued | รวมเป็น requester_affiliation เดียว เก็บการขึ้นบรรทัดเมื่อเหมาะสม ไม่เพิ่มสองคอลัมน์ซ้ำเพื่อ layout |
| destination / purpose | booking_requests.destination / purpose ตามลำดับข้อความ “ใช้รถตู้ ไป…” และ “เพื่อ…” |
| passenger_count | booking_requests.passenger_count; ไม่บังคับรายชื่อทุกคน |
| departure_date / departure_time | requested_start_at; แปลงวันเวลา Bangkok → UTC หลัง validate |
| return_date / return_time | requested_end_at; ต้องเริ่มก่อนสิ้นสุด ไม่มี buffer |
| expense_source | ตัวเลือกต้นฉบับ institute_budget / project_budget / other_expenses / **driver_extra_pay** ดู schema discrepancy ด้านล่าง |
| project_expense_details / expense_source_details | รวม/แยกความหมายรายละเอียดค่าใช้จ่ายตามกติกาที่จะยืนยัน; candidate มี expense_source_details ช่องเดียว ไม่ concatenate แบบสูญเสียความหมาย |
| requester_signature | ชื่อพิมพ์ในช่องลงชื่อสำหรับต้นแบบเท่านั้น ไม่ใช่การยืนยันตัวตนหรือลายเซ็นที่มีผลทางกฎหมาย ไม่ map เป็น approved signature อัตโนมัติ |
| requester_printed_name | ชื่อในวงเล็บ; เสนอให้ผูกจาก requester_name ใน implementation จริงเพื่อไม่เก็บชื่อซ้ำ ต้องยืนยันการแก้ชื่อและวิธีลงนาม |
| requester_phone | booking_requests.requester_phone เก็บ string |
| pickup_person_name / pickup_person_phone | คอลัมน์ชื่อเดียวกันใน booking_requests |
| pickup_location / pickup_day / pickup_month / pickup_year / pickup_time | pickup_location / pickup_at; ยังต้องยืนยันความหมายว่ารับผู้เดินทางใด |
| dropoff_location / dropoff_day / dropoff_month / dropoff_year / dropoff_time | dropoff_location / dropoff_at; ไม่ใช้แทน actual_return_at |
| coordinator_name / coordinator_phone | คอลัมน์ชื่อเดียวกัน; label ที่มองเห็นคง “ชื่อผู้ประสาน” ตามภาพ |

ช่อง additional_details ของ candidate ไม่แทรกเป็นหัวข้อใหม่ในเอกสารนี้ เพราะภาพไม่มีช่องแยกที่ระบุชื่อนี้ชัดเจน ต้องยืนยัน mapping ก่อนสร้างฟอร์มจริง

## 4. ส่วนที่แสดงข้อมูลตามสิทธิ์

ส่วนที่ 2, 3 และคนขับใช้ text spans กับเส้นประ ไม่ใช่ inputs ที่ requester แก้ได้ ก่อนถึงขั้นตอนให้แสดงว่าง ไม่ใส่ placeholder “รออนุมัติ” ลงเอกสาร คำอธิบายสถานะต้นแบบอยู่เหนือหน้ากระดาษเท่านั้น

| ส่วน / data-output | แหล่งข้อมูลในอนาคต / Guard |
|---|---|
| เลขที่ request_number | booking_requests.request_number สร้างฝั่ง server ไม่รับจาก requester |
| ส่วนที่ 2 vehicle_registration / driver_name | assignments → vehicles/drivers; ต้องมี approved decision ก่อนจัดจริง ห้ามให้ requester ส่ง FK หรือทะเบียนมาเปลี่ยน |
| officer_printed_name / officer_day/month/year | assigned_by/assigned_at → ชื่อเจ้าหน้าที่/วันที่; ข้อมูล review/proposal อยู่ booking_reviews แยกจากการจัดจริง |
| officer_signature | วิธีลงนามยังรอยืนยัน ไม่สร้างภาพลายเซ็นจำลอง |
| approval_outcome / rejection_reason และ continued | approval_decisions.outcome/reason; ชื่อบนเอกสาร “อนุญาต / ไม่สามารถจัดรถให้ได้” ตามภาพ ไม่ใช่อำนาจให้ officer เปลี่ยนผลเอง |
| approver_printed_name / approver_position / decision_day/month/year | decided_by/authority_kind/decided_at และตำแหน่ง snapshot ที่ต้องยืนยันแบบเก็บข้อมูล; role approver อย่างเดียวไม่พอ ต้องตรวจขอบเขต/ช่วงเวลา/การมอบอำนาจ backend |
| driver_signature / acknowledging_driver_name | หลักฐานการรับทราบและชื่อ driver ของ assignment; ขั้นตอนให้เจ้าหน้าที่บันทึกยังรอยืนยัน ไม่มี driver Login |
| actual_return_day/month/year/time | vehicle_assignments.actual_return_at; ไม่ใช้ ends_at ที่เป็นกำหนดการ; ผู้บันทึก/หลักฐานตามกติกาที่จะยืนยัน |

หน้าต้นแบบเปิด mock ผ่าน query enum ที่ allowlist เท่านั้น ไม่รับ actor ID หรืออ่านข้อมูลจริง เมื่อทำระบบจริงต้องมี DTO ที่แยก requester-editable fields กับ server-only outputs และ backend ตรวจ role+scope+state ทุก mutation การใช้ read-only HTML ไม่ใช่การบังคับสิทธิ์ฝั่ง server

## 5. ข้อแตกต่างของ schema ที่พบจากภาพ

อัปเดต Task 03 Phase A: แก้ candidate enum เป็น driver_extra_pay แล้วโดยได้รับขอบเขตแก้ candidate ใน Task 03 แต่ยังไม่รัน migration หรือเปลี่ยน UI ข้อความถัดไปบันทึกเหตุผลจากงาน UI ก่อนหน้าและข้อกติกาที่รอยืนยัน

candidate เก่าใช้ `driver_personal_funds` จาก prompt ก่อนเห็นต้นฉบับ แต่ภาพระบุ **“เงินเพิ่มพิเศษพนักงานขับรถ”** จึงห้าม map ตัวเลือกใหม่ `driver_extra_pay` ไป enum เก่าโดยเงียบ ๆ ต้องตกลงว่าเป็นแหล่งเงิน/การจ่ายเพิ่มแยกจาก budget source หรือเลือกควบคู่กันได้ แล้วแก้ candidate/check constraint ใน Task ที่อนุญาตให้ปรับ schema งานนี้ไม่สร้างหรือแก้ migration

ยังต้องยืนยัน: project expense line กับบรรทัดรายละเอียดล่างมีความหมายต่างกันหรือไม่, วิธีลงนาม, requiredness, จำนวนผู้เดินทางรวมผู้ขอหรือไม่ และรูปแบบเลขเอกสาร ภาพบอกตำแหน่งข้อความได้แต่ไม่ได้ยืนยัน business rules ทั้งหมด

## 6. Desktop / Mobile / Print

- Desktop: A4 กึ่งกลางหน้าจอ คลิกกรอกบนเส้นประโดยตรง มี focus outline เฉพาะตอนกรอก ไม่เปลี่ยนเส้นเอกสาร
- Mobile: paper คงประมาณ 794 CSS px ใน viewport 390 px; scroll container แนวนอน ไม่บีบตาราง เปิด browser pinch zoom ได้ (ไม่มี maximum-scale/user-scalable=no) ยังไม่ได้ทดสอบ gesture บนอุปกรณ์จริง
- Print: @page A4 portrait margin 0; ซ่อน toolbar/คำอธิบาย/ปุ่ม และเงา; แสดง textContent mirror ของ input ในตำแหน่งเดิม; native header/footer ของ printer ให้ปิดใน print dialog และตั้ง scale 100%
- ตัวอย่างปกติพิมพ์หนึ่งหน้าได้ ทดสอบตำแหน่ง field screen/print หลังชดเชยตำแหน่ง paper แล้ว drift ไม่เกิน 1 px ใน Chrome บนเครื่องนี้
- ข้อความยาวมีคำเตือนก่อนพิมพ์และ wrap ใน print โดยไม่ใช้ ellipsis, maxlength หรือตัดข้อมูล อาจขยายเกินหนึ่ง A4 และต่างจากช่องต้นฉบับ จึงไม่รับรองหนึ่งหน้าสำหรับข้อความยาวทุกกรณี ก่อน production ต้องตกลงกติกาตัดบรรทัด/แผ่นต่อโดยไม่เปลี่ยนข้อความผู้ใช้

## 7. วิธีเปิดและตัวอย่าง

จาก project root รัน `php -S 127.0.0.1:8088 -t public` แล้วเปิด:

- `/form-preview` เอกสารว่าง
- `/form-preview?example=requester` ผู้ขอกรอกแล้ว ส่วนเจ้าหน้าที่ว่าง
- `/form-preview?example=assigned` ตัวอย่างอนุมัติและจัดรถแล้ว
- `/form-preview?example=rejected` ตัวอย่างผลไม่อนุญาต ไม่มีรถที่จัด
- `/form-preview?example=completed` ตัวอย่างคนขับและเวลากลับจริง

ทุก example มาจาก resources/fixtures/form-preview.php เป็นข้อมูลจำลอง การเปิด example ไม่ทำ state transition หรือจัดรถจริง หน้าเดิม `/` และระบบ Task 01 ไม่เปลี่ยนหน้าตา

## 8. ผลเทียบและข้อจำกัดที่ต้องรายงาน

ดู `docs/form-preview/reference-vs-web.png` ซ้าย=ต้นฉบับ normalize ตามความกว้าง ขวา=web blank และ `docs/form-preview/browser-results.json` สำหรับตำแหน่งทุกช่อง

กรอบหลัก/เส้นแบ่งคอลัมน์/ส่วนคนขับใกล้ reference ในระดับประมาณ 1–3 CSS px หลัง normalize แต่ **ไม่เหมือน 100%**: ฟอนต์/น้ำหนัก/anti-aliasing และความถี่จุดต่างจาก raster; ข้อความในบางบรรทัดและช่องลงชื่อยังเลื่อนได้หลาย pixel; ภาพต้นฉบับครอปกระดาษสั้นกว่า A4; ข้อมูลที่เคยกรอกบนภาพถูกเว้นว่างหรือแทนด้วย mock; ข้อความยาวทำให้แถวขยาย

ยังไม่ได้ทดสอบพิมพ์กระดาษจริง, browser อื่น, อุปกรณ์ touch จริง หรือเครื่องที่ไม่มีฟอนต์ไทยนี้ ไม่ bundle ฟอนต์ระบบ Windows ลง source เพราะยังไม่ได้ตรวจสิทธิ์แจกจ่าย

รายละเอียดผลตรวจและไฟล์ทั้งหมด: [form-ui-verification.md](form-ui-verification.md)

## 9. Pixel refinement และสีหมึก — ข้อกำหนดเพิ่มเติมล่าสุด

ใช้ skill ui-ux-pro-max ตรวจ fixed document layout, focus, keyboard/แตะกรอกและ print โดยรักษารูปแบบเอกสารเดิม ไม่ใช้ CSS transform scale เพื่อบีบ A4 ไม่เพิ่ม dependency หรือเปลี่ยน workflow/RBAC/schema

- ค่าที่กรอก, caret, print-value, ข้อมูลเติมลง document-output และจุดเลือกใช้ `--ink-blue: #1547A0` (RGB 21,71,160) ให้ความรู้สึกหมึกปากกา ไม่เปลี่ยนเป็นฟอนต์ลายมือหรือบิดตัวอักษร
- หัวข้อ ข้อความต้นฉบับ กรอบ เส้นประ และเครื่องหมายทับของเลขเอกสารว่างคงสีดำ การใช้สีน้ำเงินเป็นข้อกำหนดเพิ่มจากผู้ใช้ จึงต่างจากเงื่อนไขขาว/ดำเดิมเฉพาะข้อมูลที่เติม
- คงสีหมึกใน @media print พร้อม print-color-adjust: exact; browser tests ตรวจ computed color ทั้งหน้าจอและ print
- ปรับ title เป็น 20.2 pt, ช่องเลขที่กว้าง 30.5 mm, checkbox 6.75 × 5.8 mm, วงรีสูง 4.3 mm (ส่วนผลพิจารณากว้าง 7.6 mm)
- ปรับเส้นกรอบหลักและ divider ตาม reference, ช่องวัน/เดือนของวันที่ยื่น, สัดส่วนตำแหน่ง/สังกัด, แนวลงชื่อผู้ขอ/เจ้าหน้าที่/ผู้พิจารณา และช่องวันที่คนขับ โดยไม่เปลี่ยนข้อความคงที่
- ปีของเจ้าหน้าที่มีพื้นที่ 12 mm แยกจากวัน/เดือน เพื่อให้ 4 หลักไม่ตัดบรรทัด ตัวอย่างทุกแบบต้องไม่ขยายกรอบหลักเกิน baseline ปกติและยังพิมพ์ A4 หน้าเดียว
- ทดสอบ blank/requester ที่ 1920×1080, 1366×768, 390×844 และ 360×800 รวม 8 viewport cases; mobile จำลอง touch เพื่อแตะและกรอก ไม่ใช่อุปกรณ์จริง

หลักฐานล่าสุด: `docs/form-preview/refinement-before-after.png` (ซ้ายก่อน/ขวาหลัง), `reference-overlay.png` (ต้นฉบับ normalize ผสมเว็บที่ opacity 50% เฉพาะไฟล์ QA), `reference-vs-web.png` และ browser-results.json ภาพก่อนปรับเก็บใน refinement-before/ ไม่แก้ภาพต้นฉบับ
