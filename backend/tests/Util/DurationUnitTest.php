<?php

declare(strict_types=1);

namespace App\Tests\Util;

use App\Util\DurationUnit;
use PHPUnit\Framework\TestCase;

final class DurationUnitTest extends TestCase
{
    public function testSecondsToMinutesFloatConvertsNominalValues(): void
    {
        $this->assertSame(0.0, DurationUnit::secondsToMinutesFloat(0));
        $this->assertSame(1.0, DurationUnit::secondsToMinutesFloat(60));
        $this->assertSame(1.5, DurationUnit::secondsToMinutesFloat(90));
        $this->assertSame(2.0, DurationUnit::secondsToMinutesFloat('120'));
    }

    public function testSecondsToMinutesFloatClampsNegativeValuesToZero(): void
    {
        $this->assertSame(0.0, DurationUnit::secondsToMinutesFloat(-3600));
    }

    public function testSecondsToMinutesFloatReturnsZeroForNonNumericInput(): void
    {
        $this->assertSame(0.0, DurationUnit::secondsToMinutesFloat(null));
        $this->assertSame(0.0, DurationUnit::secondsToMinutesFloat('not-a-number'));
        $this->assertSame(0.0, DurationUnit::secondsToMinutesFloat([]));
    }

    public function testSecondsToMinutesIntRoundsToNearestMinute(): void
    {
        $this->assertSame(0, DurationUnit::secondsToMinutesInt(0));
        $this->assertSame(1, DurationUnit::secondsToMinutesInt(89)); // 1.483... -> 1
        $this->assertSame(2, DurationUnit::secondsToMinutesInt(90)); // 1.5 -> 2 (round half away from zero)
        $this->assertSame(1, DurationUnit::secondsToMinutesInt(30)); // 0.5 -> 1
    }

    public function testSecondsToMinutesIntClampsNegativeValuesToZero(): void
    {
        $this->assertSame(0, DurationUnit::secondsToMinutesInt(-120));
    }

    public function testSecondsToMinutesIntOrNullPreservesNull(): void
    {
        $this->assertNull(DurationUnit::secondsToMinutesIntOrNull(null));
        $this->assertSame(0, DurationUnit::secondsToMinutesIntOrNull(0));
        $this->assertSame(2, DurationUnit::secondsToMinutesIntOrNull(120));
    }

    public function testMinutesToIntRoundsNominalValues(): void
    {
        $this->assertSame(0, DurationUnit::minutesToInt(0));
        $this->assertSame(5, DurationUnit::minutesToInt(5.4));
        $this->assertSame(6, DurationUnit::minutesToInt(5.5));
        $this->assertSame(10, DurationUnit::minutesToInt('10'));
    }

    public function testMinutesToIntClampsNegativeValuesToZero(): void
    {
        $this->assertSame(0, DurationUnit::minutesToInt(-10));
    }

    public function testMinutesToIntReturnsZeroForNonNumericInput(): void
    {
        $this->assertSame(0, DurationUnit::minutesToInt(null));
        $this->assertSame(0, DurationUnit::minutesToInt('not-a-number'));
    }
}
