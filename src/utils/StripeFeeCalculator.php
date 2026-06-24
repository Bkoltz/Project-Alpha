<?php
// src/utils/StripeFeeCalculator.php
// Utility for calculating Stripe processing fees and surcharge amounts

class StripeFeeCalculator {
    
    /**
     * Calculate the Stripe fee for a given amount
     * 
     * @param float $amount Invoice amount
     * @param array $config App config with surcharge settings
     * @return array Fee breakdown
     */
    public static function calculateFee(float $amount, array $config): array {
        $percent = (float)($config['stripe_surcharge_percent'] ?? 2.9);
        $fixed = (float)($config['stripe_surcharge_fixed'] ?? 0.30);
        
        $feeTotal = round(($amount * $percent / 100) + $fixed, 2);
        
        return [
            'percent' => $percent,
            'fixed' => $fixed,
            'fee_total' => $feeTotal,
            'percent_amount' => round($amount * $percent / 100, 2),
        ];
    }
    
    /**
     * Calculate surcharge to add to invoice based on settings
     * 
     * @param float $amount Invoice amount
     * @param array $config App config with surcharge settings
     * @return array Surcharge breakdown with new totals
     */
    public static function calculateSurcharge(float $amount, array $config, ?float $actualFee = null): array {
        $type = $config['stripe_surcharge_type'] ?? 'merchant';

        if ($actualFee !== null && $actualFee >= 0) {
            $feeTotal = round($actualFee, 2);
            $feeSource = 'actual';
        } else {
            $feeInfo = self::calculateFee($amount, $config);
            $feeTotal = $feeInfo['fee_total'];
            $feeSource = 'estimate';
        }

        $result = [
            'original_amount' => $amount,
            'fee_total' => $feeTotal,
            'surcharge_type' => $type,
            'client_pays' => 0,
            'merchant_pays' => 0,
            'new_total' => $amount,
            'display_text' => '',
            'fee_source' => $feeSource,
        ];
        
        // Get custom message from config
        $customMessage = trim($config['stripe_surcharge_message'] ?? '');
        
        switch ($type) {
            case 'merchant':
                // Merchant absorbs full fee
                $result['merchant_pays'] = $feeTotal;
                $result['client_pays'] = 0;
                $result['new_total'] = $amount;
                $defaultText = sprintf(
                    'Credit card processing fee of $%.2f absorbed by merchant',
                    $feeTotal
                );
                if ($customMessage) {
                    $result['display_text'] = $defaultText . "\n" . $customMessage;
                } else {
                    $result['display_text'] = $defaultText;
                }
                break;
                
            case 'client':
                // Client pays the processing fee, capped at the real merchant cost
                $clientPays = $feeTotal;
                $configPercent = (float)($config['stripe_surcharge_percent'] ?? 2.9);
                $configFixed = (float)($config['stripe_surcharge_fixed'] ?? 0.30);
                $estimatedClientFee = round(($amount * $configPercent / 100) + $configFixed, 2);
                if ($estimatedClientFee < $clientPays) {
                    $clientPays = $estimatedClientFee;
                }

                $result['client_pays'] = $clientPays;
                $result['merchant_pays'] = round($feeTotal - $clientPays, 2);
                $result['new_total'] = $amount + $clientPays;
                $defaultText = sprintf(
                    'Credit card surcharge: $%.2f (processing fee)',
                    $feeTotal
                );
                if ($customMessage) {
                    $result['display_text'] = $defaultText . "\n" . $customMessage;
                } else {
                    $result['display_text'] = $defaultText;
                }
                break;
                
            case 'split':
                // Split fee between client and merchant
                $splitPercent = (float)($config['stripe_surcharge_split_percent'] ?? 50);
                $clientPortion = round($feeTotal * ($splitPercent / 100), 2);
                $merchantPortion = round($feeTotal - $clientPortion, 2);
                
                $result['client_pays'] = $clientPortion;
                $result['merchant_pays'] = $merchantPortion;
                $result['new_total'] = $amount + $clientPortion;
                $defaultText = sprintf(
                    'Credit card surcharge: $%.2f (%d%% of $%.2f processing fee)',
                    $clientPortion,
                    $splitPercent,
                    $feeTotal
                );
                if ($customMessage) {
                    $result['display_text'] = $defaultText . "\n" . $customMessage;
                } else {
                    $result['display_text'] = $defaultText;
                }
                break;
        }
        
        return $result;
    }
    
    /**
     * Get surcharge display text for invoices
     * 
     * @param float $amount Invoice amount
     * @param array $config App config
     * @return string Human-readable surcharge description
     */
    public static function getSurchargeLabel(float $amount, array $config): string {
        $calc = self::calculateSurcharge($amount, $config);
        return $calc['display_text'];
    }
    
    /**
     * Check if surcharges are enabled (not merchant-pays-full)
     * 
     * @param array $config App config
     * @return bool True if client pays any portion of fee
     */
    public static function isSurchargeEnabled(array $config): bool {
        $type = $config['stripe_surcharge_type'] ?? 'merchant';
        return $type !== 'merchant';
    }
}
