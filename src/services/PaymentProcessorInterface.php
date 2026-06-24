<?php
// src/services/PaymentProcessorInterface.php
// Pluggable payment processor contract.

interface PaymentProcessorInterface {

    /**
     * Human-readable processor name.
     */
    public function name(): string;

    /**
     * Whether this processor is currently configured for use.
     */
    public function isConfigured(): bool;

    /**
     * Calculate the merchant fee for a charge amount.
     *
     * @param float $amount Charge amount in dollars.
     * @return array {fee, rate_pct, fixed, source}
     */
    public function actualMerchantFee(float $amount): array;

    /**
     * Whether this processor supports passing surcharges to the customer.
     */
    public function supportsSurcharge(): bool;

    /**
     * Create a checkout session that includes a disclosed surcharge line item.
     *
     * @param float $amount Base amount in dollars.
     * @param float $surcharge Surcharge to add as a separate line item.
     * @param string $currency Currency code, e.g. 'usd'.
     * @param string $description Description for the base line item.
     * @param string $successUrl Redirect URL after successful payment.
     * @param string $cancelUrl Redirect URL after cancelled payment.
     * @param array $metadata Optional metadata to attach.
     * @param string|null $customerId Optional Stripe customer ID.
     * @return array Checkout session response.
     */
    public function createSurchargableCheckout(
        float $amount,
        float $surcharge,
        string $currency,
        string $description,
        string $successUrl,
        string $cancelUrl,
        array $metadata = [],
        ?string $customerId = null
    ): array;

    /**
     * Determine if a payment event (e.g. Stripe charge object) was a credit card.
     *
     * @param array $paymentEvent Payment object from the processor.
     * @return bool True if the payment method was a credit card.
     */
    public function isCreditCardPayment(array $paymentEvent): bool;

    /**
     * Refund a surcharge portion of a charge.
     *
     * @param string $chargeOrPaymentId Charge/payment ID to refund against.
     * @param float $surchargeAmount Amount in dollars to refund.
     * @return bool True on success.
     */
    public function refundSurcharge(string $chargeOrPaymentId, float $surchargeAmount): bool;
}
