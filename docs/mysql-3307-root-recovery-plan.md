# MySQL 3307 root recovery — commands for review, NOT executed

Scope: only C:\Users\User\mysql-restore-006-data on TCP 127.0.0.1:3307.
Never stop/change primary 3306 or C:\Users\User\mysql-data. No Restore, migration,
GRANT/REVOKE, datadir initialization or skip-grant-tables is part of this procedure.

## Evidence already inspected

- PID 2616 parent; PID 22708 child/listener; child PID matches the restore PID file.
- Both command lines explicitly use --no-defaults, restore datadir, --port=3307,
  --bind-address=127.0.0.1, --mysqlx=OFF, --console.
- No .err file found in the restore datadir. Existing console history is NOT VERIFIED.
- One explicit TCP 3307 --skip-password test returned 1045 using password:NO.
  The user reported 1045 using password:YES. Root's exact account/plugin/lock/password
  state is NOT VERIFIED; this does not prove corruption or two servers using one datadir.
- UUIDs read ONLY from auto.cnf files:
  primary 77218fbd-d66d-11f1-9a0a-5c2886f057d5;
  restore 37e4d222-dda2-11f1-b503-5c2886f057d5. They differ.

## 1. Elevated Windows PowerShell — inspect again, do not stop yet

Use one dedicated PowerShell window for the commands below. Do not run a transcript,
echo variables containing SQL/password, or paste a password into a command/history.

```powershell
$ErrorActionPreference='Stop'
$mysqlBase='C:\Program Files\MySQL\MySQL Server 8.4'
$mysqlClient=Join-Path $mysqlBase 'bin\mysql.exe'
$mysqlAdmin=Join-Path $mysqlBase 'bin\mysqladmin.exe'
$mysqlServer=Join-Path $mysqlBase 'bin\mysqld.exe'
$restoreData='C:\Users\User\mysql-restore-006-data'
$expectedRestoreUuid='37e4d222-dda2-11f1-b503-5c2886f057d5'
$primaryUuid='77218fbd-d66d-11f1-9a0a-5c2886f057d5'
$restoreProcesses=@(Get-CimInstance Win32_Process -Filter 'ProcessId=2616 OR ProcessId=22708')
if ($restoreProcesses.Count -ne 2) { throw 'PID state changed; re-inspect before continuing' }
foreach($process in $restoreProcesses) {
    if($process.Name -ne 'mysqld.exe' -or $process.ExecutablePath -ne $mysqlServer -or
       $process.CommandLine -notmatch '--port(?:=|\s+)3307(?:\s|$)' -or
       $process.CommandLine -notmatch ('--datadir="?'+[regex]::Escape($restoreData)+'"?(?:\s|$)')) {
        throw 'Unexpected process identity; STOP'
    }
}
if (($restoreProcesses | Where-Object ProcessId -eq 22708).ParentProcessId -ne 2616) { throw 'Parent changed' }
$listeners=@(Get-NetTCPConnection -State Listen | Where-Object LocalPort -eq 3307)
if($listeners.Count -ne 1 -or $listeners[0].OwningProcess -ne 22708 -or $listeners[0].LocalAddress -ne '127.0.0.1') { throw 'Unexpected listener' }
if(([IO.File]::ReadAllText((Join-Path $restoreData 'wanpen-1-1059.pid'))).Trim() -ne '22708') { throw 'PID file mismatch' }
$restoreProcesses | Select-Object ProcessId,ParentProcessId,ExecutablePath
$listeners | Select-Object LocalAddress,LocalPort,OwningProcess
```

**Approval A required:** approve a graceful shutdown of ONLY the verified 3307 console
and a root@localhost password change in that datadir. No recovery has been performed.

## 2. Graceful shutdown only, then verify stopped

In the ORIGINAL console positively identified as the above 3307 startup, press Ctrl+C
once and wait for the server's shutdown-complete message and parent/child exit.
Do not close an unidentified window, use Task Manager End Task, Stop-Process,
taskkill, or stop a MySQL service by name. If that exact console cannot be accessed,
STOP here: there is no approved authenticated shutdown channel yet. Do not force it.

After shutdown, return to the dedicated PowerShell window:

```powershell
$remaining=@(Get-CimInstance Win32_Process -Filter "Name='mysqld.exe'" |
    Where-Object { $_.CommandLine -and $_.CommandLine.Contains($restoreData) })
if($remaining.Count -gt 0) { throw 'Restore process remains; wait, do not kill' }
if(@(Get-NetTCPConnection -State Listen | Where-Object LocalPort -eq 3307).Count -gt 0) { throw '3307 is still occupied' }
```

Only continue when both processes/listener are gone and graceful completion was observed.
If a CIM query fails or command lines cannot be inspected, STOP instead of assuming stopped.

## 3. Prepare ACL-protected init file; hidden input; no secret in arguments

The server will run as this same Windows user, not a service identity. A password
chosen in a password manager should be entered twice below. Quotes, backslashes,
Unicode and SQL-looking text are preserved as literal password characters. NUL/CR/LF
are rejected because init_file statements must each occupy a single line.
The SQL sets its own session NO_BACKSLASH_ESCAPES and doubles single quotes; user
input cannot terminate the password literal. It does not change global SQL mode.

```powershell
# The tested script replaces the obsolete repeated SetOwner/Set-Acl recipe.
$projectRoot='C:\จองรถตู้\up-van-booking'
$preparer=Join-Path $projectRoot 'scripts\prepare-mysql-3307-recovery-file.ps1'
powershell.exe -NoProfile -ExecutionPolicy Bypass -File $preparer -PreflightOnly
if($LASTEXITCODE -ne 0) { throw 'Read-only preflight failed; STOP' }
# Run only after the Step 3 preparation approval; password is entered by the user.
powershell.exe -NoProfile -ExecutionPolicy Bypass -File $preparer
if($LASTEXITCODE -ne 0) { throw 'Preparation failed; STOP' }
# Record the displayed RECOVERY_FILE path without opening its contents.
$initSqlPath=Read-Host 'RECOVERY_FILE path shown by successful preparation'
$recoveryDir=Split-Path -Parent $initSqlPath
$recoveryLog=Join-Path $recoveryDir 'restore3307.err'
. (Join-Path $projectRoot 'scripts\lib\mysql-3307-recovery-acl.ps1')
Assert-PrivateRecoveryAcl $recoveryDir $true
Assert-PrivateRecoveryAcl $initSqlPath $false
# STOP for the separately approved Step 4. This block does not start MySQL.
```

Plaintext exists briefly in memory and in the restricted init file as required by this
MySQL procedure. Never open/print/transcribe that file. Current Windows user and SYSTEM
are the only explicit ACL principals; no inherited Users/Everyone permission is retained.
If execution is cancelled now, remove only that exact init file; do not keep it for later.

**Approval B / final checkpoint:** before starting mysqld with init-file, confirm Approval A
is valid, graceful shutdown completed, ACL verification passed and the target below is 3307.
Starting the next block CHANGES the restore instance's root password; it is not read-only.

## 4. Approved startup with init-file, only 3307

Use the tested operator script instead of the old manual Start-Process recipe.
It is pinned to the prepared file:
C:\Users\User\mysql-3307-recovery-80da7a7f45c24543a25a983b20c014da\root-init.sql.
It never reads that SQL file's contents. Its preflight reads metadata, ACL, preparation
marker and auto.cnf UUID only. No startup occurs without BOTH Execute and ConfirmTarget.

```powershell
$startupScript=Join-Path $projectRoot 'scripts\start-mysql-3307-root-recovery.ps1'
powershell.exe -NoProfile -ExecutionPolicy Bypass -File $startupScript -PreflightOnly
if($LASTEXITCODE -ne 0) { throw 'Startup preflight failed; STOP' }
```

