<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/cli.php';

use App\Core\BookingWriteWindow;

$expected=$argv[1]??'';
if(!in_array($expected,['blocked','available','maintenance_blocked','maintenance_available'],true))exit(2);
if(str_starts_with($expected,'maintenance_')){
    try{$handle=BookingWriteWindow::acquireMaintenance();$actual='maintenance_available';fclose($handle);}
    catch(RuntimeException $e){$actual=$e->getMessage()==='MAINTENANCE_WEB_WRITE_IN_FLIGHT'?'maintenance_blocked':'unexpected';}
}else $actual=BookingWriteWindow::enterWebWrite()?'available':'blocked';
if($actual!==$expected)exit(1);
echo "WINDOW=$actual\n";
