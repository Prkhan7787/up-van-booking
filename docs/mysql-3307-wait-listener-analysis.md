# WAIT_LISTENER failure analysis — 2026-11-11

Review and preparation only. No MySQL binary was launched, no server was stopped,
no recovery SQL content was read, and no recovery marker/file was changed or deleted.
Production/Primary 3306 was not changed. Startup Source remains unchanged in this review.

Follow-up: a separate normal-start Source and offline tests are now prepared in
scripts/start-mysql-3307-normal.ps1; see mysql-3307-normal-startup-operator.md.
No normal startup or recovery retry has been executed by the agent. The historical
findings below still describe the failed recovery launch and its unchanged marker.

## Confirmed evidence

- startup-attempt.json: port 3307, datadir C:\Users\User\mysql-restore-006-data,
  attempt_utc=2026-11-11T08:30:25.3398416Z (15:30:25 Bangkok),
  state=START_REQUESTED_NO_AUTO_RETRY.
- Marker has no PID, working_directory or exit_code. It proves a request was recorded,
  not that the server started or ALTER USER ran.
- No restore process or 3307 listener exists at the time of inspection.
- startup3307.err is zero bytes; its creation/last-write time matches marker creation.
  Source precreates the empty log before Start-Process. It is not proof that mysqld
  opened the file, nor proof that the root password remained unchanged.
- root-init.sql exists, 130 bytes. Only metadata/ACL was inspected; content was NOT read.
- Recovery directory, root-init.sql, marker and log have protected ACLs granting only
  current Windows user and SYSTEM FullControl; current user owns them. No reparse points.
- Restore datadir grants the Windows user FullControl. Server exe/bin grant ReadAndExecute
  to ordinary Users. No obvious ACL denial was found; these checks are not a full audit
  of the historical process token or every file touched during startup.
- No alternative .err files were found in the restore datadir.

## Working directory

The Start-Process call omits -WorkingDirectory. A benign Windows PowerShell 5.1 child
probe (NOT mysqld) reproduced the default effective directory exactly as
C:\จองรถตู้\up-van-booking. A separate benign probe with explicit WorkingDirectory
C:\Program Files\MySQL\MySQL Server 8.4\bin used that ASCII path. Both benign children
exited 0. The probes preserve Unicode using Base64 transport; console '?' replacement
must not be mistaken for an actual working-directory value.

The uncontrolled non-ASCII directory is a confirmed Source defect/risk and consistent
with the user's earlier MySQL CreateProcess error 267. But the exact native error and
working directory of the FAILED MySQL process were not persisted. Error 267 as the
cause of THIS attempt remains a hypothesis, not a confirmed historical exit code.

## Arguments and listener validation

Get-Restore3307Arguments pins no-defaults, persisted-globals-load=OFF, correct basedir,
restore datadir, port 3307, loopback binding, mysqlx=OFF, disabled general/slow/binary/raw
query logging and quoted absolute init/log paths. No password argument or primary
datadir/port is present. Spaces in basedir are quoted; init/log options use forward
slashes. No quoting defect is evident from inspection/offline tests. Actual MySQL
option parsing was NOT retested because launching MySQL is not approved here.

Assert-Restore3307Listener requires one loopback listener, the correct mysqld executable,
datadir/port, and ownership by the launched monitor or its direct child. This is covered
by existing mock tests. WAIT_LISTENER can fail on process exit, timeout, listener-owner
validation, or TCP/CIM query error. The stage alone does not identify which happened.

WAIT_LISTENER is assigned only AFTER Start-Process returns. A failure in the initial
Start-Process/CreateProcess call itself would normally be reported at START_MYSQL_3307.
If error 267 occurred in this attempt, an internal mysqld monitor/child launch is one
possible origin; it must not be conflated with a proven failure of the first call.

