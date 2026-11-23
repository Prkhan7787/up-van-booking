# MySQL 3307 post-recovery cleanup and normal restart

Prepared 2026-11-12. These commands are operator actions, not authorization for an agent to execute them.

Target: TCP 127.0.0.1:3307, datadir `C:\Users\User\mysql-restore-006-data`, UUID `37e4d222-dda2-11f1-b503-5c2886f057d5`.

Successful recovery folder: `C:\Users\User\mysql-3307-recovery-2597af28af234664ad619cbdda6136ad`.
Only its `root-init.sql` may be removed. Preserve `prepared-status.json`, `startup-attempt.json`, `startup-diagnostic.json`, and all logs. Never repeat recovery with init-file.

## Current read-only observation

On 2026-11-12 no Restore MySQL process or listener was present. UUID remains correct; one stale PID file exists. Cleanup preflight in stopped-instance mode verifies its value against the successful recovery diagnostic, without deleting the PID file. This does not establish that a graceful shutdown occurred, or explain why the server stopped.

Use Windows PowerShell 5.1. Stop immediately on any nonzero exit; do not automatically retry or delete locks/markers.

```powershell
Set-Location -LiteralPath 'C:\จองรถตู้\up-van-booking'
powershell.exe -NoProfile -ExecutionPolicy Bypass -File '.\scripts\cleanup-mysql-3307-fresh-init.ps1' -PreflightOnly -StoppedInstance
Write-Host "Exit Code: $LASTEXITCODE"
```

Expected PASS: successful recovery proof, exact UUID, private current-user/SYSTEM ACL, no running restore process/listener, matching stale PID if present. SQL contents are never read. If the server is now running, stop and use the running-instance procedure below; do not bypass guards.

## 1. Approved cleanup only

This deletes the exact successful `root-init.sql`; it does not start/stop MySQL or change passwords.

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File '.\scripts\cleanup-mysql-3307-fresh-init.ps1' -Execute -StoppedInstance -ConfirmTarget mysql-3307-fresh-init-cleanup
Write-Host "Exit Code: $LASTEXITCODE"
```

Require `SUCCESSFUL_INIT_FILE_REMOVED`, `audit_preserved=true`, Exit Code 0. The script rechecks identity, stopped state, ACL and file metadata immediately before deleting only that literal path. If failure occurs, inspect the reported step and file_deleted field; never reset recovery state.

## 2. Graceful shutdown only if the successful recovery process is still running

Skip this section when stopped. Do not reuse historical PIDs. Running-instance cleanup checks live monitor/child identities, command-line init-file path and successful diagnostic; it fails if the active instance is unrelated to that recovery.

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File '.\scripts\cleanup-mysql-3307-fresh-init.ps1' -PreflightOnly
# Only after PASS and approval:
powershell.exe -NoProfile -ExecutionPolicy Bypass -File '.\scripts\cleanup-mysql-3307-fresh-init.ps1' -Execute -ConfirmTarget mysql-3307-fresh-init-cleanup
# Require cleanup PASS before continuing:
powershell.exe -NoProfile -ExecutionPolicy Bypass -File '.\scripts\stop-mysql-3307-gracefully.ps1' -PreflightOnly
# Only after PASS and approval:
powershell.exe -NoProfile -ExecutionPolicy Bypass -File '.\scripts\stop-mysql-3307-gracefully.ps1' -Execute -ConfirmTarget mysql-3307-shutdown
```

Shutdown preflight opens the dynamically resolved monitor event without signaling it. Execute revalidates identity immediately before signaling; it requires new Shutdown complete log evidence and disappearance of processes/listener. On failure do not kill processes or proceed with startup. Do not issue SQL RESTART while old init-file startup options remain configured.

## 3. Normal startup after cleanup and confirmed stopped state

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File '.\scripts\start-mysql-3307-normal.ps1' -PreflightOnly -PostRecovery
Write-Host "Exit Code: $LASTEXITCODE"
# Only after PASS and approval:
powershell.exe -NoProfile -ExecutionPolicy Bypass -File '.\scripts\start-mysql-3307-normal.ps1' -Execute -PostRecovery -ConfirmTarget mysql-3307-normal
Write-Host "Exit Code: $LASTEXITCODE"
```

The PostRecovery profile preserves old recovery markers and uses a separate exclusive normal-startup lock in the successful recovery folder. It requires the init file to be absent and never deletes existing locks. WorkingDirectory is `C:\Program Files\MySQL\MySQL Server 8.4\bin`. Explicit options include no-defaults, persisted-globals-load=OFF, basedir, datadir, port 3307, bind-address=127.0.0.1; no init-file or skip-grant-tables. Actual listener process and monitor command lines are checked before login.

Enter the successfully recovered password only in the native hidden mysql.exe password prompt. Require `NORMAL_STARTUP_ROOT_LOGIN_VERIFIED`, correct port/UUID/datadir/version/root@localhost, system schemas only, Exit Code 0. A failed login never triggers password recovery or server shutdown automatically. Preserve its new diagnostics for review.

No command here restores a backup, applies Migration 006, changes grants, edits .env, or starts/stops Primary 3306.
