<?php
declare(strict_types=1);

$input = file_get_contents('php://stdin');
if ($input === false) {
    fwrite(STDERR, "probe input failed\n");
    exit(1);
}
echo hash('sha256', $input), "\n";
