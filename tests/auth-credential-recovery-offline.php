<?php
declare(strict_types=1);
require dirname(__DIR__) . '/scripts/recover-test-auth-credentials-execute.php';

function recoveryCheck(bool $condition, string $label): void
{
    if (!$condition) throw new RuntimeException('Credential recovery state test failed: ' . $label);
    echo 'PASS: ' . $label . PHP_EOL;
}

$sm = AuthCredentialRecoveryStateMachine::class;
recoveryCheck($sm::decision(null,false,true)==='START_NEW','both existing accounts and absent config permit first recovery preparation');
recoveryCheck($sm::decision(null,true,true)==='BLOCK_CREDENTIALS_WITHOUT_STATE','existing env credentials without recovery state are never treated as pending');
recoveryCheck($sm::decision('PREPARED',true,true)==='RESUME_SAME_CREDENTIAL_SET','partial recovery retries reuse the persisted state');
recoveryCheck($sm::decision('AUTH_UPDATED',true,true)==='RESUME_SAME_CREDENTIAL_SET','single-account completion resumes the same credential set');
recoveryCheck($sm::decision('COMPLETE',true,true)==='VERIFY_AND_STOP','completed recovery verifies then stops without resetting passwords');
recoveryCheck($sm::decision('UNKNOWN',true,true)==='BLOCK_INVALID_STATE','unknown state fails closed');
recoveryCheck($sm::decision('PREPARED',true,false)==='BLOCK_ACCOUNTS_NOT_COMPLETE','missing target accounts block recovery without creating users');
recoveryCheck($sm::targetAllowed('up_van_booking_test') && !$sm::targetAllowed('up_van_booking'),'wrong target is denied before any connection');
recoveryCheck($sm::next('PREPARED','ENV_SYNCED') && $sm::next('ENV_SYNCED','AUTH_UPDATED'),'state transitions accept ordered initial stages');
recoveryCheck(!$sm::next('PREPARED','COMPLETE') && !$sm::next('COMPLETE','PREPARED'),'state transitions reject skipped or reversed stages');
recoveryCheck($sm::accountDecision(true,true,null)==='KEEP_PASSWORD_UNCHANGED','matching credential is never altered');
recoveryCheck($sm::accountDecision(true,false,1045)==='SET_STORED_PASSWORD','credential mismatch can set only the already persisted password');
recoveryCheck($sm::accountDecision(true,false,2002)==='BLOCK_NO_PASSWORD_CHANGE','connection failure never triggers password alteration');
recoveryCheck($sm::accountDecision(false,false,1045)==='BLOCK_ACCOUNT_MISSING','missing account is never created by recovery');
recoveryCheck($sm::actionAllowed('ENV_SYNCED','reconcile-auth') && !$sm::actionAllowed('PREPARED','reconcile-auth'),'account password operation requires the correct persisted state');
recoveryCheck($sm::actionAllowed('COMPLETE','verify') && !$sm::actionAllowed('COMPLETE','reconcile-provisioner'),'completed recovery can verify but cannot repeat password changes');
foreach (['PREPARED','ENV_SYNCED','AUTH_UPDATED','PROVISIONER_UPDATED','VERIFIED'] as $state) {
    recoveryCheck($sm::decision($state,true,true)==='RESUME_SAME_CREDENTIAL_SET','crash at '.$state.' is resumable without generating a new password');
}
foreach ([['HY000',1045,true,true],['28000',1045,true,true],['HY000',2002,true,false],['HY000',2003,true,false],['HY000',2013,true,false],['42000',1049,true,false],['42000',1142,false,false],['HY000',1045,false,false],['28000',1045,false,false],['42000',1045,true,false]] as [$sql,$native,$connect,$expected]) {
    recoveryCheck($sm::authenticationFailure($sql,$native,$connect)===$expected,'authentication evidence '.$sql.'/'.$native.' connect='.($connect?'yes':'no'));
}
$dummyError=new PDOException('synthetic driver text');
$dummyError->errorInfo=['HY000',1045,'synthetic sensitive text'];
$connectError=App\Database\SafeDatabaseException::fromPdo($dummyError,true);
$queryError=App\Database\SafeDatabaseException::fromPdo($dummyError);
recoveryCheck($sm::authenticationFailure($connectError->sqlState,$connectError->nativeCode,$connectError->duringConnect),'ConnectionFactory connect evidence permits authentication recovery');
recoveryCheck(!$sm::authenticationFailure($queryError->sqlState,$queryError->nativeCode,$queryError->duringConnect),'query/default error evidence cannot authorize password changes');
recoveryCheck($sm::actionAllowed('', 'root-check') && !$sm::actionAllowed('', 'reconcile-auth'),'root-only diagnostic is permitted without advancing recovery state');
echo "PASS: 34 credential recovery offline state checks; no database contacted.\n";
