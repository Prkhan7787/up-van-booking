# Normal Startup 3307 after failed recovery — operator review

Prepared only. No MySQL startup, login, password change, Restore or migration executed.
The normal script never reads/deletes root-init.sql or startup-attempt.json.

## Commands, Windows PowerShell 5.1

First, read-only inspection (no password prompt or file creation):

```powershell
Set-Location -LiteralPath 'C:\จองรถตู้\up-van-booking'
powershell.exe -NoProfile -ExecutionPolicy Bypass -File '.\scripts\start-mysql-3307-normal.ps1' -PreflightOnly
if($LASTEXITCODE -ne 0) { throw 'Normal-start preflight failed; STOP' }
```

Only after the user approves actually opening the restore-test server, run:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File '.\scripts\start-mysql-3307-normal.ps1' -Execute -ConfirmTarget mysql-3307-normal
```

This opens ONLY TCP 127.0.0.1:3307 normally using datadir
C:\Users\User\mysql-restore-006-data and explicit WorkingDirectory
C:\Program Files\MySQL\MySQL Server 8.4\bin. The argument builder is independent
of the recovery argument builder. No init-file, initialization or authentication bypass
option exists in the normal arguments; no password-changing SQL is issued. The old
attempt marker remains in place and is never reset to permit another recovery run.

After verifying the listener belongs to the launched monitor/server child, the script
asks for the NEW prepared password using the mysql client's native hidden prompt.
Do not put a password in the command, Source, chat or diagnostic file. One login attempt
only; failure stops the script without trying the old password or recovery automatically.

## Guards and output

- Requires both Execute and exact ConfirmTarget; otherwise no launch.
- Rejects active restore processes/listener and unreadable process command lines.
- Reads restore and primary auto.cnf UUID metadata only; no connection to Primary 3306.
- Requires expected restore UUID 37e4d222-dda2-11f1-b503-5c2886f057d5 and a different
  primary UUID, correct server binary version and non-reparse/ASCII working directory.
- Uses no-defaults and persisted-globals-load=OFF; explicit basedir/datadir/port/binding;
  mysqlx, general log, slow log, raw log and binary log disabled for this test launch.
- Keeps private folder ACL restricted to current Windows user and SYSTEM.
- Creates its OWN normal-startup.lock using CreateNew to prevent concurrent launch.
  No mutation is made to the original recovery marker or SQL file.
- Produces new unique normal-startup-<GUID>.json and .err files in the protected folder.
  The JSON records mode, timestamp, working directory, target, launch PID, listener PID,
  process exit code if available, state, safe failure code and login status. No password,
  SQL file content, raw exception or raw server logs are copied to terminal/JSON.
- Exit code is null while the launch process is running or unavailable. It is not
  invented as 0. The launch PID/exit code can be a MySQL monitor process, and must
  not be assumed to be a child server's native error code.
- SELECT identity and SHOW DATABASES verify 3307, expected UUID, exact restore datadir,
  MySQL 8.4.9, root@localhost and system databases only.

Success: NORMAL_STARTUP_ROOT_LOGIN_VERIFIED. This proves current access with the entered
password, not the exact time/cause of a previous password change. If old/new passwords
are identical, authentication cannot distinguish whether the old ALTER USER executed.

On failed login: ROOT_LOGIN_FAILED_OR_UNREACHABLE (or an identity rejection code).
The script records failure and stops; the normally started server may remain running.
It does NOT kill/shutdown the server, alter passwords, delete files, retry startup,
automatically reset its lock or run another recovery. Review the safe diagnostics first.
The old recovery marker and root-init.sql remain protected and untouched.

On startup exit/timeout: inspect JSON PID/exit/status before any further action. Never
delete normal-startup.lock merely to try again. A new normal startup after a failure or
later shutdown requires review of this lock and separate operator authorization.

No Restore or Migration 006 follows successful login automatically. Leave recovery
artifact cleanup and any later restore verification for separately approved steps.

## Verification performed while preparing Source

- Windows PowerShell 5.1 Syntax: PASS for main, helper and tests.
- Normal Startup Offline: 20 checks PASS (mocks/AST only, no mysqld/client launch).
- Existing Startup Safety: 23 checks PASS; ACL fixtures: 13 checks PASS.
- Real PreflightOnly: PASS, restore stopped, ASCII WorkingDirectory, distinct UUIDs,
  zero files created, zero servers started. Actual normal startup/login is NOT VERIFIED.
