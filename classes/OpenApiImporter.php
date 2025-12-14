<?php

namespace Grav\Plugin\ApiDocImport;

use Grav\Common\Grav;
use Grav\Common\Yaml;
use Symfony\Component\Yaml\Yaml as SymfonyYaml;

/**
 * OpenAPI Importer
 *
 * Converts OpenAPI 3.x specifications into Grav pages
 */
class OpenApiImporter
{
    protected Grav $grav;
    protected array $config;
    protected array $spec = [];
    protected array $stats = [
        'created' => 0,
        'updated' => 0,
        'skipped' => 0,
        'errors' => [],
    ];

    public function __construct(Grav $grav, array $config)
    {
        $this->grav = $grav;
        $this->config = $config;
    }

    /**
     * Import from file path
     */
    public function importFromFile(string $filePath, string $outputPath, ?callable $logger = null): array
    {
        $this->log($logger, "Reading OpenAPI spec from: $filePath");

        if (!file_exists($filePath)) {
            throw new \RuntimeException("File not found: $filePath");
        }

        $content = file_get_contents($filePath);
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        if ($extension === 'json') {
            $this->spec = json_decode($content, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \RuntimeException("Invalid JSON: " . json_last_error_msg());
            }
        } elseif (in_array($extension, ['yaml', 'yml'])) {
            $this->spec = SymfonyYaml::parse($content);
        } else {
            throw new \RuntimeException("Unsupported file format: $extension (use .json, .yaml, or .yml)");
        }

        return $this->processSpec($outputPath, $logger);
    }

    /**
     * Import from URL
     */
    public function importFromUrl(string $url, string $outputPath, ?callable $logger = null): array
    {
        $this->log($logger, "Fetching OpenAPI spec from: $url");

        $content = @file_get_contents($url);
        if ($content === false) {
            throw new \RuntimeException("Failed to fetch URL: $url");
        }

        // Try JSON first, then YAML
        $this->spec = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->spec = SymfonyYaml::parse($content);
        }

