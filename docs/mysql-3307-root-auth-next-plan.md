# Root authentication recovery: next operator plan — 2026-11-11

PLAN ONLY. No shutdown signal, MySQL startup, password change, deletion, Restore or
migration was executed. Old root-init.sql and startup-attempt.json remain untouched.

## Initial verified state (before operator shutdown)

- Monitor 17392 -> server 11516, listener 127.0.0.1:3307 and PID file 11516.
- Restore datadir C:\Users\User\mysql-restore-006-data; auto.cnf UUID
  37e4d222-dda2-11f1-b503-5c2886f057d5. Normal command lines contain no init-file.
- Normal log: ready for connections, no ERROR. Diagnostic reached LISTENER_VERIFIED
  and failed ROOT_LOGIN. Root 1045 is user-reported authentication evidence.
- Earlier recovery marker remains START_REQUESTED_NO_AUTO_RETRY; earlier error log is
  empty. This does not prove whether the earlier ALTER USER committed.
- MySQL 8.4.9 Source creates mysqld<monitor_pid>_shutdown and handles its event by
  closing connections via the normal shutdown path. Event mysqld17392_shutdown was
  opened SYNCHRONIZE-only to verify existence, then disposed. NO Set/Wait was called.
- This is a version-specific local Windows mechanism, not a generic event name or
  permission to signal it. ModifyState access has not been exercised. If it fails,
  STOP; do not create another event, try generic shutdown events or kill processes.

Sources:
https://github.com/mysql/mysql-server/blob/mysql-8.4.9/sql/mysqld.cc
(create_shutdown_and_restart_thread; handle_shutdown_and_restart)
https://dev.mysql.com/doc/refman/8.4/en/server-shutdown.html

## A. Separate approval: graceful shutdown of 3307 only

Do NOT execute until the user approves stopping this specific restore instance.
Use elevated Windows PowerShell 5.1. This is not an authenticated SQL operation:
Windows permissions to the server's own shutdown event are required. No grants change.
If an already authorized MySQL administrator credential with SHUTDOWN exists, explicit
TCP mysqladmin shutdown on port 3307 is the documented alternative; no such working
credential is currently established. Do not keep trying passwords to obtain it.

```powershell
$ErrorActionPreference='Stop'
$project='C:\จองรถตู้\up-van-booking'
$data='C:\Users\User\mysql-restore-006-data'
$shutdownScript=Join-Path $project 'scripts\stop-mysql-3307-gracefully.ps1'
# Read-only: discover CURRENT PIDs, verify metadata and open event permissions only.
powershell.exe -NoProfile -ExecutionPolicy Bypass -File $shutdownScript -PreflightOnly
if($LASTEXITCODE -ne 0) { throw 'Shutdown preflight failed; STOP' }
# Execute ONLY after explicit user approval to gracefully stop 3307.
powershell.exe -NoProfile -ExecutionPolicy Bypass -File $shutdownScript -Execute -ConfirmTarget mysql-3307-shutdown
if($LASTEXITCODE -ne 0) { throw 'Shutdown not verified; STOP, do not kill or retry automatically' }
```

Also require the normal log's shutdown-complete event (MY-010910 / Shutdown complete),
reviewed without publishing raw contents. If uncertain, STOP before any new startup.
Event shutdown follows MySQL's normal close-connections path; it does not terminate
the Windows process forcibly. No 3306 connection, signal or service stop is involved.

