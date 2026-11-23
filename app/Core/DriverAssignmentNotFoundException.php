<?php
declare(strict_types=1);
namespace App\Core;

final class DriverAssignmentNotFoundException extends \RuntimeException
{
    public function __construct() { parent::__construct('Driver assignment was not found.'); }
}
