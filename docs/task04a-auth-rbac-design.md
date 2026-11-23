# Task 04A — Authentication & RBAC Architecture

**สถานะ:** Design & Readiness เท่านั้น — ไม่พัฒนา Login, ไม่แก้ Schema, ไม่เชื่อมฐานข้อมูล  
**ตรวจ Source:** 2026-11-09 (Asia/Bangkok)  
**ฐานข้อมูล:** อ้างอิง Migration 001–003 และผล Stage D ในเอกสารโครงการเท่านั้น ไม่มีการเชื่อมต่อ MySQL ใน Task นี้

## 1. Existing Architecture Inventory

| ส่วน | สิ่งที่มีจริง | ข้อสังเกต |
|---|---|---|
| Web entry / routing | `public/index.php`, `routes/web.php`, IIS rewrite ใน `public/web.config` | Front controller จับคู่ method + path แบบ array; มี GET `/` และ `/form-preview` เท่านั้น; ไม่มี POST route หรือ route middleware |
| Controllers / Views | `HomeController`, `FormPreviewController`, Views ใน `app/Views` | ไม่มี Auth/Login/Logout/User Admin controller; หน้าแบบฟอร์มเป็น prototype ไม่บันทึกข้อมูล |
| Model / Service | `Models/User::findById()` ใช้ Prepared Statement; `Services/AuditLogger`; `ModuleCatalog` | User model ยังไม่ค้นหาด้วย email หรือโหลด roles; ไม่มี AuthService, Repository layer, Policy หรือ Middleware |
| Database | `Core\Database` เรียก `ConnectionFactory`; ConnectionFactory สร้าง PDO, exception mode, native prepares และปิด multi-statements | Source ไม่พบ `mysqli`; ไม่ได้เชื่อมต่อฐานข้อมูลเพื่อตรวจสิทธิ์หรือข้อมูลจริง |
| PHP Runtime | Project CLI PHP 8.4.27; เมื่อโหลด extension ตาม wrapper มี PDO driver `mysql`; ไม่พบ mysqli extension | ตรวจ CLI ได้เท่านั้น ไม่ยืนยัน IIS FastCGI ว่าใช้ runtime/config เดียวกัน; `password_algos()` มี Argon2id |
| Session | `bootstrap/app.php` เปิด session ทุก Web Request; strict mode และ cookie-only; ตั้งชื่อ `up_van_session`; เก็บใน `storage/sessions` | Cookie เป็น HttpOnly, SameSite=Lax; Secure ขึ้นกับ `SESSION_SECURE` ซึ่งค่า default ใน Source คือ `false`; ยังไม่มี timeout, session rotation หลัง login หรือ logout flow |
| CSRF | `Core\Csrf` สร้าง token 32 random bytes และตรวจด้วย `hash_equals()`; Front controller ตรวจ POST ก่อน route | พื้นฐานใช้ได้ แต่ยังไม่มี POST route/form; token ยังไม่มีการ rotate เมื่อเปลี่ยนสถานะ authentication |
| Output / UI | `e()` escape ด้วย `htmlspecialchars`; Tailwind CSS build ไว้ใน `public/assets`; SweetAlert2 อยู่ใน assets และใช้แจ้งสถานะหน้าแรก | ไม่มี Login UI หรือ login error flow; SweetAlert2 เป็น UI dependency ไม่ใช่ security boundary |
| Error / Logs | `public/index.php` ส่ง exception ไป `error_log`; PHP log อยู่ใต้ `storage/logs`, นอก Public | `error_log($error->__toString())` อาจบันทึก stack/รายละเอียดภายใน จึงควรเปลี่ยนเป็น structured, redacted logging ก่อนเพิ่ม Auth |
| Audit | `audit_logs` และ `AuditLogger` มี Prepared Statement; user_id เป็น nullable; metadata JSON | ไม่มี System Log service/table แยก; Logger กำหนดให้ caller allowlist metadata และห้ามใส่ password/token/credentials |

### ตรวจ Migration และ Role Model