## B. Prepare a FRESH init file after shutdown; do not reuse the old file

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File 'C:\จองรถตู้\up-van-booking\scripts\prepare-mysql-3307-recovery-file.ps1' -PreflightOnly
if($LASTEXITCODE -ne 0) { throw 'Preflight failed; STOP' }
powershell.exe -NoProfile -ExecutionPolicy Bypass -File 'C:\จองรถตู้\up-van-booking\scripts\prepare-mysql-3307-recovery-file.ps1'
if($LASTEXITCODE -ne 0) { throw 'File preparation failed; STOP' }
```

Enter the new password twice using Hidden Prompt. Store it in the operator's password
manager, not chat. Record only RECOVERY_FILE path and ACL=PASS. The preparer creates
a new private GUID directory and uses DACL-only setup. It does not reset MySQL itself.
Keep the old 80da7... folder/SQL/attempt marker unchanged.

## C. NEW approval: actual root password change

Current follow-up: 3307 is stopped. Fresh preparation is complete at:
C:\Users\User\mysql-3307-recovery-2597af28af234664ad619cbdda6136ad\root-init.sql.
Read-only Preflight has verified ACL, marker, UUID and unused attempt state. No SQL file
content was read. Do not repeat B to generate another file unless a new attempt is reviewed.

Use scripts/start-mysql-3307-fresh-recovery.ps1, which pins ONLY that fresh file.
The old recovery script/file/markers are not reused or deleted. Source helpers reuse the
reviewed ACL, argument, process/listener, exit-code and hidden-root-login implementations.

First run only read-only checks:

```powershell
Set-Location -LiteralPath 'C:\จองรถตู้\up-van-booking'
powershell.exe -NoProfile -ExecutionPolicy Bypass -File '.\scripts\start-mysql-3307-fresh-recovery.ps1' -PreflightOnly
if($LASTEXITCODE -ne 0) { throw 'Fresh preflight failed; STOP' }
```

STOP and obtain NEW explicit approval for the actual root password change. Approval A
was only for shutdown. Running the command below starts 3307 with the prepared init-file
and CHANGES root@localhost to the newly prepared password. It is not a read-only command.
No agent execution of this command occurred during Source preparation.

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File '.\scripts\start-mysql-3307-fresh-recovery.ps1' -Execute -ConfirmTarget mysql-3307-fresh-recovery
if($LASTEXITCODE -ne 0) { throw 'Fresh recovery failed; preserve evidence, do not retry' }
```

Guards/behavior:

- Exact fresh path, target datadir/UUID/MySQL 8.4.9 and ASCII MySQL bin WorkingDirectory.
- No defaults/persisted settings; fixed port=3307 and bind-address=127.0.0.1.
- Checks no processes/listener, exact private ACL, prepared marker and unchanged init-file
  metadata before startup. No SQL content is read, hashed or printed.
- Requires unused startup-attempt.json, startup-diagnostic.json and startup.err in the
  FRESH directory. CreateNew atomically claims the new marker. No overwrite/replay on failure.
- PID, listener PID, WorkingDirectory, exit code (null while unknown/running), stage,
  safe error code/type, native Win32 error code when available, and root-login status
  are persisted in the fresh private diagnostic JSON. Parent monitor exit code must
  not be misrepresented as a child CreateProcess native code.
- General/slow/binary/raw query logs disabled; error log is precreated with restricted ACL.
  Raw exception, SQL, credentials and server log contents are not copied to terminal/JSON.
- After verified listener, asks for the NEW prepared password through mysql's native
  hidden prompt once. Only SELECT identity and SHOW DATABASES are issued by the client.
  Expect FRESH_RECOVERY_ROOT_LOGIN_VERIFIED, expected port/UUID/datadir/version/root account
  and system databases only.
- On any error: STOP, preserve the new marker/diagnostics; no automatic restart, password
  fallback, account unlock/plugin change, cleanup, kill, normal restart, Restore or migration.
  Root may already have changed even when listener/login verification fails.
- The old 80da7... root-init.sql and startup-attempt.json remain untouched. This script
  has no CLI argument for substituting either a legacy file or a different target.

Preparation checks: Windows PowerShell 5.1 Syntax PASS (main/helper/tests); 33 fresh
recovery checks PASS; shared startup/normal/ACL regressions 23/20/13 PASS. Real Preflight
PASS with files_created=false and server_started=false. Actual Execute/login NOT VERIFIED.

After successful new login: separately approve removing ONLY the newly used init file
and gracefully restarting 3307 normally before any later restore verification. Preserve
prior attempt artifacts until their cleanup is specifically approved. No Restore Backup
or Migration 006 follows automatically.
