<?php

namespace App\Enums;

/**
 * Employment status of an employee.
 *
 * Backed by the stable string value in the `employees.employment_status`
 * column. Business rules around these states belong to domain engines.
 */
enum EmploymentStatus: string
{
    case Permanent = 'permanent';

    case Contract = 'contract';

    case Probation = 'probation';

    case Outsource = 'outsource';
}
