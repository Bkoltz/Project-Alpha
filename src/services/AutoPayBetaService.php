<?php

require_once __DIR__ . '/../utils/autopay_beta.php';

/**
 * BETA / UNAVAILABLE: internal shape only. AutoPay has no production routes,
 * UI, scheduler, email workflow, or enabled Stripe implementation.
 */
interface AutoPayBetaProcessorInterface
{
    public function schedule(array $authorization, array $invoice): string;
}

/**
 * BETA / UNAVAILABLE: every public method is protected by the fail-closed guard.
 */
final class AutoPayBetaService
{
    private AutoPayBetaProcessorInterface $processor;

    public function __construct(AutoPayBetaProcessorInterface $processor)
    {
        require_autopay_beta();
        $this->processor = $processor;
    }

    public function schedule(array $authorization, array $invoice): string
    {
        require_autopay_beta();
        return $this->processor->schedule($authorization, $invoice);
    }
}
