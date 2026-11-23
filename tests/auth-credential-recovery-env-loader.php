<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/Core/Env.php';
$key='RECOVERY_DUMMY_TEST_VALUE';
$previous=getenv($key);
putenv($key);
$path=tempnam(sys_get_temp_dir(),'upvan-dummy-env-');
if ($path===false) throw new RuntimeException('Dummy fixture unavailable');
try {
    $cases=[
        ["$key=\n",''],["$key=\r\n",''],
        ["$key=dummy\n",'dummy'],["$key=dummy\r\n",'dummy'],
        ["$key=\"  dummy # ; {} &  \"\r\n",'  dummy # ; {} &  '],
        ["  $key= 'dummy' \r\n",'dummy'],
    ];
    foreach($cases as [$text,$expected]) {
        file_put_contents($path,$text);
        App\Core\Env::load($path);
        if (App\Core\Env::get($key)!==$expected) throw new RuntimeException('Dummy Config Loader compatibility failure');
    }
    if(App\Core\Env::get('RECOVERY_DUMMY_MISSING','MISSING')!=='MISSING') throw new RuntimeException('Missing dummy key mismatch');
    echo "PASS: 7 actual Config Loader compatibility checks; dummy text only.\n";
} finally {
    unlink($path);
    $previous===false ? putenv($key) : putenv($key.'='.$previous);
}
