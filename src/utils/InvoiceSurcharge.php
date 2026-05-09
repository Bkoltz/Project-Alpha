<?php
// src/utils/InvoiceSurcharge.php
// Helper to apply Stripe surcharge to invoices when payment method is card

require_once __DIR__ . '/StripeFeeCalculator.php';

class InvoiceSurcharge {
    
    /**
     * Apply surcharge to invoice if applicable
     * 
     * @param PDO $pdo Database connection
     * @param int $invoiceId Invoice ID
     * @param array $config App config
     * @param string $paymentMethod Selected payment method (stripe, card, etc.)
     * @return array Updated invoice info with surcharge details
     */
    public static function applyIfNeeded($pdo, $invoiceId, $config, $paymentMethod = 'stripe'): array {
        // Only apply for credit card payments
        if (!in_array(strtolower($paymentMethod), ['stripe', 'card'])) {
            return [
                'surcharge_applied' => false,
                'original_amount' => null,
                'surcharge_amount' => null,
                'new_total' => null,
            ];
        }
        
        // Check if surcharge is enabled
        if (!StripeFeeCalculator::isSurchargeEnabled($config)) {
            return [
                'surcharge_applied' => false,
                'original_amount' => null,
                'surcharge_amount' => null,
                'new_total' => null,
            ];
        }
        
        // Get invoice total
        $stmt = $pdo->prepare('SELECT total, amount_paid FROM invoices WHERE id = ?');
        $stmt->execute([$invoiceId]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$invoice) {
            return [
                'surcharge_applied' => false,
                'error' => 'Invoice not found',
            ];
        }
        
        $remainingAmount = (float)$invoice['total'] - (float)$invoice['amount_paid'];
        
        if ($remainingAmount <= 0) {
            return [
                'surcharge_applied' => false,
                'error' => 'Invoice already paid',
            ];
        }
        
        // Calculate surcharge
        $surcharge = StripeFeeCalculator::calculateSurcharge($remainingAmount, $config);
        
        // Only update invoice if client pays any portion
        if ($surcharge['client_pays'] > 0) {
            $newTotal = (float)$invoice['total'] + $surcharge['client_pays'];
            
            // Update invoice with surcharge
            $update = $pdo->prepare('
                UPDATE invoices 
                SET total = ?, 
                    original_amount = COALESCE(original_amount, ?),
                    surcharge_amount = ?, 
                    surcharge_type = ? 
                WHERE id = ?
            ');
            $update->execute([
                $newTotal,
                $invoice['total'],
                $surcharge['client_pays'],
                $surcharge['surcharge_type'],
                $invoiceId
            ]);
            
            return [
                'surcharge_applied' => true,
                'original_amount' => $remainingAmount,
                'surcharge_amount' => $surcharge['client_pays'],
                'new_total' => $newTotal,
                'display_text' => $surcharge['display_text'],
            ];
        }
        
        return [
            'surcharge_applied' => false,
            'original_amount' => $remainingAmount,
            'surcharge_amount' => 0,
            'new_total' => (float)$invoice['total'],
        ];
    }
    
    /**
     * Get surcharge info for display purposes (doesn't modify invoice)
     * 
     * @param float $amount Amount to calculate surcharge on
     * @param array $config App config
     * @return array Surcharge details
     */
    public static function getInfo(float $amount, array $config): array {
        if (!StripeFeeCalculator::isSurchargeEnabled($config)) {
            return [
                'has_surcharge' => false,
                'text' => '',
            ];
        }
        
        $surcharge = StripeFeeCalculator::calculateSurcharge($amount, $config);
        
        return [
            'has_surcharge' => true,
            'text' => $surcharge['display_text'],
            'client_pays' => $surcharge['client_pays'],
            'merchant_pays' => $surcharge['merchant_pays'],
            'new_total' => $surcharge['new_total'],
        ];
    }
}
