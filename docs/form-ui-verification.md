# ผลตรวจต้นแบบแบบฟอร์ม A4 — Task 02 UI

วันที่ 6 พฤศจิกายน 2026 (Asia/Bangkok)

## รอบล่าสุด — Pixel refinement และหมึกสีน้ำเงิน

แก้ source 5 ไฟล์: public/assets/form-preview.css, tests/browser/form-preview.cjs, scripts/compare-form.ps1, docs/form-ui-specification.md และรายงานฉบับนี้ รวม artifacts ที่สร้างใหม่ ไม่แก้ View/ข้อความไทย/route/fixture, ฐานข้อมูลหรือ migration, workflow/RBAC, Lost & Found, Login หรือ Task 03 ไม่เพิ่ม dependency ไม่ทำ Git commit

ปรับข้อมูลที่กรอกและข้อมูลแสดงในช่องเป็นหมึก #1547A0 ทั้งจอและ print พร้อม caret/จุดเลือกสีน้ำเงิน ข้อความคงที่ กรอบและเส้นประยังดำ ปรับ title/เลขเอกสาร/ขนาด checkbox/วงรี/แนวคอลัมน์/เส้นกรอกและแนวลายเซ็น/วันที่โดยใช้หน่วย mm ไม่ scale เอกสาร

### ผลตรวจรอบ refinement ที่รันจริง

| รายการ | ผล |
|---|---|
| Desktop 1920×1080 | blank/requester ผ่าน A4 กึ่งกลางและช่องอยู่ในกรอบ |
| Desktop 1366×768 | blank/requester ผ่าน A4 กึ่งกลางและช่องอยู่ในกรอบ |
| Mobile 390×844 | blank/requester ผ่าน fixed A4, scroll และ touch tap/fill จำลอง |
| Mobile 360×800 | blank/requester ผ่าน fixed A4, scroll และ touch tap/fill จำลอง |
| Print A4 ปกติ | requester/assigned/rejected/completed พิมพ์หนึ่งหน้า; ตรวจ PDF page count และ normal grid |
| ตำแหน่ง screen → print | maximum field drift 1 CSS px หลังหักตำแหน่ง paper |
| สีหน้าจอ/พิมพ์ | input/print-value/output เป็น rgb(21,71,160), title และ dotted border สีดำ |
| ภาษาไทยยาว | มีคำเตือนก่อนพิมพ์ และ wrap โดยไม่มีการตัดเนื้อหา; อาจหลายหน้า |
| Browser runtime | ไม่มี JavaScript/CSP/console errors ในรอบผ่าน |
| PHP syntax | ผ่านทั้ง 19 ไฟล์ |
| JavaScript syntax | node --check ผ่านทั้ง form-preview.js และ browser test |
| Regression | smoke 7 รายการและ design contract 283 checks ผ่าน ไม่มี DB connection |

พบปีของเจ้าหน้าที่ wrap ระหว่างตรวจ screenshot แล้วเพิ่มความกว้าง year พร้อม test normal fixture ทุกแบบ ก่อนรันทดสอบยืนยันรอบสุดท้าย ทุกผลข้างต้นเป็นหลังแก้ ไม่อ้างจากภาพก่อนแก้

### Before / After / Overlay

- [ก่อน/หลัง พร้อมข้อมูลหมึกน้ำเงิน](form-preview/refinement-before-after.png): ซ้ายก่อนปรับ ขวาหลังปรับ
- [ต้นฉบับ/เว็บล่าสุด](form-preview/reference-vs-web.png): ซ้าย source ขวาเว็บ
- [Overlay](form-preview/reference-overlay.png): normalize source ตามขอบกระดาษแล้วผสม opacity 50% ไม่ยืดสัดส่วนตัวอักษร
- ภาพทั้ง 8 viewport cases ชื่อ blank/requester-WIDTHxHEIGHT.png และ PDF แยก requester/assigned/rejected/completed-a4.pdf
- ข้อมูลตำแหน่ง/สี/viewport ล่าสุดอยู่ browser-results.json