- `001_identity.sql` สร้าง `users`, `roles`, `user_roles`, `audit_logs`.
- `users` มี `email` UNIQUE, `display_name`, `password_hash VARCHAR(255) NULL`, `is_active`, timestamps; comment ระบุว่าค่า password_hash ต้องเป็นผลจาก `password_hash()` และ NULL รองรับ SSO ในอนาคต
- `user_roles` ใช้ `(user_id, role_id)` เป็น Primary Key, มี `assigned_at` และ Foreign Keys; ไม่มี `assigned_by`.
- `audit_logs` มี actor ที่ nullable, action/entity, metadata JSON และเวลา; ไม่มี IP/session fields.
- `003_roles.sql` seed สี่ role: `requester`, `vehicle_officer`, `approver`, `admin` ไม่มีบัญชีผู้ใช้จริง.
- Role ของ Director / Delegate ใน Schema คือ `approver`; `authority_kind` และ `authority_reference` อยู่ใน `approval_decisions` เพื่อบอกชนิดอำนาจในผลพิจารณา ไม่ได้สร้าง RBAC role แยก และไม่ได้พิสูจน์เอกสารมอบอำนาจ.
- ตรวจ Source/Migrations แล้วไม่พบ `auth_identities`, ตาราง login attempts หรือ auth/session version.
- เอกสาร `task03-stage-d-readiness.md` บันทึก Stage D PASS จากผลที่ผู้ดูแลรายงาน รวม 103 checks บนฐานทดสอบ; เป็นผล Database Integration ไม่ใช่หลักฐานการติดตั้ง Login หรือการตรวจฐานข้อมูลสดใน Task 04A.

## 2. Authentication Design

### บัญชีภายในระยะแรก

- ไม่มี self-registration; Admin ที่ได้รับอนุญาตสร้างบัญชีให้ผู้ใช้ โดยบัญชีใหม่ได้ role `requester` จากฝั่ง Server เสมอ
- ใช้ `users.email` เป็นตัวค้นหาบัญชีในระยะแรก และ `users.password_hash` เก็บเฉพาะ hash; ห้ามส่งหรือแสดง hash ให้ View, URL, Log หรือ API
- ใช้ `password_hash()` / `password_verify()` และ `password_needs_rehash()`; Runtime ที่ตรวจมี Argon2id ให้เลือก ใช้ Argon2id พร้อมกำหนด memory/time/threads จากการ benchmark บน IIS; fallback bcrypt ทำได้เมื่อ runtime deployment ไม่มี Argon2idและกำหนด cost ที่เหมาะสม
- ตรวจ `is_active` หลังตรวจ credential สำเร็จ; ตอบข้อความผิดพลาดแบบเดียวกันสำหรับ email ไม่มี, password ผิด และบัญชีปิด เพื่อไม่เปิดเผยการมีอยู่ของบัญชี
- ทุก query ใช้ PDO Prepared Statements; การ update hash หลัง rehash หรือ reset ใช้ Service/Repository ไม่ทำใน Controller/View
- ห้ามรับ role, `user_id`, active flag หรือสิทธิ์จาก Form/URL/Client มาใช้เป็น authority

### การป้องกัน Brute Force

ใช้ rate limit ฝั่ง Server แบบรวมศูนย์สองมิติ: identifier bucket และ source/IP bucket, ใช้หน้าต่างเวลาและ backoff/temporary lock ที่ปรับได้, ตอบกลับข้อความทั่วไปเหมือนกัน และไม่ lock บัญชีถาวรจากการลองผิดที่อาจถูกใช้ DoS ผู้ใช้ เป้าหมาย threshold และระยะเวลายังเป็น Open Question

แนะนำเก็บ bucket เป็น HMAC digest ไม่เก็บ email/IP ดิบในตาราง counter; secret สำหรับ HMAC ต้องอยู่ใน Environment/Secret Store นอก Public/Source และไม่ใช่รหัสผ่านบัญชี DB. ล้าง bucket หมดอายุด้วยงานบำรุงรักษาที่กำหนดขอบเขตแน่นอน หากเลือกใช้ IIS rate limiting เพิ่ม ให้ถือเป็นชั้นเสริม ไม่แทน application-level control.

## 3. RBAC Permission Matrix

