<?php
// src/services/StripeService.php

class StripeService
{
    private $apiKey;
    private $webhookSecret;

    public function __construct($apiKey = null, $webhookSecret = null)
    {
        $this->apiKey = $apiKey;
        $this->webhookSecret = $webhookSecret;
    }

    /**
     * Initialize from payment method configuration
     */
    public static function fromPaymentMethod($pdo, $paymentMethodId)
    {
        try {
            $stmt = $pdo->prepare("SELECT config FROM payment_methods WHERE id = ? AND provider = 'stripe' AND is_active = 1");
            $stmt->execute([$paymentMethodId]);
            $config = $stmt->fetchColumn();

            if (!$config) {
                throw new \Exception('Payment method not found or inactive');
            }

            $configData = json_decode($config, true);
            return new self(
                $configData['secret_key'] ?? null,
                $configData['webhook_secret'] ?? null
            );
        } catch (\Throwable $e) {
            @error_log('[StripeService] Error loading payment method: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Create or retrieve a Stripe customer for a client
     */
    public function createOrGetCustomer($pdo, $clientId)
    {
        try {
            // Check if customer already exists in DB
            $stmt = $pdo->prepare("SELECT config FROM clients WHERE id = ?");
            $stmt->execute([$clientId]);
            $client = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$client) {
                throw new \Exception('Client not found');
            }

            // Check if stripe_customer_id exists in client metadata
            // Note: You may need to add a stripe_customer_id column to clients table
            $stmt = $pdo->prepare("SELECT stripe_customer_id, client_name, email FROM client WHERE client_id = ?");
            $stmt->execute([$clientId]);
            $clientData = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($clientData && !empty($clientData['stripe_customer_id'])) {
                return $clientData['stripe_customer_id'];
            }

            // Create new Stripe customer
            $customerData = [
                'name' => $clientData['client_name'] ?? 'Unknown',
                'email' => $clientData['email'] ?? null,
                'metadata' => ['client_id' => $clientId]
            ];

            $customer = $this->apiRequest('POST', 'customers', $customerData);

            // Store customer ID in database
            $pdo->prepare("UPDATE client SET stripe_customer_id = ? WHERE client_id = ?")->execute([
                $customer['id'],
                $clientId
            ]);

            return $customer['id'];
        } catch (\Throwable $e) {
            @error_log('[StripeService] Error creating customer: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Create a Stripe subscription for recurring payments
     */
    public function createSubscription($customerId, $amount, $currency, $interval, $description)
    {
        try {
            // Create a price for this subscription
            $price = $this->apiRequest('POST', 'prices', [
                'unit_amount' => (int)($amount * 100), // Convert to cents
                'currency' => strtolower($currency ?? 'usd'),
                'recurring' => [
                    'interval' => $interval // 'day', 'week', 'month', or 'year'
                ],
                'product_data' => [
                    'name' => $description
                ]
            ]);

            // Create subscription
            $subscription = $this->apiRequest('POST', 'subscriptions', [
                'customer' => $customerId,
                'items' => [
                    ['price' => $price['id']]
                ],
                'expand' => ['latest_invoice.payment_intent']
            ]);

            return $subscription;
        } catch (\Throwable $e) {
            @error_log('[StripeService] Error creating subscription: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Create a one-time payment intent
     */
    public function createPaymentIntent($customerId, $amount, $currency, $description)
    {
        try {
            $paymentIntent = $this->apiRequest('POST', 'payment_intents', [
                'amount' => (int)($amount * 100), // Convert to cents
                'currency' => strtolower($currency ?? 'usd'),
                'customer' => $customerId,
                'description' => $description,
                'automatic_payment_methods' => [
                    'enabled' => true
                ]
            ]);

            return $paymentIntent;
        } catch (\Throwable $e) {
            @error_log('[StripeService] Error creating payment intent: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Cancel a subscription
     */
    public function cancelSubscription($subscriptionId)
    {
        try {
            return $this->apiRequest('DELETE', "subscriptions/{$subscriptionId}");
        } catch (\Throwable $e) {
            @error_log('[StripeService] Error canceling subscription: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Verify webhook signature
     */
    public function verifyWebhook($payload, $signature)
    {
        if (!$this->webhookSecret) {
            throw new \Exception('Webhook secret not configured');
        }

        $expectedSignature = hash_hmac('sha256', $payload, $this->webhookSecret);

        // Parse signature header
        $elements = explode(',', $signature);
        $signatureData = [];
        foreach ($elements as $element) {
            $item = explode('=', $element, 2);
            if (count($item) === 2) {
                $signatureData[$item[0]] = $item[1];
            }
        }

        if (empty($signatureData['v1'])) {
            throw new \Exception('Invalid signature format');
        }

        if (!hash_equals($expectedSignature, $signatureData['v1'])) {
            throw new \Exception('Signature verification failed');
        }

        return true;
    }

    /**
     * Make API request to Stripe
     */
    private function apiRequest($method, $endpoint, $data = [])
    {
        if (!$this->apiKey) {
            throw new \Exception('Stripe API key not configured');
        }

        $url = 'https://api.stripe.com/v1/' . $endpoint;
        $ch = curl_init($url);

        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/x-www-form-urlencoded'
        ];

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => $method
        ]);

        if (in_array($method, ['POST', 'PUT', 'PATCH']) && !empty($data)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($this->flattenArray($data)));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = json_decode($response, true);

        if ($httpCode >= 400 || !empty($decoded['error'])) {
            $error = $decoded['error']['message'] ?? 'Unknown error';
            throw new \Exception("Stripe API error: {$error}");
        }

        return $decoded;
    }

    /**
     * Flatten nested array for Stripe API (e.g. ['metadata' => ['key' => 'value']] becomes ['metadata[key]' => 'value'])
     */
    private function flattenArray($array, $prefix = '')
    {
        $result = [];
        foreach ($array as $key => $value) {
            $newKey = $prefix === '' ? $key : $prefix . '[' . $key . ']';
            if (is_array($value)) {
                $result = array_merge($result, $this->flattenArray($value, $newKey));
            } else {
                $result[$newKey] = $value;
            }
        }
        return $result;
    }
}