        return $this->processSpec($outputPath, $logger);
    }

    /**
     * Process the loaded OpenAPI spec
     */
    protected function processSpec(string $outputPath, ?callable $logger = null): array
    {
        $this->stats = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []];

        // Validate spec
        if (!isset($this->spec['openapi']) && !isset($this->spec['swagger'])) {
            throw new \RuntimeException("Invalid OpenAPI/Swagger specification");
        }

        $version = $this->spec['openapi'] ?? $this->spec['swagger'] ?? 'unknown';
        $this->log($logger, "OpenAPI version: $version");

        $info = $this->spec['info'] ?? [];
        $this->log($logger, "API: " . ($info['title'] ?? 'Untitled') . " v" . ($info['version'] ?? '?'));

        // Get base path for pages
        $pagesPath = $this->grav['locator']->findResource('page://', true);
        $fullOutputPath = rtrim($pagesPath, '/') . '/' . ltrim($outputPath, '/');

        // Ensure output directory exists
        if (!is_dir($fullOutputPath)) {
            mkdir($fullOutputPath, 0755, true);
            $this->log($logger, "Created output directory: $outputPath");
        }

        // Create root chapter page for the API
        $this->createRootChapter($fullOutputPath, $info);

        // Process paths
        $paths = $this->spec['paths'] ?? [];
        $this->log($logger, "Found " . count($paths) . " paths to process");

        // Group endpoints by tag
        $endpointsByTag = $this->groupEndpointsByTag($paths);

        // Generate pages
        $folderIndex = $this->config['folder_prefix_start'] ?? 1;

        foreach ($endpointsByTag as $tag => $endpoints) {
            $tagFolder = $this->generateTagFolder($tag, $folderIndex, $fullOutputPath);
            $this->log($logger, "Processing tag: $tag ({$tagFolder})");

            $endpointIndex = 1;
            foreach ($endpoints as $endpoint) {
                try {
                    $this->createEndpointPage($endpoint, $tagFolder, $endpointIndex, $logger);
                    $endpointIndex++;
                } catch (\Exception $e) {
                    $this->stats['errors'][] = $e->getMessage();
                    $this->log($logger, "  ERROR: " . $e->getMessage(), 'error');
                }
            }

            $folderIndex++;
        }

        $this->log($logger, "");
        $this->log($logger, "Import complete:");
        $this->log($logger, "  Created: {$this->stats['created']}");
        $this->log($logger, "  Updated: {$this->stats['updated']}");
        $this->log($logger, "  Skipped: {$this->stats['skipped']}");
        if (!empty($this->stats['errors'])) {
            $this->log($logger, "  Errors: " . count($this->stats['errors']));
        }

        return $this->stats;
    }

    /**
     * Group endpoints by their first tag
     */
    protected function groupEndpointsByTag(array $paths): array
    {
        $grouped = [];

        foreach ($paths as $path => $methods) {
            foreach ($methods as $method => $operation) {
                // Skip non-HTTP methods (like 'parameters', 'servers', etc.)
                if (!in_array(strtoupper($method), ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'])) {
                    continue;
                }

                $tags = $operation['tags'] ?? ['default'];
                $primaryTag = $tags[0];

                if (!isset($grouped[$primaryTag])) {
                    $grouped[$primaryTag] = [];
                }

                $grouped[$primaryTag][] = [
                    'path' => $path,
                    'method' => strtoupper($method),
                    'operation' => $operation,
                ];
            }
        }

        // Sort by tag name
        ksort($grouped);

        return $grouped;
    }

    /**
     * Generate tag folder path
     */
    protected function generateTagFolder(string $tag, int $index, string $basePath): string
    {
        $slug = $this->slugify($tag);
        $prefix = str_pad($index, 2, '0', STR_PAD_LEFT);

        $folderPath = $basePath . '/' . $prefix . '.' . $slug;

        if (!is_dir($folderPath)) {
            mkdir($folderPath, 0755, true);

            // Create chapter page for the tag
            $this->createChapterPage($tag, $folderPath);
        }

        return $folderPath;
    }

    /**
     * Create the root chapter page for the API
     */
    protected function createRootChapter(string $folderPath, array $info): void
    {
        $filePath = $folderPath . '/chapter.md';
        $title = $info['title'] ?? 'API Documentation';
        $description = $info['description'] ?? '';

        $frontmatter = [
            'title' => $title,
            'template' => 'chapter',
            'taxonomy' => [
                'category' => ['docs'],
            ],
        ];

        $existingContent = '';
        $preserveContent = $this->config['preserve_content'] ?? true;

        // If updating, preserve existing content below frontmatter
        if (file_exists($filePath) && $preserveContent) {
            $existingFileContent = file_get_contents($filePath);
            if (preg_match('/^---\s*\n.*?\n---\s*\n(.*)$/s', $existingFileContent, $matches)) {
                $existingContent = $matches[1];
            }
        }

        $content = "---\n" . SymfonyYaml::dump($frontmatter, 4) . "---\n";

        if (!empty(trim($existingContent))) {
            $content .= $existingContent;
        } elseif (!empty($description)) {
            // Add API description for new pages
            $content .= "\n" . $description . "\n";
        }

        file_put_contents($filePath, $content);
    }

    /**
     * Create a chapter page for a tag group
     */
    protected function createChapterPage(string $tag, string $folderPath): void
    {
        $filePath = $folderPath . '/chapter.md';
        $frontmatter = [
            'title' => ucwords(str_replace(['-', '_'], ' ', $tag)),
            'template' => 'chapter',
            'taxonomy' => [
                'category' => ['docs'],
            ],
        ];

        $existingContent = '';
        $preserveContent = $this->config['preserve_content'] ?? true;

        // If updating, preserve existing content below frontmatter
        if (file_exists($filePath) && $preserveContent) {
            $existingFileContent = file_get_contents($filePath);
            if (preg_match('/^---\s*\n.*?\n---\s*\n(.*)$/s', $existingFileContent, $matches)) {
                $existingContent = $matches[1];
            }
        }

        $content = "---\n" . SymfonyYaml::dump($frontmatter, 4) . "---\n";

        if (!empty(trim($existingContent))) {
            $content .= $existingContent;
        }

        file_put_contents($filePath, $content);
    }

    /**
     * Create an endpoint page
     */
    protected function createEndpointPage(array $endpoint, string $tagFolder, int $index, ?callable $logger = null): void
    {
        $operation = $endpoint['operation'];
        $method = $endpoint['method'];
        $path = $endpoint['path'];

        // Generate title and slug
        $title = $operation['summary'] ?? $this->generateTitle($method, $path);
        $slug = $this->slugify($operation['operationId'] ?? $title);
        $prefix = str_pad($index, 2, '0', STR_PAD_LEFT);

        $folderPath = $tagFolder . '/' . $prefix . '.' . $slug;
        $filePath = $folderPath . '/api-endpoint.md';

        // Check if already exists
        if (file_exists($filePath)) {
            if (!($this->config['update_existing'] ?? false)) {
                $this->stats['skipped']++;
                $this->log($logger, "  Skipped (exists): $method $path");
                return;
            }
        }

        // Build frontmatter
        $api = [
            'method' => $method,
            'path' => $path,
        ];

        if (!empty($operation['description'] ?? $operation['summary'])) {
            $api['description'] = $operation['description'] ?? $operation['summary'];
        }

        // Extract parameters
        $parameters = $this->extractParameters($operation, $path);
        if (!empty($parameters)) {
            $api['parameters'] = $parameters;
        }

        // Generate request example
        $requestExample = $this->generateRequestExample($operation);
        if ($requestExample) {
            $api['request_example'] = $requestExample;
        }

        // Generate response example
        $responseExample = $this->generateResponseExample($operation);
        if ($responseExample) {
            $api['response_example'] = $responseExample;
        }

        // Extract response codes
        $responseCodes = $this->extractResponseCodes($operation);
        if (!empty($responseCodes)) {
            $api['response_codes'] = $responseCodes;
        }

        $frontmatter = [
            'title' => $title,
            'template' => 'api-endpoint',
            'taxonomy' => $this->config['defaults']['taxonomy'] ?? ['category' => ['docs']],
            'api' => $api,
        ];

        // Create directory if needed
        if (!is_dir($folderPath)) {
            mkdir($folderPath, 0755, true);
        }

        $isUpdate = file_exists($filePath);
        $existingContent = '';

        // When updating, extract and preserve existing content below frontmatter
        if ($isUpdate) {
            $existingFileContent = file_get_contents($filePath);
            // Match frontmatter and capture everything after it
            if (preg_match('/^---\s*\n.*?\n---\s*\n(.*)$/s', $existingFileContent, $matches)) {
                $existingContent = $matches[1];
            }
        }

        // Build the new file content
        $newContent = "---\n" . SymfonyYaml::dump($frontmatter, 6, 2) . "---\n";

        $preserveContent = $this->config['preserve_content'] ?? true;

        if ($isUpdate) {
            if ($preserveContent && !empty(trim($existingContent))) {
                // Updating with preserve: only update frontmatter, keep existing content
                $newContent .= $existingContent;
                $this->log($logger, "  Updated (frontmatter only): $method $path");
            } else {
                // Updating without preserve: regenerate content from OpenAPI
                if (!empty($operation['description']) && strlen($operation['description']) > 100) {
                    $newContent .= "\n" . $operation['description'] . "\n";
                }
                $this->log($logger, "  Updated (full replace): $method $path");
            }
            $this->stats['updated']++;
        } else {
            // Creating new: optionally add OpenAPI description as starter content
            if (!empty($operation['description']) && strlen($operation['description']) > 100) {
                $newContent .= "\n" . $operation['description'] . "\n";
            }
            $this->stats['created']++;
            $this->log($logger, "  Created: $method $path");
        }

        file_put_contents($filePath, $newContent);
    }

    /**
     * Extract parameters from operation
     */
    protected function extractParameters(array $operation, string $path): array
    {
        $params = [];

        // Path/query/header parameters
        foreach ($operation['parameters'] ?? [] as $param) {
            $params[] = [
                'name' => $param['name'],
                'type' => $param['schema']['type'] ?? 'string',
                'required' => $param['required'] ?? false,
                'description' => $param['description'] ?? '',
            ];
        }

        // Request body parameters (for POST/PUT/PATCH)
        if (isset($operation['requestBody']['content'])) {
            $content = $operation['requestBody']['content'];
            $schema = $content['application/json']['schema'] ?? null;

            if ($schema) {
                $bodyParams = $this->extractSchemaParams($schema);
                $params = array_merge($params, $bodyParams);
            }
        }

        return $params;
    }

    /**
     * Extract parameters from schema
     */
    protected function extractSchemaParams(array $schema, string $prefix = ''): array
    {
        $params = [];

        // Handle $ref
        if (isset($schema['$ref'])) {
            $schema = $this->resolveRef($schema['$ref']);
        }

        $properties = $schema['properties'] ?? [];
        $required = $schema['required'] ?? [];

        foreach ($properties as $name => $prop) {
            // Handle nested $ref
            if (isset($prop['$ref'])) {
                $prop = $this->resolveRef($prop['$ref']);
            }

            $fullName = $prefix ? "{$prefix}.{$name}" : $name;

            $params[] = [
                'name' => $fullName,
                'type' => $prop['type'] ?? 'string',
                'required' => in_array($name, $required),
                'description' => $prop['description'] ?? '',
            ];
        }

        return $params;
    }

    /**
     * Resolve a $ref pointer
     */
    protected function resolveRef(string $ref): array
    {
        // Handle #/components/schemas/Name format
        if (strpos($ref, '#/') === 0) {
            $path = explode('/', substr($ref, 2));
            $current = $this->spec;

            foreach ($path as $segment) {
                if (!isset($current[$segment])) {
                    return [];
                }
                $current = $current[$segment];
            }

            return $current;
        }

        return [];
    }

    /**
     * Fields to exclude from request examples (typically server-generated)
     */
    protected array $serverGeneratedFields = [
        'id',
        'created_at',
        'createdAt',
        'created',
        'updated_at',
        'updatedAt',
        'updated',
        'modified_at',
        'modifiedAt',
        'modified',
        'timestamp',
        'uuid',
    ];

    /**
     * Generate request example from schema
     */
    protected function generateRequestExample(array $operation): ?string
    {
        if (!isset($operation['requestBody']['content'])) {
            return null;
        }

        $content = $operation['requestBody']['content'];

        // Try different content types in order of preference
        $contentTypes = [
            'application/json',
            'application/x-www-form-urlencoded',
            'multipart/form-data',
        ];

        $schema = null;
        $selectedContent = null;

        foreach ($contentTypes as $contentType) {
            if (isset($content[$contentType]['schema'])) {
                $schema = $content[$contentType]['schema'];
                $selectedContent = $content[$contentType];
                break;
            }
        }

        if (!$schema) {
            return null;
        }

        // Check for example first
        if (isset($selectedContent['example'])) {
            $example = $selectedContent['example'];
            // Remove server-generated fields from explicit examples too
            if (is_array($example)) {
                $example = $this->removeServerGeneratedFields($example);
            }
            return json_encode($example, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }

        // Generate from schema, excluding server-generated fields
        $example = $this->generateExampleFromSchema($schema, true);
        if ($example) {
            return json_encode($example, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }

        return null;
    }

    /**
     * Remove server-generated fields from an example array
     */
    protected function removeServerGeneratedFields(array $data): array
    {
        foreach ($this->serverGeneratedFields as $field) {
            unset($data[$field]);
        }
        return $data;
    }

    /**
     * Generate response example from schema
     */
    protected function generateResponseExample(array $operation): ?string
    {
        $responses = $operation['responses'] ?? [];

        // Try 200, 201, or first 2xx response
        $successCodes = ['200', '201', '202', '204'];
        $responseSchema = null;

        foreach ($successCodes as $code) {
            if (isset($responses[$code]['content']['application/json'])) {
                $content = $responses[$code]['content']['application/json'];

                // Check for example first
                if (isset($content['example'])) {
                    return json_encode($content['example'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                }

                $responseSchema = $content['schema'] ?? null;
                if ($responseSchema) {
                    break;
                }
            }
        }

        if ($responseSchema) {
            $example = $this->generateExampleFromSchema($responseSchema);
            if ($example) {
                return json_encode($example, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            }
        }

        return null;
    }

    /**
     * Generate example data from schema
     *
     * @param array $schema The schema to generate example from
     * @param bool $isRequest If true, exclude server-generated fields (id, timestamps, etc.)
     */
    protected function generateExampleFromSchema(array $schema, bool $isRequest = false): mixed
    {
        // Handle $ref
        if (isset($schema['$ref'])) {
            $schema = $this->resolveRef($schema['$ref']);
        }

        // Check for explicit example
        if (isset($schema['example'])) {
            $example = $schema['example'];
            if ($isRequest && is_array($example)) {
                return $this->removeServerGeneratedFields($example);
            }
            return $example;
        }

        $type = $schema['type'] ?? 'object';

        switch ($type) {
            case 'object':
                $obj = [];
                foreach ($schema['properties'] ?? [] as $name => $prop) {
                    // Skip server-generated fields for request examples
                    if ($isRequest && in_array($name, $this->serverGeneratedFields)) {
                        continue;
                    }
                    $obj[$name] = $this->generateExampleFromSchema($prop, $isRequest);
                }
                return $obj ?: null;

            case 'array':
                $items = $schema['items'] ?? [];
                $itemExample = $this->generateExampleFromSchema($items, $isRequest);
                return $itemExample ? [$itemExample] : [];

            case 'string':
                if (isset($schema['enum'])) {
                    return $schema['enum'][0];
                }
                if (isset($schema['format'])) {
                    return match ($schema['format']) {
                        'date-time' => '2024-01-15T10:30:00Z',
                        'date' => '2024-01-15',
                        'email' => 'user@example.com',
                        'uri', 'url' => 'https://example.com',
                        'uuid' => '550e8400-e29b-41d4-a716-446655440000',
                        default => 'string',
                    };
                }
                return $schema['example'] ?? 'string';

            case 'integer':
                return $schema['example'] ?? 1;

            case 'number':
                return $schema['example'] ?? 1.0;

            case 'boolean':
                return $schema['example'] ?? true;

            default:
                return null;
        }
    }

    /**
     * Extract response codes from operation
     */
    protected function extractResponseCodes(array $operation): array
    {
        $codes = [];
        $responses = $operation['responses'] ?? [];

        foreach ($responses as $code => $response) {
            // Skip non-numeric codes like 'default'
            if (!is_numeric($code)) {
                continue;
            }

            $codes[] = [
                'code' => (string) $code,
                'description' => $response['description'] ?? '',
            ];
        }

        // Sort by code
        usort($codes, fn($a, $b) => $a['code'] <=> $b['code']);

        return $codes;
    }

    /**
     * Generate title from method and path
     */
    protected function generateTitle(string $method, string $path): string
    {
        // Extract resource from path
        $parts = array_filter(explode('/', $path));
        $resource = '';

        foreach (array_reverse($parts) as $part) {
            if (!str_starts_with($part, '{')) {
                $resource = $part;
                break;
            }
        }

        $resource = ucfirst(rtrim($resource, 's')); // users -> User

        return match ($method) {
            'GET' => str_contains($path, '{') ? "Get $resource" : "List {$resource}s",
            'POST' => "Create $resource",
            'PUT', 'PATCH' => "Update $resource",
            'DELETE' => "Delete $resource",
            default => "$method $path",
        };
    }

    /**
     * Convert string to URL-friendly slug
     */
    protected function slugify(string $text): string
    {
        $text = preg_replace('/[^a-zA-Z0-9\s-]/', '', $text);
        $text = preg_replace('/[\s_]+/', '-', $text);
        $text = preg_replace('/-+/', '-', $text);
        $text = strtolower(trim($text, '-'));

        return $text ?: 'endpoint';
    }

    /**
     * Log message using callback
     */
    protected function log(?callable $logger, string $message, string $type = 'info'): void
    {
        if ($logger) {
            $logger($message, $type);
        }
    }

    /**
     * Get import statistics
     */
    public function getStats(): array
    {
        return $this->stats;
    }
}