หลัง refinement: main table (55.92,89.94) ขนาด 693.16×565 CSS px เทียบ reference ที่วัดโดยประมาณ (55.93,89.98) ขนาด 693.08×565.4 px; divider x=412.17 เทียบ source ประมาณ 412.2 px; driver table x=55.92, y=699.14, กว้าง 700.34 px จุดเหล่านี้ใกล้กันขึ้น แต่ไม่ได้เป็นคะแนนความเหมือนของข้อความทั้งเอกสาร

### ความคลาดเคลื่อนและความพร้อม

ยังไม่เหมือน 100% ฟอนต์/น้ำหนัก/anti-aliasing ความถี่จุด และ baseline ของข้อความบางบรรทัดยังต่างจากภาพ โดยเห็น ghosting ใน overlay บางส่วน ภาพ source เป็น screenshot ที่ crop สั้นกว่า A4 ตัวอย่างค่าทะเบียน/คนขับต่างจากข้อมูลที่กรอกใน source โดยตั้งใจเพื่อใช้ mock และข้อมูลหมึกน้ำเงินเป็นคำขอเพิ่มล่าสุด ไม่ใช่การจำลอง raster ทุก pixel

ข้อความยาวขยายเอกสารเพื่อเก็บข้อมูลครบ ยังไม่รับรองเอกสารหนึ่งหน้าสำหรับทุกความยาว ไม่ทดสอบกระดาษจริง อุปกรณ์ touch จริง browser อื่น หรือเครื่องไม่มีฟอนต์ไทยนี้ ต้นแบบพร้อมให้ review ต่อ แต่การเชื่อมข้อมูล/requiredness/expense mapping/ลายเซ็นและ authority ต้องยืนยันก่อน Task 03 และยังไม่ได้เริ่ม Task 03

---

## สิ่งที่ส่งมอบ

เปิด `/form-preview` บน PHP localhost หรือ IIS ของโปรเจกต์นี้ มีหน้าเอกสารว่างและ fixture requester/assigned/rejected/completed ไม่มีการบันทึกหรือส่งคำขอ การกดเลือกตัวอย่างไม่เปลี่ยนข้อมูลใดในระบบจริง

### ไฟล์ที่สร้าง

- `app/Controllers/FormPreviewController.php` — เลือกตัวอย่างผ่าน allowlist; ไม่เชื่อม DB
- `app/Views/form-preview.php` — HTML ตามโครงเอกสาร มี 33 text inputs เฉพาะส่วนที่ 1/หมายเหตุ และ travel/expense radio; ส่วนอื่นเป็น outputs
- `public/assets/form-preview.css` — A4, กรอบ/เส้นประ, mobile scroll และ @media print
- `public/assets/form-preview.js` — sync ข้อความพิมพ์ผ่าน textContent, เตือนข้อความล้น, เรียก print ไม่มี submit/fetch/storage
- `public/assets/form-preview-icon.svg` — icon สำหรับ browser tab ไม่อยู่ในหน้ากระดาษ
- `resources/fixtures/form-preview.php` — ข้อมูลจำลองห้าแบบ ไม่มีบัญชีหรือข้อมูลติดต่อจริง
- `tests/browser/form-preview.cjs` — Chrome headless visual/behavior checks
- `scripts/compare-form.ps1` — สร้างภาพเทียบโดย normalize ความกว้างของ source ไม่ยืดอักษร
- `docs/reference/original-form.png` — สำเนาภาพต้นฉบับที่ผู้ใช้แนบ โดยไม่เปลี่ยนไฟล์ต้นทาง
- `docs/form-ui-specification.md` และรายงานฉบับนี้
- `docs/form-preview/` — screenshots, comparison, JSON metrics และ sample PDF

### ไฟล์ที่แก้ไข

- `routes/web.php` — เพิ่ม GET /form-preview; route หน้าแรกเดิมคงเดิม
- `package.json`, `package-lock.json` — เพิ่ม playwright-core เป็น dev dependency ใช้ Chrome ที่มีอยู่ ไม่ดาวน์โหลด browser
- `README.md` — วิธีเปิด/ทดสอบต้นแบบและลิงก์เอกสาร
- `docs/task02-design.md`, `docs/task02-verification.md` — อัปเดตว่ารับภาพต้นฉบับแล้วและบันทึก expense enum discrepancy โดยไม่แก้ migration

