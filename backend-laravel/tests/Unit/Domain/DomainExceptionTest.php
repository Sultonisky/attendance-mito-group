<?php

namespace Tests\Unit\Domain;

use App\Exceptions\Domain\DomainException;
use App\Exceptions\Domain\InactiveEmployeeException;
use App\Exceptions\Domain\InvalidStateException;
use PHPUnit\Framework\TestCase;

class DomainExceptionTest extends TestCase
{
    public function test_domain_exception_is_base_for_domain_violations(): void
    {
        $exception = new DomainException('business rule violation');

        $this->assertSame('business rule violation', $exception->getMessage());
        $this->assertInstanceOf(\Exception::class, $exception);
    }

    public function test_inactive_employee_exception_extends_domain_exception(): void
    {
        $exception = new InactiveEmployeeException('Employee is not active');

        $this->assertInstanceOf(DomainException::class, $exception);
        $this->assertSame('Employee is not active', $exception->getMessage());
    }

    public function test_invalid_state_exception_extends_domain_exception(): void
    {
        $exception = new InvalidStateException('Session is not open');

        $this->assertInstanceOf(DomainException::class, $exception);
        $this->assertSame('Session is not open', $exception->getMessage());
    }
}
