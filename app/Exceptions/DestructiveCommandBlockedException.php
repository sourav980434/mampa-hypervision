<?php

namespace App\Exceptions;

use Exception;

class DestructiveCommandBlockedException extends Exception
{
    protected $message = "Destructive operation is blocked because the Hypervisor is running in Read-Only safety mode.";
}