ไม่มีการเปลี่ยนหน้าแรกเดิม, users/roles/user_roles/audit_logs, candidate SQL, ฐานข้อมูล, Login/SSO หรือโปรเจกต์ Lost & Found ไม่มีบัญชีจริง Git commit หรืออัปโหลดข้อมูล

## Automated checks ที่รันจริง

| รายการ | ผล |
|---|---|
| PHP lint 19 ไฟล์ | ผ่าน ไม่พบ syntax error |
| node --check public/assets/form-preview.js | ผ่าน |
| node --check tests/browser/form-preview.cjs | ผ่าน |
| php tests/task02-design.php | ผ่าน 283 design contract checks ไม่มี DB connection |
| php tests/smoke.php | ผ่าน 7 รายการเดิม |
| python tests/http-smoke.py | ผ่าน 9 requests รวมหน้าเดิม assets/private paths/CSRF |
| node tests/browser/form-preview.cjs | ผ่าน Chrome headless บน Windows |
| scripts/compare-form.ps1 | สร้าง reference-vs-web.png สำเร็จและเปิดดูเทียบภาพจริง |

Browser checks ตรวจ: GET ต้นแบบ 200, 33 ช่องมีตำแหน่ง/ขนาด/aria-label, ไม่มี input ในส่วนเจ้าหน้าที่/ผลพิจารณา/คนขับ, ไม่มี form submit, ตัวอย่างผู้ขอไม่มีทะเบียน/คนขับ, fixture อนุมัติและไม่อนุญาตเลือกวงกลมถูกแบบ, fixture กลับจริงแสดงเวลา, query example ผิดชนิด fallback เป็นเอกสารว่าง, typed value sync ไป print, ข้อความ HTML เป็น text ไม่สร้าง DOM, และไม่มี JavaScript/CSP/console errors ในการทดสอบสุดท้าย

ระหว่างพัฒนาพบและแก้: จำนวนช่องใน test, ชื่อตัวแปร data ที่ชนกับ View renderer เดิม, PDF ล้นสองหน้า, line height ภาษาไทย, และ favicon 404 ผลตารางด้านบนมาจากการรันหลังแก้ ไม่ใช่ผลก่อนแก้

## หลักฐาน Desktop / Mobile / Print

- `blank-desktop.png`: หน้าว่างบนกระดาษขาว ส่วนที่ยังไม่มีข้อมูลว่างตามเอกสาร
- `requester-desktop.png`, `assigned-desktop.png`, `rejected-desktop.png`, `completed-desktop.png`: ตัวอย่างสถานะของข้อมูล ไม่มี action ดำเนินงานจริง
- `mobile-left.png`, `mobile-right.png`: viewport 390 × 844 px, paper กว้าง 793.69 px, scroll area 818 px แสดงการเลื่อนสองฝั่งโดยไม่บีบตาราง
- `requester-print.png`: print CSS ซ่อน toolbar/ปุ่ม แสดงข้อมูลที่กรอกในช่องเดิม
- `requester-a4.pdf`: sample A4 portrait **หนึ่งหน้า** MediaBox ประมาณ 594.96 × 841.92 pt (Chrome rounding); ไม่รวม browser header/footer
- `long-content-print.png`: ข้อความยาว wrap โดยไม่ clip เนื้อหา แต่หน้าเอกสารขยายและอาจพิมพ์หลายหน้า
- `browser-results.json`: เก็บตำแหน่งทุกช่องเทียบกับ paper origin, dimensions, PDF page count และ print drift
- `reference-vs-web.png`: ซ้ายต้นฉบับ ขวาเว็บ ทั้งสองเทียบตามความกว้างกระดาษ