**Explicit operator execution approval required now.** Running the following command
starts 3307 with init-file and CHANGES its root@localhost password to the password
already placed in the protected file. It does not change Primary 3306.

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File $startupScript -Execute -ConfirmTarget mysql-3307-restore
if($LASTEXITCODE -ne 0) { throw 'Startup/verification failed; do not retry Execute' }
$recoveryLog=Join-Path $recoveryDir 'startup3307.err'
```

The script creates startup-attempt.json atomically before launch. If it exists, a
second Execute/preflight is refused. Do not delete that marker to bypass failure:
root's password may already have changed. No automatic server shutdown, kill,
cleanup, Restore or migration is performed. A Start-Process failure, timeout or login
failure requires manual review; server may still be running. No automatic relaunch.

The server launch explicitly pins no-defaults, basedir, restore datadir, port 3307,
bind-address=127.0.0.1 and mysqlx=OFF. General/slow/binary/raw query logging is off;
error log is precreated inside the ACL-protected recovery folder. The script does
not print raw server logs or SQL. New monitor/child PIDs are verified against the
listener before the root prompt. SQL/UUID identity validation is still required.

## 5. Verify root login and identity

Execute automatically invokes mysql.exe with --no-defaults --no-login-paths,
--protocol=TCP --host=127.0.0.1 --port=3307 --user=root --password. Enter the NEW
3307 password in its hidden prompt, never in arguments or chat. Password is not
read by PowerShell or taken from the init file. Only SELECT identity and SHOW DATABASES
are executed by the verification client. Results are parsed and checked automatically.

Require port=3307, UUID=37e4d222-dda2-11f1-b503-5c2886f057d5, exact restore datadir,
version=8.4.9, account=root@localhost and system schemas only. A primary UUID/datadir,
other user database or unrelated listener fails closed. Expect ROOT_RECOVERY_LOGIN_VERIFIED.

A separate check of an already running verified restore instance is available without
starting it or replaying init-file:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File $startupScript -VerifyOnly
if($LASTEXITCODE -ne 0) { throw 'Read-only login/identity verification failed; STOP' }
```

The init file remains protected after success. Proceed to Step 6 cleanup and normal
restart only as a separate approved operator action. Never issue RESTART while the
init file remains configured. Do not Restore Backup or Apply Migration 006 here.

## 6. Delete init file and gracefully restart WITHOUT it

Only after successful identity/auth verification:

```powershell
Remove-Item -LiteralPath $initSqlPath -Force
if(Test-Path -LiteralPath $initSqlPath) { throw 'Init file cleanup failed; do not restart' }
& $mysqlAdmin --no-defaults --no-login-paths --protocol=TCP --host=127.0.0.1 --port=3307 --user=root --password shutdown
if($LASTEXITCODE -ne 0) { throw 'Graceful shutdown failed; STOP, do not kill' }
```

Wait for both restore parent/child and listener exit. Reuse the stopped checks above.
Then start normally (no init-file, no skip-grant-tables):

```powershell
$normalArgs=@('--no-defaults',"--basedir=`"$mysqlBase`"", "--datadir=`"$restoreData`"",
    '--port=3307','--bind-address=127.0.0.1','--mysqlx=OFF',"--log-error=`"$logOptionPath`"")
if(Test-Path -LiteralPath $initSqlPath) { throw 'Unexpected init file remains' }
if(@(Get-CimInstance Win32_Process -Filter "Name='mysqld.exe'" | Where-Object { $_.CommandLine -and $_.CommandLine.Contains($restoreData) }).Count -gt 0) { throw 'Restore datadir remains in use' }
if(@(Get-NetTCPConnection -State Listen | Where-Object LocalPort -eq 3307).Count -gt 0) { throw '3307 occupied' }
$normalProcess=Start-Process -FilePath $mysqlServer -ArgumentList $normalArgs -WindowStyle Hidden -PassThru
```

Repeat Step 5, confirm new process command lines have NO init-file and correct port/datadir.
Delete only the generated init file, not a datadir or another project's file. Logical
deletion does not promise forensic erasure on SSD; retain restricted ACLs and use the
machine's encrypted storage policy for sensitive temporary files/logs.

## Failure / rollback

- Before recovery startup: no MySQL password change; remove the protected init file and
  restart 3307 with the original normal options if the user wants to abort.
- After startup begins: password change may already have committed, even if client login
  fails. There is no transaction rollback to an unknown old password. Keep the chosen
  password in the operator's password manager; do not generate another or reinitialize.
- Delete the init file to prevent replay. Preserve only protected error evidence. If 3307
  still runs, attempt graceful mysqladmin shutdown with the NEW password only after
  verifying its identity, or the OLD known password if the ALTER is confirmed not applied.
  If neither works and there is no positively identified accessible server console, STOP
  for review; no kill, skip-grant-tables, automatic restart, or primary intervention.
- Never retry init-file startup automatically. No Restore or Migration 006 follows
  recovery without separate verification and approval.

Official procedure: https://dev.mysql.com/doc/refman/8.4/en/resetting-permissions.html