ตรวจสิทธิ์ที่ Server ทุก Request ผ่าน Middleware/Policy และ query scope; ซ่อนปุ่มใน View เป็นเพียง UX ไม่ใช่การอนุญาต

| ความสามารถ | Requester | Vehicle Officer | Director / Delegate (`approver`) | Admin |
|---|---|---|---|---|
| สร้าง Draft | ได้ | ไม่ใช่สิทธิ์ปกติ | ไม่ได้ | ไม่ได้โดยปริยาย |
| แก้ Draft | ได้ เฉพาะของตนและเฉพาะสถานะ Draft | ไม่ได้ | ไม่ได้ | ไม่ได้โดยปริยาย |
| ส่งคำขอ / ดูสถานะ | ส่งและดูเฉพาะคำขอตน | ดูคิวงานและคำขอที่ได้รับมอบหมาย | ดูเฉพาะคำขอรอพิจารณา | ดูรายงานตามสิทธิ์ที่ยืนยัน; ไม่ได้สิทธิ์ workflow โดยอัตโนมัติ |
| ตรวจสอบ / เสนอผู้อำนวยการ | ไม่ได้ | ได้ | ไม่ได้ | ไม่ได้โดยปริยาย |
| รับผลพิจารณา | ดูผลของตน | ได้ | บันทึกผลอนุมัติ/ไม่อนุมัติและส่งกลับเจ้าหน้าที่ | ไม่ได้โดยปริยาย |
| จัดรถ/คนขับ | ไม่ได้ | ได้ เฉพาะคำขอที่อนุมัติแล้ว | ไม่ได้ | ไม่ได้โดยปริยาย |
| จัดการข้อมูลรถ/คนขับ | ไม่ได้ | ได้ | ไม่ได้ | ไม่ได้โดยปริยาย |
| ดูปฏิทิน | เฉพาะรายการที่อนุญาตให้เห็นตามนโยบาย | ได้ | เฉพาะมุมมองที่จำเป็นต่อการพิจารณา | รายงานรวมเมื่อได้รับอนุญาต |
| จัดการบัญชี/บทบาท | ไม่ได้ | ไม่ได้ | ไม่ได้ | ได้ ภายใน allowlist สี่บทบาทและกติกา Admin ปลอดภัย |
| ค่าระบบ / รายงานรวม / Audit & System Logs | ไม่ได้ | ไม่ได้ | ไม่ได้ | ได้ เฉพาะข้อมูลตามหน้าที่และนโยบายเก็บรักษา |

**Admin ไม่ใช่ superuser ของ Workflow โดยนัย:** หากผู้ดูแลต้องทำงาน Requester/Officer/Approver ต้องได้รับ role เพิ่มอย่างชัดเจนและมีเหตุผลตรวจสอบได้ ห้ามให้สิทธิ์ตัดสินใจอนุมัติแก่ Admin อัตโนมัติ

### IDOR และการเปลี่ยนสิทธิ์

- Requester query รายการด้วยเงื่อนไขเจ้าของ เช่น `id = :id AND requester_id = :current_user_id`; ตรวจ owner ซ้ำใน Service ก่อนแก้หรือส่ง ไม่โหลดตาม ID อย่างเดียว
- ตรวจ scope ต่อทุก resource ที่เชื่อมโยง (booking, passenger, assignment, vehicle, driver, attachment ในอนาคต); ใช้ Policy จาก DB-backed current user/roles
- Role assignment รับได้เฉพาะสี่ role ที่ seed ไว้; role ใหม่หรือชื่อ role จาก Client ถูกปฏิเสธ
- ห้ามผู้ใช้เพิ่ม/ถอน `admin` ของตนเอง; กันการถอน/ปิดบัญชี Admin คนสุดท้ายด้วย Transaction/Lock และ Audit; การแต่งตั้ง Admin อื่นต้องเป็น Admin ที่มีสิทธิ์และมีเหตุผล
- โหลด `is_active`, `auth_version` และ roles จากฐานทุก Request (หรือ cache ได้เฉพาะใน request เดียว); ไม่เชื่อ role ใน session อย่างเดียว การเปลี่ยน/ถอน role, ปิดบัญชี หรือ reset password เพิ่ม auth_version เพื่อทำให้ session เก่าหมดผลทันที

