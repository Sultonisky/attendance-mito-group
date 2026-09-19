<?php

namespace App\Exceptions\Domain;

/**
 * Thrown when a device already has another outsource with an open attendance session.
 */
class OutsourceDeviceBusyException extends DomainException
{
}