The script checks HasExited but does not persist ExitCode. Its generic error trap
does not distinguish timeout/exit/validation. That diagnostic gap prevents reconstructing
whether the monitor failed before the child/server logger initialized. Empty log is
consistent with early monitor/loader/argument failure, but does not choose among them.

## Proposed Source patch — NOT implemented or approved for execution

1. Pin -WorkingDirectory to C:\Program Files\MySQL\MySQL Server 8.4\bin for every server
   launch; verify directory existence, no reparse point and ASCII-only path beforehand.
   C:\Users\User is another ASCII candidate; prefer bin for a stable installation path.
2. Preserve existing marker/replay guard, target pins and ACL checks. Never delete or
   reset startup-attempt.json to get past REPLAY_GUARD.
3. Record a separate safe diagnostic artifact containing selected working directory,
   launch PID, process exit code if available and a fixed reason code such as
   SERVER_EXITED_BEFORE_LISTENER, SERVER_RUNNING_NO_LISTENER or LISTENER_IDENTITY_REJECTED.
   Parent monitor exit code is not automatically the child's native CreateProcess code.
   Capture safe native code separately when available; do not print raw stderr/logs.
4. If reviewing stdout/stderr capture, use new files in the protected recovery folder
   with verified ACLs. Never truncate/overwrite the existing empty log or marker during
   investigation. Do not include SQL content/password in diagnostics.
5. Add a distinct normal-start mode/utility which cannot accept/add init-file, initialize,
   skip-grant-tables, another datadir or port. It must not reuse recovery Execute or
   erase the attempt marker. Add offline process mocks and explicit working-directory,
   no-init-file, duplicate-start, parent-exit, timeout and safe-exit-code tests.

## Safest next operator sequence — separate approval required

Root authentication cannot be established while the server is stopped. File timestamps,
empty logs, PID absence and the marker cannot prove its current password. Reading
mysql.ibd/authentication hashes offline is outside scope and is not proposed.

1. Keep root-init.sql and startup-attempt.json unchanged and private.
2. Review/approve the minimal WorkingDirectory and diagnostic patch, plus a NORMAL
   start path with no init-file and no replay/reset of recovery state.
3. Approve an explicit operator normal startup ONLY of port 3307, same UUID/datadir,
   with ASCII WorkingDirectory and all required duplicate-process/listener checks.
   This opens the test server normally; it does not execute the password-reset file.
4. Once that normal server is running and its listener identity is checked, run the
   existing read-only verification mode from Windows PowerShell:

```powershell
Set-Location -LiteralPath 'C:\จองรถตู้\up-van-booking'
powershell.exe -NoProfile -ExecutionPolicy Bypass -File '.\scripts\start-mysql-3307-root-recovery.ps1' -VerifyOnly
```

Enter the NEW prepared password in the hidden prompt. If it succeeds, current root
credentials are usable and another init-file run is unnecessary. If it fails, do not
automatically retry passwords or recovery. With explicit operator approval, try the
OLD known password once using the same explicit TCP 127.0.0.1:3307 SELECT/SHOW-only
client settings; if both fail, stop and review. If old and new passwords are identical,
login cannot identify whether the earlier ALTER executed; it only proves current access.

5. Require port=3307, UUID=37e4d222-dda2-11f1-b503-5c2886f057d5, restore datadir,
   MySQL 8.4.9, root@localhost and system schemas only. Never use a client command
   without explicit TCP/host/port, and do not connect to 3306 to try the password.
6. Only after credential state is established, obtain separate approval for recovery
   file cleanup/normal restart or another recovery design. This review grants NO
   permission for init-file retry, marker deletion, Restore or Migration 006.

## Decision

GO: Source review and Offline patch preparation.
NO-GO: Recovery Execute retry. Normal startup also requires separate approval; no
normal-start command was executed or supplied as immediately runnable in this review.
Existing tests passed 23 mock startup checks and current script/helper Syntax under
Windows PowerShell 5.1. They do not demonstrate that live mysqld startup is fixed.