## 4. Session / CSRF / Password Security

### Session

1. คง `session.use_strict_mode=1`, `session.use_only_cookies=1`, HttpOnly และ SameSite=Lax ที่มีอยู่
2. ใช้ HTTPS บน IIS สำหรับสภาพแวดล้อมที่มีบัญชีจริง และกำหนด `SESSION_SECURE=true`; ห้ามเปิด cookie Secure บน HTTP แล้วคาดหวังให้ browser ส่ง cookie ได้
3. หลัง credential ถูกต้องและบัญชี active: ล้างข้อมูล anonymous session ที่ไม่จำเป็น, เรียก `session_regenerate_id(true)`, หมุน CSRF token แล้วเก็บเฉพาะ user id, auth_version, authenticated_at และ last_activity; roles โหลดจาก DB
4. บังคับ idle และ absolute timeout ใน Auth Middleware; ค่าเริ่มต้นที่เสนอ 30 นาที idle / 8 ชั่วโมง absolute ต้องยืนยันกับผู้ดูแล
5. Logout เป็น POST + CSRF: บันทึก audit ก่อนลบ actor context, ล้าง `$_SESSION`, หมดอายุ cookie ด้วย path/options เดิม, `session_destroy()`; ทำซ้ำได้โดยไม่เปิดเผยว่ามี session หรือไม่

### CSRF

ใช้ Synchronizer Token ใน Session สำหรับทุก POST ที่เปลี่ยนสถานะ รวม login/logout และ Admin actions; ตรวจด้วย `hash_equals()` ผ่านจุดกลางก่อน Controller, ไม่ทำ state change ผ่าน GET; หมุน token หลัง login/logout/สิทธิ์เปลี่ยน และตรวจ Origin/Referer เพิ่มได้เป็น defense-in-depth ไม่แทน token.

### Password / Logging

- Validate ความยาวและ policy ฝั่ง Server โดยรับ password manager/paste; ไม่บังคับเปลี่ยนรหัสตามรอบโดยไม่มีเหตุผลความเสี่ยง
- ใช้ Argon2id ที่ benchmark แล้ว; ห้ามเก็บ plaintext, reversible encryption, password ใน Audit, log หรือ message; ไม่ log session ID, CSRF token, reset token หรือ raw login identifier
- login success/logout/role changes/account disable/password reset บันทึก Audit event ด้วย action ที่จำกัดชุดและ metadata allowlist; failed login ใช้ actor NULL ได้ แต่ไม่บันทึก password/email/IP ดิบ
- เปลี่ยน Web exception logger ให้บันทึก event code + correlation ID และ stack เฉพาะ log ที่ควบคุมสิทธิ์/retention; ห้ามปล่อย PDO exception, SQL, DSN หรือ request body สู่ Client/Log ที่ผู้ใช้ทั่วไปอ่านได้

## 5. Login / Logout Flow

### Login

1. `GET /login` สร้าง CSRF token และแสดง form ที่มี label/error แบบทั่วไป
2. `POST /login` ผ่าน method/route check และ CSRF middleware; normalize identifier ตามนโยบายเดียวกับ UNIQUE email แล้วตรวจ rate-limit buckets ก่อนตรวจ password
3. Repository ดึง user ด้วย Prepared Statement รวม `id,email,password_hash,is_active,auth_version`; ถ้าไม่พบหรือ password_hash เป็น NULL ให้ใช้ข้อความ/เวลาโดยรวมที่ไม่เปิดเผยสถานะบัญชี
4. `password_verify()` และตรวจ active; ความล้มเหลวเพิ่ม counter แบบ atomic และบันทึก event ที่ไม่เก็บ credential
5. เมื่อสำเร็จ อัปเดต hash หาก `password_needs_rehash()`, ล้าง throttling ที่เกี่ยวข้อง, บันทึก login event, regenerate session ID, rotate CSRF และเก็บ session claims ที่ระบุข้างต้น
6. ทุก Request หลัง login ผ่าน Auth Middleware ตรวจบัญชี active/version และโหลด roles จาก DB; Authorization Policy ตรวจสิทธิ์และ owner scope ก่อนดำเนินงาน

