<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/cli.php';

use App\Core\BookingWriteWindow;

$marker=$argv[1]??'';
$mode=$argv[2]??'maintenance';
if($marker==='' || !str_starts_with($marker,sys_get_temp_dir()))exit(2);
$handle=null;
if($mode==='maintenance')$handle=BookingWriteWindow::acquireMaintenance();
elseif($mode==='web'){
    if(!BookingWriteWindow::enterWebWrite())exit(4);
}else exit(5);
try {
    if(file_put_contents($marker,'LOCKED')===false)exit(3);
    usleep(1500000);
    throw new RuntimeException('INTENTIONAL_OFFLINE_EXCEPTION');
} catch(RuntimeException $error) {
    if($error->getMessage()!=='INTENTIONAL_OFFLINE_EXCEPTION')throw $error;
} finally {if(is_resource($handle))fclose($handle);}
