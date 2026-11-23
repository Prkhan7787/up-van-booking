<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/cli.php';

use App\Database\BookingResetMaintenanceGrantPlan;
use App\Database\GrantVerifier;

$input=file_get_contents('php://stdin');
if(!is_string($input) || $input==='')exit(1);
try {
    $grants=json_decode($input,true,512,JSON_THROW_ON_ERROR);
    if(!is_array($grants) || !array_is_list($grants))exit(1);
    $result=GrantVerifier::evaluate($grants,'up_van_booking_test',BookingResetMaintenanceGrantPlan::rules(),false,true);
    if($result['status']!=='PASS' || $result['missing_privileges']!==[] || $result['unexpected_privileges']!==[])exit(1);
    echo "MAINTENANCE_GRANTS=PASS\n";
} catch(Throwable) {exit(1);}