### Logout

`POST /logout` ต้องเป็นผู้ใช้ปัจจุบันและผ่าน CSRF → บันทึก logout audit → ล้าง session/cookie/ทำลาย Session → redirect ไปหน้า Login พร้อมข้อความทั่วไป การปิด tab/browser ไม่ถือเป็น logout และ timeout ต้องทำงานแยก

### Admin คนแรก

สร้างด้วย CLI แบบ interactive บนเครื่อง Server เท่านั้น ไม่ผ่าน Web route และไม่ seed credential ใน Migration: รับ email/display name และ password แบบ masked จาก terminal, hash ทันที, Transaction ล็อก role row `admin` แล้วปฏิเสธหากมี Admin อยู่แล้ว, เพิ่ม user + admin role + bootstrap audit event ใน Transaction เดียว ไม่มี default password/secret ใน command line; คำสั่งทำงานซ้ำต้องปฏิเสธ ไม่ reset Admin อัตโนมัติ. ผู้ดูแลสำรองช่องทาง recovery และตรวจเหตุการณ์ bootstrap

## 6. Database Schema Gap Analysis

| ประเด็น | สภาพจาก Migration | ข้อเสนอ |
|---|---|---|
| Local password | `users.password_hash` มีแล้วและ NULL ได้ | เพียงพอสำหรับเก็บ hash แบบ local; ไม่เพิ่มคอลัมน์ซ้ำ |
| Username | ไม่มีคอลัมน์ username ใน `users` | User Management ต้องการ Username ไม่ซ้ำ; เสนอเพิ่ม `username` ใน forward migration พร้อม normalization/unique rule ที่ชัดเจน; ห้ามใช้ email แทนโดยเงียบ ๆ |
| User state | มี `is_active` | ใช้ปิดบัญชี; ต้องตรวจทุก Request และไม่ลบ user ที่มี FK history |
| Roles | `roles`/`user_roles` และ seed สี่ค่าอยู่แล้ว | ใช้เดิม; ห้ามเพิ่ม role Director/Delegate/Document Officer โดยไม่มีการอนุมัติ |
| Session invalidation | ไม่มี version/token state | Migration 004 เสนอ `users.auth_version BIGINT UNSIGNED NOT NULL DEFAULT 1`; เพิ่ม version เมื่อปิดบัญชี, reset password หรือเปลี่ยนสิทธิ์ เพื่อ invalidate Session เก่า |
| Password lifecycle | ไม่มี changed-at/forced-change fields | Migration 004 พิจารณา `password_changed_at` และ `must_change_password` เพื่อรองรับ Admin reset แบบบังคับเปลี่ยน; ยืนยันว่าต้องการ forced reset ก่อนสร้าง Migration |
| Brute-force counters | ไม่มีตาราง | Migration 004 เสนอ `auth_login_buckets`: scope + HMAC bucket digest, counter, window start, locked_until, timestamps และ index สำหรับ expiry; ไม่มี FK ไป users เพราะ identifier อาจไม่มีบัญชี และไม่เก็บ email/IP ดิบ |
| SSO identity | ไม่มี `auth_identities` | Migration แยกในระยะ SSO ภายหลัง: `user_id`, `issuer`, `subject`, provider metadata/timestamps, UNIQUE `(issuer,subject)`, case-sensitive/binary comparison และ FK ไป users; ห้ามใช้ email เป็น primary identity |
| Role grant actor | `user_roles` มี assigned_at แต่ไม่มี assigned_by | ใช้ Audit log เป็นหลักฐาน actor ในระยะแรก; ถ้าต้องมี relational provenance ให้เสนอเพิ่ม FK column ใน migration ภายหลัง |
| System/security events | มี `audit_logs` แต่ไม่มี auth attempt table/system log table | ใช้ AuditLogger สำหรับ business/security events ที่ allowlist; `storage/logs/php.log` เป็น technical log ไม่ใช่หน้าจอ System Logs |
| Runtime DB privileges | เอกสาร Task 03 วาง runtime ไว้เน้น SELECT และ INSERT audit; ไม่มีการตรวจ Grant สดใน Task 04A | Login ต้องอ่าน users/roles/user_roles; rehash/update account ต้องมีสิทธิ์เฉพาะตาราง; throttling ต้องเขียน bucket; Admin account/role management ต้อง INSERT/UPDATE users และ INSERT/DELETE user_roles. ก่อน 04B ต้องทบทวน/เสนอ table-level Least Privilege Grant แยกจาก Migration; ยังไม่เปลี่ยน Grant |
| Account change history | `audit_logs` เก็บ actor/action/entity/metadata/time ได้ แต่ไม่มีหน้า History | บันทึก before/after role/status, เหตุผล และ actor ใน allowlisted metadata; ใช้ audit log เป็นประวัติที่หน้า Admin อ่านได้; password/hash/credential ห้ามเข้า metadata |

