<?php

namespace EasyLog\Services\Storage;

use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\Exception\AuthenticationException;
use Elastic\Elasticsearch\Exception\ClientResponseException;
use Elastic\Elasticsearch\Exception\ServerResponseException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Elasticsearch 存储适配器实现
 *
 * 依赖：elasticsearch/elasticsearch ^8.0
 */
class ElasticsearchAdapter implements StorageAdapterInterface
{
    private Client $client;

    /** @var array<string> 创建索引失败重试队列 */
    private array $knownIndices = [];

    public function __construct(array $config)
    {
        $builder = \Elastic\Elasticsearch\ClientBuilder::create()
            ->setHosts($config['hosts']);

        if (!empty($config['api_key'])) {
            $builder->setApiKey($config['api_key']);
        } elseif (!empty($config['username']) && !empty($config['password'])) {
            $builder->setBasicAuthentication($config['username'], $config['password']);
        }
        if (!empty($config['ssl_verify']) === false) {
            $builder->setSSLVerification(false);
        }
        $this->client = $builder->build();
    }

    public function bulkWrite(string $indexName, array $docs): array
    {
        if (empty($docs)) {
            return ['success' => 0, 'failed' => 0, 'errors' => null];
        }
        $this->ensureIndex($indexName);

        $params = ['body' => []];
        foreach ($docs as $doc) {
            $params['body'][] = ['index' => ['_index' => $indexName]];
            $params['body'][] = $doc;
        }

        try {
            $response = $this->client->bulk($params);
            $errors   = $response['errors'] ?? false;
            $items    = $response['items'] ?? [];
            $success  = 0;
            $failed   = 0;
            $errorList = [];
            foreach ($items as $item) {
                $result = reset($item);
                $status = $result['status'] ?? 0;
                if ($status >= 200 && $status < 300) {
                    $success++;
                } else {
                    $failed++;
                    $errorList[] = $result['error'] ?? 'unknown error';
                }
            }
            return ['success' => $success, 'failed' => $failed, 'errors' => $failed ? $errorList : null];
        } catch (ClientResponseException | ServerResponseException $e) {
            Log::error('EasyLog ES bulk write failed', ['error' => $e->getMessage()]);
            return ['success' => 0, 'failed' => count($docs), 'errors' => [$e->getMessage()]];
        }
    }

    public function search(string $indexPattern, array $dsl): array
    {
        try {
            $response = $this->client->search([
                'index' => $indexPattern,
                'body'  => $dsl,
            ]);
            return [
                'hits'    => array_map(fn ($h) => $h['_source'], $response['hits']['hits'] ?? []),
                'total'   => $response['hits']['total']['value'] ?? 0,
                'took_ms' => $response['took'] ?? 0,
            ];
        } catch (ClientResponseException | ServerResponseException $e) {
            Log::error('EasyLog ES search failed', ['error' => $e->getMessage()]);
            return ['hits' => [], 'total' => 0, 'took_ms' => 0];
        }
    }

    public function aggregate(string $indexPattern, array $dsl): array
    {
        try {
            $response = $this->client->search([
                'index'    => $indexPattern,
                'body'     => array_merge(['size' => 0], $dsl),
            ]);
            return $response['aggregations'] ?? [];
        } catch (ClientResponseException | ServerResponseException $e) {
            Log::error('EasyLog ES aggregate failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    public function createIndex(string $indexName, array $mapping): void
    {
        if (isset($this->knownIndices[$indexName])) {
            return;
        }
        try {
            if (!$this->client->indices()->exists(['index' => $indexName])->asBool()) {
                $this->client->indices()->create([
                    'index' => $indexName,
                    'body'  => ['mappings' => $mapping],
                ]);
            }
            $this->knownIndices[$indexName] = true;
        } catch (ClientResponseException | ServerResponseException $e) {
            Log::error('EasyLog ES create index failed', ['error' => $e->getMessage()]);
        }
    }

    public function ensureMonthlyRoll(string $indexPrefix): void
    {
        // 按月自动创建新索引，确保跨月时立即写入新索引
        $now = now();
        $indexName = $indexPrefix.'-'.$now->format('Ym');
        $this->ensureIndex($indexName);
    }

    public function listIndices(string $indexPrefix): array
    {
        try {
            $resp = $this->client->cat()->indices([
                'index' => $indexPrefix . '*',
                'format' => 'json',
                'h' => 'index',
            ]);
            return array_map(fn ($row) => $row['index'] ?? '', $resp->asArray());
        } catch (\Throwable $e) {
            Log::warning('EasyLog ES listIndices failed', ['prefix' => $indexPrefix, 'error' => $e->getMessage()]);
            return [];
        }
    }

    public function deleteIndex(string $indexName): void
    {
        try {
            $this->client->indices()->delete(['index' => $indexName]);
            unset($this->knownIndices[$indexName]);
        } catch (ClientResponseException | ServerResponseException $e) {
            Log::error('EasyLog ES delete index failed', ['error' => $e->getMessage()]);
        }
    }

    public function ping(): bool
    {
        try {
            return $this->client->ping()->asBool();
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function ensureIndex(string $indexName): void
    {
        if (isset($this->knownIndices[$indexName])) {
            return;
        }
        try {
            if (!$this->client->indices()->exists(['index' => $indexName])->asBool()) {
                $this->client->indices()->create([
                    'index' => $indexName,
                    'body'  => [
                        'mappings' => [
                            'dynamic'    => true, // 业务方自定义字段
                            'properties' => [
                                'ts'           => ['type' => 'date'],
                                'level'        => ['type' => 'keyword'],
                                'service'      => ['type' => 'keyword'],
                                'host'         => ['type' => 'keyword'],
                                'request_id'   => ['type' => 'keyword'],
                                'msg'          => ['type' => 'text'],
                                'tenant_id'    => ['type' => 'keyword'],
                                'workspace_id' => ['type' => 'keyword'],
                                'topic_id'     => ['type' => 'keyword'],
                            ],
                        ],
                    ],
                ]);
            }
            $this->knownIndices[$indexName] = true;
        } catch (ClientResponseException | ServerResponseException $e) {
            Log::error('EasyLog ES ensure index failed', ['index' => $indexName, 'error' => $e->getMessage()]);
            throw new RuntimeException('Failed to ensure index: '.$indexName);
        }
    }
}
