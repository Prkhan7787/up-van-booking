<?php
declare(strict_types=1);
namespace App\Database;

use PDOException;
use Throwable;

final class AuthCredentialReadOnlyCheck
{
    /**
     * @param callable():array{database:string,identity:string,grants_verified:bool} $connect
     * @return array{status:string,step:string,code:string,database?:string,identity?:string,grants?:string,sqlstate?:string,native_code?:int|null}
     */
    public static function run(string $expectedDatabase, string $expectedIdentity, callable $connect): array
    {
        try {
            $actual = $connect();
            if (($actual['database'] ?? null) !== $expectedDatabase) {
                return ['status'=>'FAIL','step'=>'IDENTITY','code'=>'WRONG_DATABASE'];
            }
            if (($actual['identity'] ?? null) !== $expectedIdentity) {
                return ['status'=>'FAIL','step'=>'IDENTITY','code'=>'WRONG_ACCOUNT'];
            }
            if (($actual['grants_verified'] ?? false) !== true) {
                return ['status'=>'FAIL','step'=>'GRANTS','code'=>'GRANTS_NOT_VERIFIED'];
            }
            return [
                'status'=>'PASS', 'step'=>'COMPLETE', 'code'=>'READ_ONLY_CHECK_PASS',
                'database'=>$expectedDatabase, 'identity'=>$expectedIdentity, 'grants'=>'PASS',
            ];
        } catch (SafeDatabaseException $error) {
            return [
                'status'=>'FAIL','step'=>'PDO_CONNECT_OR_VERIFY','code'=>'PDO_ERROR',
                'sqlstate'=>$error->sqlState,'native_code'=>$error->nativeCode,
            ];
        } catch (PDOException $error) {
            $safe = SafeDatabaseException::fromPdo($error);
            return [
                'status'=>'FAIL','step'=>'PDO_CONNECT_OR_VERIFY','code'=>'PDO_ERROR',
                'sqlstate'=>$safe->sqlState,'native_code'=>$safe->nativeCode,
            ];
        } catch (SafetyException) {
            return ['status'=>'FAIL','step'=>'CONNECTION_OR_GRANTS','code'=>'SAFE_CONNECTION_REJECTED'];
        } catch (Throwable) {
            return ['status'=>'FAIL','step'=>'CONNECT_OR_VERIFY','code'=>'SAFE_CHECK_FAILED'];
        }
    }
}