ตำแหน่ง wrapper ของช่องกรอก screen/print หลังหักตำแหน่ง paper ที่ย้ายเมื่อซ่อน toolbar มี maximum drift **1 CSS px** ใน sample ปกติ ไม่ได้แปลว่าช่องทุกช่องตรงกับ raster source ภายใน 1 px

ตัวอย่างกรอบหลัง normalize source จาก paper crop (7,2,653,889): main table ของภาพประมาณ (55.93,89.98) ขนาด 693.08 × 565.4 px; เว็บล่าสุดประมาณ (55.92,89.94) ขนาด 693.16 × 565 px ส่วน driver table ภาพเริ่มประมาณ y=699.16 px เว็บ y=699.14 px ค่าเหล่านี้อ้างการวัดกรอบในภาพหน้าจอ ไม่ใช่พิกัดจากไฟล์ Word/PDF ต้นฉบับ

## ส่วนที่ยังต่างจากต้นฉบับ / ยังไม่ได้ทดสอบ

1. ไม่อ้างว่าเหมือน 100% ฟอนต์ที่ใช้เป็น TH Sarabun New และ fallback; ภาพต้นฉบับไม่บอก font/pt ที่แท้จริง น้ำหนักตัวอักษร anti-aliasing ความถี่เส้นประและตำแหน่งข้อความบางบรรทัดยังต่างหลาย pixel
2. ต้นฉบับที่แนบเป็น screenshot สัดส่วนกระดาษถูก crop สั้นกว่า A4 ต้นแบบรักษา A4 จริง จึงมีขอบขาวล่างต่างจากภาพ
3. ค่าทะเบียน/ชื่อคนขับที่อยู่ในภาพเป็นข้อมูลที่กรอกไว้ ต้นแบบว่างไม่คัดลอกค่าเหล่านั้น ส่วน fixture ใช้ค่าจำลอง; ไม่สร้างภาพลายเซ็น ช่องลงชื่อผู้ขอรับเพียงชื่อพิมพ์สำหรับต้นแบบ
4. ข้อมูลยาวเกินช่องจะเตือนและขยายแถวเมื่อพิมพ์ จึงไม่รับรองความเหมือนหรือหน้าเดียวในกรณียาวทุกแบบ ต้องตกลงข้อจำกัด/แผ่นต่อก่อน production
5. ทดสอบ viewport จอแคบและ CSS print/PDF ใน Chrome แล้ว แต่ยังไม่ทดสอบ pinch gesture บนอุปกรณ์จริง, Safari/Firefox/Edge, เครื่องไม่มีฟอนต์นี้ หรือเครื่องพิมพ์กระดาษจริง
6. ไม่เชื่อม MySQL ไม่สร้าง/แก้/รัน migration ไม่ทดสอบการอนุมัติ/RBAC จริง เพราะงานนี้เป็น prototype ไม่มี persistence

## ประเด็นก่อนเชื่อมข้อมูลจริง

สำคัญ: ภาพยืนยันว่า expense option คือ **เงินเพิ่มพิเศษพนักงานขับรถ** ไม่ใช่ “เงินส่วนตัวของพนักงานขับรถ” UI ใช้ driver_extra_pay ส่วน candidate เดิมยังเป็น driver_personal_funds ไม่ใช่ mapping ที่พร้อมใช้งาน ต้องตกลงความหมาย/single หรือ multiple choices และแก้ schema ภายใต้ Task ที่อนุญาต ห้ามส่งค่า UI ตรงเข้า enum เก่า

รายละเอียด field mapping, requiredness ที่รอยืนยัน, การลงนาม, แหล่งข้อมูล read-only และ backend guards อยู่ [UI Specification](form-ui-specification.md)

## วิธีรันซ้ำ

```powershell
cd C:\จองรถตู้\up-van-booking
php -S 127.0.0.1:8088 -t public
# อีก terminal:
node tests/browser/form-preview.cjs
powershell -NoProfile -File scripts/compare-form.ps1
```

ต้องมี dependencies จาก npm ci และ Google Chrome ติดตั้ง การทดสอบทั้งหมดใช้ localhost และ artifacts ภายในโปรเจกต์ ไม่เรียกฐานข้อมูล
