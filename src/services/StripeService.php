<?php
// src/services/StripeService.php

class StripeService {
    private $apiKey;
    private $webhookSecret;
    
    public function __construct($apiKey = null, $webhookSecret = null) {
        $this->apiKey = $apiKey;
        $this->webhookSecret = $webhookSecret;
    }
    
    /**
     * Initialize from payment method configuration
     */
    public static function fromPaymentMethod($pdo, $paymentMethodId) {
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
    public function createOrGetCustomer($pdo, $clientId) {
        try {
            require_once __DIR__ . '/../utils/autopay_beta.php';
            require_autopay_beta();
            $stmt = $pdo->prepare("SELECT stripe_customer_id, name, email FROM clients WHERE id = ?");
            $stmt->execute([$clientId]);
            $clientData = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$clientData) {
                throw new \Exception('Client not found');
            }
            
            if ($clientData && !empty($clientData['stripe_customer_id'])) {
                return $clientData['stripe_customer_id'];
            }
            
            // Create new Stripe customer
            $customerData = [
                'name' => $clientData['name'] ?? 'Unknown',
                'email' => $clientData['email'] ?? null,
                'metadata' => ['client_id' => $clientId]
            ];
            
            $customer = $this->apiRequest('POST', 'customers', $customerData);
            
            // Store customer ID in database
            $pdo->prepare("UPDATE clients SET stripe_customer_id = ? WHERE id = ?")->execute([
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
    public function createSubscription($customerId, $amount, $currency, $interval, $description) {
        try {
            require_once __DIR__ . '/../utils/autopay_beta.php';
            require_autopay_beta();
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
    public function createPaymentIntent($customerId, $amount, $currency, $description) {
        try {
            require_once __DIR__ . '/../utils/autopay_beta.php';
            require_autopay_beta();
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
    public function cancelSubscription($subscriptionId) {
        try {
            return $this->apiRequest('DELETE', "subscriptions/{$subscriptionId}");
        } catch (\Throwable $e) {
            @error_log('[StripeService] Error canceling subscription: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Create a Stripe Checkout Session with surcharge line items
     * @param float $amount Base invoice amount
     * @param float $surchargeAmount Surcharge to add (0 if none)
     * @param string $currency Currency code (e.g. 'usd')
     * @param string $description Description for the payment
     * @param string $successUrl URL to redirect after successful payment
     * @param string $cancelUrl URL to redirect if payment is cancelled
     * @param array $metadata Optional metadata to attach to the session
     * @param string|null $customerId Optional Stripe customer ID for saved cards
     * @param string|null $idempotencyKey Optional Stripe idempotency key
     * @return array Checkout session data including 'url' for redirect
     */
    public function createCheckoutSessionWithSurcharge($amount, $surchargeAmount, $currency, $description, $successUrl, $cancelUrl, $metadata = [], $customerId = null, ?string $idempotencyKey = null) {
        try {
            if ($customerId !== null && $customerId !== '') {
                require_once __DIR__ . '/../utils/autopay_beta.php';
                require_autopay_beta();
            }
            $lineItems = [
                [
                    'price_data' => [
                        'currency' => strtolower($currency ?? 'usd'),
                        'unit_amount' => (int)round($amount * 100), // Convert to cents
                        'product_data' => [
                            'name' => $description
                        ]
                    ],
                    'quantity' => 1
                ]
            ];
            
            // Add surcharge as separate line item if applicable
            if ($surchargeAmount > 0) {
                $lineItems[] = [
                    'price_data' => [
                        'currency' => strtolower($currency ?? 'usd'),
                        'unit_amount' => (int)round($surchargeAmount * 100),
                        'product_data' => [
                            'name' => 'Credit Card Processing Fee'
                        ]
                    ],
                    'quantity' => 1
                ];
            }
            
            $sessionData = [
                'payment_method_types' => ['card'],
                'mode' => 'payment',
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
                'line_items' => $lineItems
            ];
            
            if (!empty($metadata)) {
                $sessionData['metadata'] = $metadata;
                $sessionData['payment_intent_data']['metadata'] = $metadata;
            }
            
            if ($customerId) {
                $sessionData['customer'] = $customerId;
            }
            
            $session = $this->apiRequest('POST', 'checkout/sessions', $sessionData, $idempotencyKey);
            
            return $session;
            
        } catch (\Throwable $e) {
            @error_log('[StripeService] Error creating checkout session: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Create a Stripe Checkout Session for one-time payment (legacy, no surcharge)
     * @param float $amount Amount to charge
     * @param string $currency Currency code (e.g. 'usd')
     * @param string $description Description for the payment
     * @param string $successUrl URL to redirect after successful payment
     * @param string $cancelUrl URL to redirect if payment is cancelled
     * @param array $metadata Optional metadata to attach to the session
     * @return array Checkout session data including 'url' for redirect
     */
    public function createCheckoutSession($amount, $currency, $description, $successUrl, $cancelUrl, $metadata = [], ?string $idempotencyKey = null) {
        try {
            $sessionData = [
                'payment_method_types' => ['card'],
                'mode' => 'payment',
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
                'line_items' => [
                    [
                        'price_data' => [
                            'currency' => strtolower($currency ?? 'usd'),
                            'unit_amount' => (int)round($amount * 100), // Convert to cents
                            'product_data' => [
                                'name' => $description
                            ]
                        ],
                        'quantity' => 1
                    ]
                ]
            ];
            
            if (!empty($metadata)) {
                $sessionData['metadata'] = $metadata;
                // Also copy metadata to the PaymentIntent so webhooks have access to it
                $sessionData['payment_intent_data']['metadata'] = $metadata;
            }
            
            $session = $this->apiRequest('POST', 'checkout/sessions', $sessionData, $idempotencyKey);
            
            return $session;
            
        } catch (\Throwable $e) {
            @error_log('[StripeService] Error creating checkout session: ' . $e->getMessage());
            throw $e;
        }
    }

    /** Retrieve a one-time Checkout session so concurrent payment clicks reuse it. */
    public function getCheckoutSession(string $sessionId): array {
        return $this->apiRequest('GET', 'checkout/sessions/' . rawurlencode($sessionId));
    }

    /** Expire an open one-time Checkout session. */
    public function expireCheckoutSession(string $sessionId): array {
        return $this->apiRequest('POST', 'checkout/sessions/' . rawurlencode($sessionId) . '/expire');
    }

    private static function resolveSecret(array $appConfig, array $rawKeys, string $encryptedKey): ?string {
        foreach ($rawKeys as $key) {
            if (!empty($appConfig[$key])) {
                $value = trim((string)$appConfig[$key]);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        if (empty($appConfig[$encryptedKey])) {
            return null;
        }

        $encVal = trim((string)$appConfig[$encryptedKey]);
        if ($encVal === '') {
            return null;
        }
        if (strpos($encVal, 'plain::') === 0) {
            $plain = trim(substr($encVal, 7));
            return $plain !== '' ? $plain : null;
        }

        require_once __DIR__ . '/../utils/crypto.php';
        $pt = crypto_decrypt($encVal);
        if (!is_string($pt)) {
            return null;
        }
        $pt = trim($pt);
        return $pt !== '' ? $pt : null;
    }
    
    /**
     * Initialize StripeService from app config settings
     * @param array $appConfig The application configuration array
     * @return StripeService|null Returns null if Stripe is not configured
     */
    public static function fromAppConfig($appConfig) {
        $secretKey = self::resolveSecret((array)$appConfig, ['_stripe_secret_key'], 'stripe_secret_key_enc');
        
        if (!$secretKey) {
            return null;
        }
        
        $webhookSecret = self::resolveSecret((array)$appConfig, ['_stripe_webhook_secret'], 'stripe_webhook_secret_enc');
        
        return new self($secretKey, $webhookSecret);
    }
    
    /**
     * Check if Stripe is configured in app settings
     * @param array $appConfig The application configuration array
     * @return bool True if Stripe keys are configured
     */
    public static function isConfigured($appConfig) {
        return self::hasSecretKey((array)$appConfig);
    }

    /**
     * True when the server has a usable Stripe secret key for Checkout/API calls.
     */
    public static function hasSecretKey($appConfig) {
        return self::resolveSecret((array)$appConfig, ['_stripe_secret_key'], 'stripe_secret_key_enc') !== null;
    }

    public static function webhookSecret($appConfig): ?string {
        return self::resolveSecret((array)$appConfig, ['_stripe_webhook_secret'], 'stripe_webhook_secret_enc');
    }
    
    /**
     * List Payment Intents created since a given timestamp
     * Used for reconciliation after downtime
     * @param int $since Unix timestamp
     * @return array List of Payment Intent objects
     */
    public function listPaymentIntents($since) {
        try {
            $allIntents = [];
            $hasMore = true;
            $startingAfter = null;
            
            while ($hasMore) {
                $params = [
                    'limit' => 100,
                    'created[gte]' => $since,
                    'expand[]' => 'charges.data'
                ];
                if ($startingAfter) {
                    $params['starting_after'] = $startingAfter;
                }
                
                $response = $this->apiRequest('GET', 'payment_intents?' . http_build_query($params));
                
                if (!empty($response['data'])) {
                    $allIntents = array_merge($allIntents, $response['data']);
                    $lastItem = end($response['data']);
                    $startingAfter = $lastItem['id'] ?? null;
                }
                
                $hasMore = !empty($response['has_more']);
                
                // Safety limit
                if (count($allIntents) >= 1000) {
                    break;
                }
            }
            
            return $allIntents;
            
        } catch (\Throwable $e) {
            @error_log('[StripeService] Error listing payment intents: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Create a Payment Intent with metadata for tracking
     * @param float $amount Amount in dollars
     * @param string $currency Currency code
     * @param array $metadata Metadata including pa_invoice_id
     * @param string|null $customerId Optional Stripe customer ID
     * @param string|null $paymentMethodId Optional saved payment method for auto-pay
     * @return array Payment Intent object
     */
    public function createPaymentIntentWithMetadata($amount, $currency, $metadata, $customerId = null, $paymentMethodId = null) {
        try {
            if ($paymentMethodId !== null && $paymentMethodId !== '') {
                require_once __DIR__ . '/../utils/autopay_beta.php';
                require_autopay_beta();
            }
            $params = [
                'amount' => (int)round($amount * 100), // Convert to cents
                'currency' => strtolower($currency ?? 'usd'),
                'metadata' => $metadata,
                'automatic_payment_methods' => ['enabled' => true]
            ];
            
            if ($customerId) {
                $params['customer'] = $customerId;
            }
            
            if ($paymentMethodId) {
                $params['payment_method'] = $paymentMethodId;
                $params['confirm'] = true;
                $params['off_session'] = true;
            }
            
            return $this->apiRequest('POST', 'payment_intents', $params);
            
        } catch (\Throwable $e) {
            @error_log('[StripeService] Error creating payment intent: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Verify webhook signature
     */
    public function verifyWebhook($payload, $signature) {
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
     * Create a partial refund on a Stripe Charge.
     *
     * @param string $chargeId The Stripe charge ID to refund.
     * @param int $amountCents Amount to refund in the smallest currency unit (cents).
     * @return array Stripe refund object.
     */
    public function refundCharge($chargeId, $amountCents) {
        try {
            return $this->apiRequest('POST', 'refunds', [
                'charge' => $chargeId,
                'amount' => (int)$amountCents
            ]);
        } catch (\Throwable $e) {
            @error_log('[StripeService] Error creating refund: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Fetch a Stripe balance transaction by ID
     * @param string $btId Balance transaction ID (e.g. 'txn_...')
     * @return array Balance transaction object
     */
    public function getBalanceTransaction($btId) {
        try {
            return $this->apiRequest('GET', "balance_transactions/{$btId}");
        } catch (\Throwable $e) {
            @error_log('[StripeService] Error fetching balance transaction: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Make API request to Stripe
     */
    private function apiRequest($method, $endpoint, $data = [], ?string $idempotencyKey = null) {
        if (!$this->apiKey) {
            throw new \Exception('Stripe API key not configured');
        }
        
        $url = 'https://api.stripe.com/v1/' . $endpoint;
        $ch = curl_init($url);
        
        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/x-www-form-urlencoded'
        ];
        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $headers[] = 'Idempotency-Key: ' . substr($idempotencyKey, 0, 255);
        }
        
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
    private function flattenArray($array, $prefix = '') {
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
