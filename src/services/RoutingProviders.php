<?php

final class RouteRequest
{
    public function __construct(public array $origin, public array $destination) {}
}

final class RouteEstimate
{
    public function __construct(
        public float $distanceMiles,
        public int $durationSeconds,
        public string $provider = 'google_routes',
        public string $attribution = 'Google Maps',
        public ?string $expiresAt = null
    ) {}
}

interface RoutingProviderInterface
{
    public function estimateOneWay(RouteRequest $request): RouteEstimate;
}

final class GoogleRoutesProvider implements RoutingProviderInterface
{
    public function __construct(private string $apiKey) {}

    public function estimateOneWay(RouteRequest $request): RouteEstimate
    {
        if ($this->apiKey === '') {
            throw new RuntimeException('Google Routes is not configured.');
        }
        $payload = [
            'origin' => ['address' => self::formatAddress($request->origin)],
            'destination' => ['address' => self::formatAddress($request->destination)],
            'travelMode' => 'DRIVE',
            'routingPreference' => 'TRAFFIC_UNAWARE',
            'computeAlternativeRoutes' => false,
            'languageCode' => 'en-US',
            'units' => 'IMPERIAL',
        ];
        if ($payload['origin']['address'] === '' || $payload['destination']['address'] === '') {
            throw new InvalidArgumentException('Both saved addresses must be complete enough to route.');
        }
        $ch = curl_init('https://routes.googleapis.com/directions/v2:computeRoutes');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-Goog-Api-Key: ' . $this->apiKey,
                'X-Goog-FieldMask: routes.distanceMeters,routes.duration',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        $decoded = json_decode($body === false ? '' : $body, true);
        if ($status === 429) {
            throw new OverflowException('Google route quota was reached. Enter mileage manually.');
        }
        if ($status < 200 || $status >= 300) {
            $message = is_array($decoded) ? (string)($decoded['error']['message'] ?? '') : '';
            throw new RuntimeException($message ?: ($error ?: 'Google Routes is temporarily unavailable.'));
        }
        $route = $decoded['routes'][0] ?? null;
        if (!is_array($route) || empty($route['distanceMeters'])) {
            throw new InvalidArgumentException('Google could not resolve a driving route. Enter mileage manually.');
        }
        $duration = (string)($route['duration'] ?? '0s');
        return new RouteEstimate(
            round(((float)$route['distanceMeters']) / 1609.344, 3),
            max(0, (int)round((float)rtrim($duration, 's'))),
            'google_routes',
            'Google Maps',
            date('Y-m-d H:i:s', strtotime('+30 days'))
        );
    }

    private static function formatAddress(array $address): string
    {
        return implode(', ', array_filter([
            trim((string)($address['address_line1'] ?? '')) . (trim((string)($address['address_line2'] ?? '')) !== '' ? ' ' . trim((string)$address['address_line2']) : ''),
            trim((string)($address['city'] ?? '')),
            trim((string)($address['state'] ?? '')),
            trim((string)($address['postal_code'] ?? '')),
            trim((string)($address['country'] ?? 'US')),
        ]));
    }
}
