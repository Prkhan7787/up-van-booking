<?php
declare(strict_types=1);
namespace App\Core;

final class DriverRegistrationInputException extends \InvalidArgumentException
{
    /** @param array<string,string> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('Driver registration input is invalid.');
    }
}
