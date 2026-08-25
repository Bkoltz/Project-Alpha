<?php
declare(strict_types=1);
namespace App\Domain\Pricing;
use DomainException;
final class ExactPercentageCalculator
{
    public const VERSION = 'percentage-v1';
    public function discount(int $basisMinor, string $percentageRate): array
    {
        if ($basisMinor < 0) throw new DomainException('Pricing basis cannot be negative.');
        $units = $this->rateUnits($percentageRate);
        $denominator = 1_000_000;
        // Decompose before multiplication so valid BIGINT minor-unit inputs do
        // not overflow PHP integers at high percentage rates.
        $adjustment = (intdiv($basisMinor, $denominator) * $units)
            + intdiv((($basisMinor % $denominator) * $units) + intdiv($denominator, 2), $denominator);
        $adjustment = min($basisMinor, $adjustment);
        return ['basis_minor'=>$basisMinor, 'adjustment_minor'=>$adjustment,
            'adjusted_minor'=>$basisMinor-$adjustment, 'percentage_rate'=>$this->formatRate($units),
            'calculation_version'=>self::VERSION];
    }
    private function rateUnits(string $rate): int
    {
        $rate=trim($rate);
        if (!preg_match('/^(?:\d{1,3})(?:\.\d{1,4})?$/',$rate)) throw new DomainException('Percentage rate must have at most four decimal places.');
        [$whole,$fraction]=array_pad(explode('.',$rate,2),2,'');
        $units=((int)$whole*10_000)+(int)str_pad($fraction,4,'0');
        if($units<=0||$units>1_000_000) throw new DomainException('Percentage rate must be greater than zero and no more than 100.');
        return $units;
    }
    private function formatRate(int $units): string
    { return intdiv($units,10_000).'.'.str_pad((string)($units%10_000),4,'0',STR_PAD_LEFT); }
}