### Migration Proposal — ยังไม่สร้าง

เสนอ forward-only `004_auth_security.sql` หลังยืนยันนโยบาย: `username` UNIQUE, auth_version, password lifecycle fields ที่ตกลง, และ `auth_login_buckets` พร้อม PK/index/FK/Constraints ตามแบบ ไม่แก้ Migration 001–003/Manifest/ข้อมูลเดิม ไม่มี DROP/Reset. ต้องกำหนด normalization และ duplicate resolution ก่อนเพิ่ม UNIQUE กับข้อมูลเก่า; ตอนนี้ยังไม่มีบัญชีจริงจาก Migration 001–003 แต่ต้องตรวจอีกครั้งก่อน Migration ในอนาคต. `auth_identities` ควรเป็น Migration แยกในอนาคตเมื่อเริ่ม SSO จริง เพื่อไม่ผูก Task 04B กับ Provider ที่ยังไม่เลือก. Migration ทุกตัวต้องได้รับการ review, checksum/manifest update และอนุมัติก่อนใช้กับฐานทดสอบ; ฐานหลักต้องมี approval แยก

## 7. SSO Extension Design

แยก credential authentication ออกจาก account/authorization:

- `AuthProvider` adapter ให้ผลเป็น verified `(issuer, subject)`; `LocalPasswordProvider`, `MicrosoftOidcProvider`, `CamsProvider` เป็น strategy ในอนาคต
- `IdentityRepository` map exact issuer + subject ไป `users.id` ผ่าน `auth_identities`; email ใช้ติดต่อ/แสดงผล ไม่ใช้ auto-link หรือระบุตัวตนถาวร
- OIDC ต้องตรวจ signature, issuer, audience, nonce, state, expiry และ PKCE ตาม flow ที่เลือก; CAMS adapter แปลง stable provider subject เป็น issuer/subject รูปแบบมาตรฐาน
- ทั้ง Local และ SSO คืน internal `user_id`; RBAC อ่านจาก `roles`/`user_roles` เดิมเท่านั้น ไม่เชื่อ role claim จาก token/provider
- การ link identity ต้องมีการยืนยันตัวตนทั้งสองฝั่งหรือกระบวนการ Admin ที่ตรวจสอบได้; ห้ามผูกบัญชีด้วย email match อย่างเดียว

ยังไม่เชื่อม Office 365 OAuth/OIDC หรือ CAMS SSO ใน Task 04A–04B ตามขอบเขตปัจจุบัน

## 8. Implementation Plan — Task 04B–04D

