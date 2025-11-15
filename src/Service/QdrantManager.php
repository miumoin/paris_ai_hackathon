<?php

namespace App\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

class QdrantManager
{
    private Client $client;
    private string $collection;
    private string $qdrantApiKey;

    public function __construct(
        string $qdrantUrl = null,
        string $qdrantApiKey = null,
        string $collection = null
    ) {
        $qdrantUrl = $qdrantUrl ?: $_ENV['QDRANT_CLUSTER_URL'];
        $qdrantApiKey = $qdrantApiKey ?: $_ENV['QDRANT_API_KEY'];
        $collection = $collection ?: 'paris_ai_hackathon';

        $this->qdrantApiKey = $qdrantApiKey;
        $this->collection = $collection;

        // Set API key in headers if provided
        $headers = [];
        if ($qdrantApiKey) {
            $headers['api-key'] = $qdrantApiKey;
        }

        $this->client = new Client([
            'base_uri' => $qdrantUrl,
            'timeout'  => 10.0,
            'headers'  => $headers
        ]);
    }

    /**
     * Create collection if it does not exist
     *
     * @param int $vectorSize Dimension of embeddings (must match model output)
     * @param string $distance "Cosine", "Dot", "Euclid"
     */
    public function createCollection(int $vectorSize, string $distance = 'Cosine'): bool
    {
        $payload = [
            'vectors' => [
                'size' => $vectorSize,
                'distance' => $distance
            ]
        ];

        try {
            $response = $this->client->put("/collections/{$this->collection}", [
                'json' => $payload
            ]);

            $result = json_decode($response->getBody()->getContents(), true);
            return isset($result['status']) && $result['status'] === 'ok';
        } catch (RequestException $e) {
            // Collection might already exist, ignore error
            return false;
        }
    }

    /**
     * Upsert a single vector
     */
    public function upsertVector(string $id, array $vector, string $text): array
    {
        $payload = [
            'points' => [
                [
                    'id' => $id,
                    'vector' => $vector,
                    'payload' => [
                        'text' => $text
                    ]
                ]
            ]
        ];

        $response = $this->client->put("/collections/{$this->collection}/points", [
            'json' => $payload
        ]);

        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * Upsert multiple chunks
     *
     * Each chunk must have:
     * [
     *   'embedding' => [...],
     *   'text' => '...'
     * ]
     */
    public function upsertChunks(array $chunks): array
    {
        $points = [];

        foreach ($chunks as $i => $chunk) {
            $points[] = [
                'id' => $i + 1,  // simple numeric ID, or customize as needed
                'vector' => $chunk['embedding'],
                'payload' => [
                    'text' => $chunk['text']
                ]
            ];
        }

        $response = $this->client->put("/collections/{$this->collection}/points", [
            'json' => ['points' => $points]
        ]);

        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * Delete the current collection permanently
     */
    public function deleteCollection(): bool
    {
        try {
            $response = $this->client->delete("/collections/{$this->collection}");
            $result = json_decode($response->getBody()->getContents(), true);
            return isset($result['status']) && $result['status'] === 'ok';
        } catch (RequestException $e) {
            var_dump( $e->getMessage() );
            return false;
        }
    }

    public function getAllPoints(int $batchSize = 100): array
    {
        $allPoints = [];
        $payload = ['limit' => $batchSize];

        while (true) {
            $response = $this->client->post("/collections/{$this->collection}/points/scroll", [
                'json' => [
                    'limit' => 1000,
                    'with_vectors' => true,
                    'with_payload' => true
                ]
            ]);

            $result = json_decode($response->getBody()->getContents(), true);

            if (empty($result['result'])) {
                break; // no more points
            }

            // Collect points including vectors
            foreach ($result['result']['points'] as $point) {
                $allPoints[] = [
                    'embedding' => $point['vector'],       // vector values
                    'text' => $point['payload']['text'] ?? [] // text or metadata
                ];
            }

            // Check for next_page token (for large datasets)
            if (isset($result['next_page'])) {
                $payload['offset'] = $result['next_page'];
            } else {
                break;
            }
        }

        return $allPoints;
    }
}