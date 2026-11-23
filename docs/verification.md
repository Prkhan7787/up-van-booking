# Verification — 6 November 2026 (Asia/Bangkok)

## Environment observed

- Workspace initially empty; up-van-booking did not exist. No existing files overwritten.
- PHP CLI 8.4.27 NTS available via PATH at C:\lostandfound-bysky\.runtime\php84\php.exe. Used read-only to execute new project checks; no runtime/config changes made there.
- PDO and pdo_mysql available; Node.js and npm available; Composer and mysql CLI not found in PATH.
- W3SVC service and C:\Windows\System32\inetsrv\appcmd.exe not found by initial checks. IIS not configured or modified.
- No connection to any database; schema not imported; no existing project or database changed.

## Passed

- PHP lint on all 15 PHP files: no syntax errors.
- npm dependency installation (project-local cache) and npm run build: Tailwind 4.1.18 compiled, SweetAlert2 11.26.17 copied locally, package-lock.json generated.
- php tests/smoke.php: 7 checks passed (escaping, missing/valid/invalid CSRF, module catalog, view rendering, built CSS).
- python tests/http-smoke.py against PHP 8.4 development server on 127.0.0.1:8088: 9 requests passed.
- GET / = 200 with Thai content, CSP, HttpOnly and SameSite=Lax session cookie.
- GET /assets/app.css and /assets/sweetalert2.min.js = 200.
- GET /.env, /config/database.php, /storage/, /missing = 404.
- POST / without token and with wrong token = 419.

Initial sandbox HTTP access failed (socket permission / timeout); repeated checks with server and client outside sandbox passed. No failed check is treated as a pass before retry.

## Not tested

- MySQL 8.4 schema execution, foreign keys, role seed, database connection, User model and AuditLogger integration.
- IIS FastCGI, URL Rewrite, NTFS ACLs, production PHP config and HTTPS secure cookie behavior.
- Browser rendering at mobile/desktop sizes, keyboard navigation, SweetAlert2 dialog behavior and CSP compatibility in browser. Responsive classes are implemented but visual QA is pending.
- Authentication, authorization enforcement, booking, approval, calendar, maps, OAuth and SSO are outside Task 01.

Development server stopped after checks. To start again: php -S 127.0.0.1:8088 -t public (from project root).