1. **04B-1 — Authentication Foundation เท่านั้น:** เตรียม auth/session services, repository contracts, middleware/policy interfaces, CSRF/session lifecycle, audit event catalog, safe error logging และ draft forward migration/least-privilege grant plan หลังข้อกำหนดได้รับการยืนยัน. ยังไม่เปิด Login/User Management route, ไม่เชื่อม DB/รัน Migration และไม่เปิด Gate หากยังไม่มีการอนุมัติแยก
2. **04B-2 — Local Login/Logout:** หลัง 04B-1 review และได้รับอนุมัติ จึงพัฒนา endpoints จริง, Argon2id, throttling, session rotation/invalidation, Admin-first CLI bootstrap และทดสอบ service/CSRF/session ก่อนต่อ Test DB ตาม approval แยก
3. **04C — Admin User Management & RBAC:** ทำ backend ก่อน UI; สร้างบัญชีด้วย Username/Email/Display Name, Password/Confirm และ default Requester; admin actions เปลี่ยน role/status/reset password พร้อม reason + Audit; ใช้ 4 roles เท่านั้น; ป้องกัน self-change/last-admin. หน้าสร้างบัญชีอยู่บน ตาราง/ค้นหา/filters/history อยู่ล่าง รองรับมือถือ/แท็บเล็ต/เดสก์ท็อปด้วย Tailwind และ SweetAlert2. ใช้ภาพ Super Admin ที่ผู้ดูแลให้เป็นแนวทาง UI/function เท่านั้น ไม่คัดลอกโค้ด, ข้อมูล หรือฐาน Lost & Found. หน้าจริงต้องยังไม่ register/enable จน Integration Testing ผ่านและมี approval เปิดใช้งาน; ใช้ role/state/session invalidation จาก server.
4. **04D — Security Verification & Operations:** offline tests สำหรับ role matrix, CSRF, session fixation/logout, lockout, IDOR, privilege revocation, self-escalation และ audit redaction; หลังอนุมัติแยกต่างหากจึงทดสอบ Integration เฉพาะ test DB; ตั้ง IIS HTTPS/FastCGI/SESSION_SECURE, log permissions/retention, backup/recovery และคู่มือ Admin; ไม่รวมการเชื่อม SSO จริง

### Acceptance Criteria — Admin User Management

- ไม่มี public signup; สร้างบัญชีได้เฉพาะ Admin ที่ผ่าน Server-side authorization
- Username และ Email ต้องไม่ซ้ำ; แสดง Username, Email, Display Name, Role, Status และวันที่สร้าง; ค้นหา/กรองตามข้อความ, role, active/suspended พร้อม pagination ที่กำหนดเพดาน
- Create form อยู่เหนือ account table; Username, Email, Display Name, Password, Confirm Password; password ขั้นต่ำ 6 ตัวอักษรและต้องตรง confirmation ตาม requirement ปัจจุบัน; เก็บแต่ hash ด้วย Argon2id หรือ bcrypt; role เริ่มต้น `requester`
- ผู้ใช้เลือก Role จาก `requester`, `vehicle_officer`, `approver` (Director/Delegate), `admin` เท่านั้น; ยังไม่เพิ่ม `super_admin`; UI ระบุ role ที่มีอยู่และยังต้องยืนยันว่า user มี role เดียวหรือหลาย role เพราะ `user_roles` ปัจจุบันรองรับหลาย role
- Role/status/reset actions ต้องเป็น POST + CSRF + PDO Prepared Statements; เปลี่ยน role/status ต้องมี reason; ยืนยัน action สำคัญผ่าน SweetAlert2, ตรวจ Admin actor/target และใช้ Transaction ป้องกัน last-admin race
- ห้ามแก้ role ของตนเอง; ห้ามระงับ/ลดสิทธิ์ Admin คนสุดท้าย; บัญชีปิดต้องถูกปฏิเสธทุก authenticated request; reset password ต้องเพิ่ม `auth_version` และไม่เปิดเผยรหัสผ่านเก่า/hash
- History แสดง actor/timestamp สำหรับ user create และแสดง before/after + reason สำหรับ role/status change; บันทึกเหตุการณ์ password reset โดยไม่มี password/hash/confirm value/token
- ใช้ shared escaping helper `e()`; validation/error อยู่ใกล้ field; responsive/mobile-first; success/error state ผ่าน SweetAlert2 โดยไม่แทน server validation
- User Management UI ยัง disabled/unregistered จน backend + Integration Testing ผ่านใน test database และได้รับอนุมัติแยก

แต่ละ Task ต้องมี Acceptance Criteria และ Gate อนุมัติของตนเอง; เอกสารนี้ไม่ได้เปิด gate หรืออนุมัติการเชื่อมฐาน/รัน migration

## 9. Risks และ Open Questions

