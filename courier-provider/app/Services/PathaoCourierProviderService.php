<?php

namespace App\Services;

use App\Enums\CourierProviderEnum;
use App\Models\CourierProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;


class PathaoCourierProviderService
{
    protected string $baseUrl;
    protected string $accessToken = '';
    protected int $batchSize = 50; // Number of records to insert in a single batch

    /**
     * @throws \Exception
     */
    public function __construct()
    {
        $this->baseUrl = config('services.pathao.base_url');
        $this->accessToken = $this->getAccessToken();
    }

    /**
     * Request Bearer token from Pathao API
     * @throws \Exception
     */
    protected function getAccessToken(): string
    {
        // Try cache first
        if ($token = Cache::get('pathao_access_token')) {
            return $token;
        }

        // Try refresh token if available
        if ($refreshToken = Cache::get('pathao_refresh_token')) {
            $newToken = $this->refreshAccessToken($refreshToken);
            if ($newToken) {
                return $newToken;
            }
        }
        //Otherwise, request new token
        return $this->requestNewAccessToken();
    }


    /**
     * Request a brand-new access token (password grant)
     * @throws \Exception
     */
    protected function requestNewAccessToken(): string
    {
        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
        ])->post("{$this->baseUrl}/aladdin/api/v1/issue-token", [
            'client_id'     => config('services.pathao.client_id'),
            'client_secret' => config('services.pathao.client_secret'),
            'grant_type'    => 'password',
            'username'      => config('services.pathao.username'),
            'password'      => config('services.pathao.password'),
        ]);

        if (!$response->successful()) {
            throw new \Exception('Failed to get Pathao access token: ' . $response->body());
        }

        $data = $response->json();
        $this->storeTokenData($data);

        return $data['access_token'];
    }

    /**
     * Refresh access token using refresh_token grant
     * @throws ConnectionException
     */
    protected function refreshAccessToken(string $refreshToken): ?string
    {
        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
        ])->post("{$this->baseUrl}/aladdin/api/v1/issue-token", [
            'client_id'     => config('services.pathao.client_id'),
            'client_secret' => config('services.pathao.client_secret'),
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);

        if (!$response->successful()) {
            Log::warning('Failed to refresh Pathao token: ' . $response->body());
            return null;
        }

        $data = $response->json();
        $this->storeTokenData($data);

        return $data['access_token'] ?? null;
    }

    /**
     * Store access & refresh token in cache
     */
    protected function storeTokenData(array $data): void
    {
        if (!empty($data['access_token']) && !empty($data['expires_in'])) {
            // Store token slightly shorter than real expiry to avoid edge cases
            Cache::put('pathao_access_token', $data['access_token'], now()->addSeconds($data['expires_in'] - 60));
        }

        if (!empty($data['refresh_token'])) {
            // Store refresh token for a longer period
            Cache::put('pathao_refresh_token', $data['refresh_token'], now()->addDays(7));
        }
    }

    /**
     * Headers for all API requests
     */
    protected function getHeaders(): array
    {
        return [
            'Authorization' => "Bearer {$this->accessToken}",
            'Content-Type'  => 'application/json',
        ];
    }

    /**
     * Fetch cities, zones, areas and store them
     */
    public function storeCourierProviderData(): array
    {
        try {
            $citiesResponse = Http::withHeaders($this->getHeaders())
                ->get("{$this->baseUrl}/aladdin/api/v1/city-list");

            if (!$citiesResponse->successful()) {
                throw new \Exception('Failed to fetch cities');
            }

            $cities = $citiesResponse->json('data.data', []);
            $processedCount = 0;
            $totalBatchCount = 0;

            foreach ($cities as $city) {
                $zonesResponse = Http::withHeaders($this->getHeaders())
                    ->get("{$this->baseUrl}/aladdin/api/v1/cities/{$city['city_id']}/zone-list");

                if (!$zonesResponse->successful()) {
                    Log::warning("Failed to fetch zones for city: {$city['city_id']}");
                    continue;
                }

                $zones = $zonesResponse->json('data.data', []);

                // Process zones in chunks to avoid memory issues
                foreach (array_chunk($zones, 10) as $zoneChunk) {
                    $batchRecords = [];

                    foreach ($zoneChunk as $zone) {
                        $areasResponse = Http::withHeaders($this->getHeaders())
                            ->get("{$this->baseUrl}/aladdin/api/v1/zones/{$zone['zone_id']}/area-list");

                        if (!$areasResponse->successful()) {
                            Log::warning("Failed to fetch areas for zone: {$zone['zone_id']}");
                            continue;
                        }

                        $areas = $areasResponse->json('data.data', []);

                        // Prepare batch records for storage
                        foreach ($areas as $area) {
                            $batchRecords[] = [
                                'provider_name' => CourierProviderEnum::PATHAO->value,
                                'city_id' => $city['city_id'],
                                'zone_id' => $zone['zone_id'],
                                'area_id' => $area['area_id'],
                                'city_name' => $city['city_name'],
                                'zone_name' => $zone['zone_name'],
                                'area_name' => $area['area_name'],
                                'home_delivery_available' => $area['home_delivery_available'] ?? false,
                                'pickup_available' => $area['pickup_available'] ?? false,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ];

                            $processedCount++;

                            // Process batch when reaching batch size
                            if (count($batchRecords) >= $this->batchSize) {
                                $this->storeBatch($batchRecords);
                                $totalBatchCount++;
                                $batchRecords = [];
                            }
                        }
                    }

                    // Store any remaining records
                    if (!empty($batchRecords)) {
                        $this->storeBatch($batchRecords);
                        $totalBatchCount++;
                    }
                }
            }

            return [
                'success'         => true,
                'message'         => "Successfully stored {$processedCount} Pathao records in {$totalBatchCount} batches",
                'processed_count' => $processedCount,
                'batch_count'     => $totalBatchCount,
            ];
        } catch (\Exception $e) {
            Log::error('Error storing Pathao courier data: ' . $e->getMessage());

            return [
                'success' => false,
                'message' => 'Failed to store Pathao courier data: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Store a batch of courier provider records
     */
    protected function storeBatch(array $records): void
    {
        // Use upsert to handle updates/inserts efficiently
        CourierProvider::upsert(
            $records,
            ['provider_name', 'city_id', 'zone_id', 'area_id'], // Unique key constraint fields
            ['city_name', 'zone_name', 'area_name', 'home_delivery_available', 'pickup_available'] // Fields to update if record exists
        );
    }

    /**
     * Store or update a courier provider record
     */
    protected function storeCourierProvider(array $city, array $zone, array $area = []): void
    {
        CourierProvider::updateOrCreate(
            [
                'provider_name' => CourierProviderEnum::PATHAO->value,
                'city_id' => $city['city_id'],
                'zone_id' => $zone['zone_id'],
                'area_id' => $area['area_id'],
            ],
            [
                'city_name' => $city['city_name'],
                'zone_name' => $zone['zone_name'],
                'area_name' => $area['area_name'],
                'home_delivery_available' => $area['home_delivery_available'] ?? false,
                'pickup_available' => $area['pickup_available'] ?? false,
            ]
        );
    }
}
