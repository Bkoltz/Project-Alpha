<?php
// src/services/StripeProcessor.php
// Stripe implementation of the pluggable PaymentProcessorInterface.

require_once __DIR__ . '/StripeService.php';

class StripeProcessor implements PaymentProcessorInterface {

    /** @var StripeService */
    private $stripeService;

    public function __construct(StripeService $stripeService) {
        $this->stripeService = $stripeService;
    }

    /**
     * Build a StripeProcessor from application config if Stripe is configured.
     *
     * @param array $appConfig Application configuration.
     * @return self|null
     */
    public static function fromAppConfig(array $appConfig): ?self {
        if (!StripeService::isConfigured($appConfig)) {
            return null;
        }

        $service = StripeService::fromAppConfig($appConfig);
        if (!$service) {
            return null;
        }

        return new self($service);
    }

    public function name(): string {
        return 'Stripe';
    }

    public function isConfigured(): bool {
        return StripeService::isConfigured($GLOBALS['appConfig'] ?? []);
    }

    public function actualMerchantFee(float $amount): array {
        $config = $GLOBALS['appConfig'] ?? [];

        $ratePct = (float)($config['stripe_effective_rate_pct'] ?? 2.9);
        $fixed   = (float)($config['stripe_effective_fixed'] ?? 0.30);
        $source  = isset($config['stripe_effective_rate_pct']) ? 'synced' : 'fallback';

        $fee = round(($amount * $ratePct / 100) + $fixed, 2);

        return [
            'fee'      => $fee,
            'rate_pct' => $ratePct,
            'fixed'    => $fixed,
            'source'   => $source,
        ];
    }

    public function supportsSurcharge(): bool {
        return true;
    }

    public function createSurchargableCheckout(
        float $amount,
        float $surcharge,
        string $currency,
        string $description,
        string $successUrl,
        string $cancelUrl,
        array $metadata = [],
        ?string $customerId = null
    ): array {
        return $this->stripeService->createCheckoutSessionWithSurcharge(
            $amount,
            $surcharge,
            $currency,
            $description,
            $successUrl,
            $cancelUrl,
            $metadata,
            $customerId
        );
    }

    public function isCreditCardPayment(array $paymentEvent): bool {
        $funding = $paymentEvent['charges']['data'][0]['payment_method_details']['card']['funding'] ?? null;
        return $funding === 'credit';
    }

    public function refundSurcharge(string $chargeOrPaymentId, float $surchargeAmount): bool {
        $amountCents = (int)round($surchargeAmount * 100);

        if ($amountCents <= 0) {
            return false;
        }

        try {
            $result = $this->stripeService->refundCharge($chargeOrPaymentId, $amountCents);
            return empty($result['error']);
        } catch (\Throwable $e) {
            @error_log('[StripeProcessor] Error refunding surcharge: ' . $e->getMessage());
            return false;
        }
    }
}