1. Login จะรับ Username หรือ Email; Username normalize case/whitespace แบบใดและให้เปลี่ยนได้หรือไม่?
2. ยืนยัน email/ตัวตนก่อน Admin สร้างบัญชีอย่างไร และต้องบังคับเปลี่ยน password หลัง reset หรือไม่?
3. Requirement ตั้ง minimum password length 6; จะยืนยัน 6 ตามนี้หรือกำหนดค่าที่สูงขึ้น/เพิ่ม breached-password blocklist?
4. User มี role เดียวหรือหลาย role? Existing `user_roles` รองรับหลาย role แต่หน้าจอระบุ Role singular
5. Reset password ใช้ Admin กำหนด temporary password, ระบบสร้าง one-time password หรือช่องทางอื่น; ใครส่งให้ผู้ใช้โดยไม่ลง log?
6. ยืนยัน idle/absolute session timeout และระยะเก็บ audit/security events
7. ยืนยัน login rate-limit thresholds, lock/backoff duration และช่องทาง alert ผู้ดูแล
8. Director/Delegate ใครมีอำนาจจริง, หลักฐานมอบหมายอยู่ที่ไหน, และต้องตรวจความถูกต้องทุก decision อย่างไร? `approver` role อย่างเดียวไม่ยืนยันอำนาจ
9. Admin ได้สิทธิ์ดูข้อมูลคำขอ/รายงาน/PII ระดับใด; ข้อเสนอคือไม่ให้ workflow privilege โดยปริยาย
10. ยืนยันกติกาการจัดการ Admin คนแรก/สำรองเมื่อ Admin ทุกคนเข้าไม่ได้ และผู้ถือสิทธิ์รัน CLI
11. ยืนยัน IIS production ใช้ HTTPS และ runtime/FastCGI เป็น PHP 8.4.27 ที่มี PDO MySQL/Argon2id หรือไม่; การตรวจครั้งนี้ยืนยันเฉพาะ CLI
12. Grant จริงของ Production Runtime ไม่ได้ตรวจใน Task 04A; ต้องตรวจโดยผู้ดูแลและออกแบบ Least Privilege ก่อน Login ที่ต้องอ่าน/อัปเดต user และเขียน rate-limit
13. ผู้ดูแลจะ link Office 365/CAMS กับบัญชีเดิมด้วยกระบวนการใด โดยไม่ auto-link ผ่าน email?

## 10. PASS / WARNING / FAIL Readiness

| มิติ | สถานะ | เหตุผล |
|---|---|---|
| Task 04A Design Deliverable | **PASS** | ตรวจ Source/Migrations จริงและจัดทำแบบแปลน Login, Session, CSRF, RBAC, schema gaps, SSO และแผนงาน |
| Existing technical foundation | **PASS / WARNING** | CLI PHP 8.4.27, PDO MySQL, Argon2id, Prepared Statements, CSRF helper, session baseline, roles และ audit schema มี; ยังขาด auth flows, authorization middleware, session lifecycle และ login rate limit |
| Database readiness for secure local auth / account management | **WARNING** | password_hash/roles/is_active ใช้ต่อได้ แต่ไม่มี username/auth_version/login buckets/lifecycle fields; runtime grants จริงยังไม่ตรวจและอาจไม่พอสำหรับ rehash, rate-limit และ Admin writes |
| Production authentication readiness | **FAIL** | ไม่มี Login/Logout/identity checks/RBAC enforcement; ห้ามใช้ระบบปัจจุบันเป็นระบบยืนยันตัวตนจริง |
| Database safety in Task 04A | **PASS** | ไม่เชื่อมฐานข้อมูล ไม่รัน Migration ไม่อ่าน/แก้ `.env`, Credentials, Grants หรือ Gate; ไม่แตะ `lost_found` |

**สรุป:** Task 04A พร้อมส่งมอบด้านการออกแบบเท่านั้น. ก่อน Task 04B ต้องยืนยัน Open Questions ที่มีผลต่อ security, อนุมัติ migration/grant plan แยก และระบุ Test DB execution approval; Production Gate คงปิดจนมีการอนุมัติของฐานหลักแยกต่างหาก.
