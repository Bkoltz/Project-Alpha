<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping;

use DomainException;

final class DecimalMoney
{
    public static function payAmount(int $durationSeconds, string $hourlyRate): string
    {
        $hourlyRate = trim($hourlyRate);
        if ($durationSeconds < 0 || !preg_match('/^\d+(?:\.\d{1,4})?$/', $hourlyRate)) {
            throw new DomainException('Duration and hourly rate must be non-negative decimal values.');
        }

        $parts = explode('.', $hourlyRate, 2);
        $wholeRate = ltrim($parts[0], '0');
        $wholeRate = $wholeRate === '' ? '0' : $wholeRate;
        if (strlen($wholeRate) > 8) {
            throw new DomainException('Hourly rate exceeds the supported decimal range.');
        }

        // Store the rate as ten-thousandths. Decompose before multiplying so
        // every DECIMAL(12,4)/INT UNSIGNED input stays within a PHP integer.
        $units = ((int) $wholeRate * 10000) + (int) str_pad($parts[1] ?? '', 4, '0');
        $divisor = 360000;
        $cents = ($durationSeconds * intdiv($units, $divisor))
            + intdiv(($durationSeconds * ($units % $divisor)) + intdiv($divisor, 2), $divisor);
        if ($cents > 999999999999) {
            throw new DomainException('Pay amount exceeds the supported decimal range.');
        }

        return intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}
