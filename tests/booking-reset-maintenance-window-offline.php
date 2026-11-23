<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/cli.php';

use App\Core\BookingWriteWindow;

set_exception_handler(static function(Throwable $error):void{fwrite(STDERR,$error->getMessage().PHP_EOL);exit(1);});
$checks=0;
$check=static function(bool $ok,string $label)use(&$checks):void{if(!$ok)throw new RuntimeException($label);$checks++;};
$handle=BookingWriteWindow::acquireMaintenance();
try{$check(!BookingWriteWindow::enterWebWrite(),'Web POST rejected while maintenance holds exclusive lock');}
finally{fclose($handle);}
$check(BookingWriteWindow::enterWebWrite(),'Web POST obtains shared lock after maintenance releases');
try{BookingWriteWindow::acquireMaintenance();throw new RuntimeException('Maintenance accepted during web POST');}
catch(RuntimeException $e){$check($e->getMessage()==='MAINTENANCE_WEB_WRITE_IN_FLIGHT','Maintenance rejected while web POST is in flight');}
echo "PASS: booking write window ($checks checks); no MySQL connection made.\n";
