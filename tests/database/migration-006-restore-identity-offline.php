<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/bootstrap/cli.php';
use App\Database\{Migration006RestoreIdentity, SafetyException};
$n=0;
$check=static function(bool $ok)use(&$n):void{if(!$ok)throw new RuntimeException('OFFLINE_ASSERTION_FAILED');$n++;};
$reject=static function(callable $fn):bool{try{$fn();return false;}catch(SafetyException){return true;}};
$config=['host'=>'127.0.0.1','port'=>'3307','database'=>'up_van_booking_test','username'=>'up_van_test_migrator'];
Migration006RestoreIdentity::assertConfig($config);$check(true);
foreach(['host'=>'localhost','port'=>'3306','database'=>'up_van_booking','username'=>'root'] as $key=>$bad){$c=$config;$c[$key]=$bad;$check($reject(fn()=>Migration006RestoreIdentity::assertConfig($c)));}
$identity=['port'=>3307,'uuid'=>'37e4d222-dda2-11f1-b503-5c2886f057d5','datadir'=>'C:\\Users\\User\\mysql-restore-006-data\\','database_name'=>'up_van_booking_test','account'=>'up_van_test_migrator@127.0.0.1'];
Migration006RestoreIdentity::assertIdentity($identity);$check(true);
foreach(['port'=>3306,'uuid'=>'wrong','datadir'=>'C:/Users/User/mysql-data','database_name'=>'up_van_booking','account'=>'root@localhost'] as $key=>$bad){$c=$identity;$c[$key]=$bad;$check($reject(fn()=>Migration006RestoreIdentity::assertIdentity($c)));}
foreach(array_keys($identity) as $key){$c=$identity;unset($c[$key]);$check($reject(fn()=>Migration006RestoreIdentity::assertIdentity($c)));}
$source=file_get_contents(dirname(__DIR__,2).'/bin/db-migration-006.php');
$check(str_contains($source,"ConnectionFactory::connect('migration_test_006')"));
$check(str_contains($source,'new MigrationRunner($db,MigrationTarget::TEST)'));
$check(substr_count($source,'Migration006RestoreIdentity::inspect($db)')===2);
$check(strpos($source,'Migration006RestoreIdentity::assertConfig')<strpos($source,'ConnectionFactory::connect'));
$check(str_contains($source,'--restore-test-3307'));
echo "PASS: $n Migration 006 restore identity offline checks; no MySQL connection\n";
