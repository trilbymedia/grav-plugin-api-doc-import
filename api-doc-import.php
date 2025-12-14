<?php

namespace Grav\Plugin;

use Composer\Autoload\ClassLoader;
use Grav\Common\Plugin;
use Grav\Plugin\ApiDocImport\OpenApiImporter;

/**
 * API Doc Import Plugin
 *
 * Imports OpenAPI/Swagger specifications into Grav pages for the Helios theme
 *
 * CLI Usage:
 *   bin/plugin api-doc-import import openapi.yaml api-reference
 */
class ApiDocImportPlugin extends Plugin
{
    /**
     * @return array
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'onPluginsInitialized' => [
                ['autoload', 100001],
            ],
        ];
    }

    /**
     * Composer autoload
     */
    public function autoload(): ClassLoader
    {
        return require __DIR__ . '/vendor/autoload.php';
    }

    /**
     * Get importer instance for programmatic use
     */
    public function getImporter(): OpenApiImporter
    {
        return new OpenApiImporter($this->grav, $this->config->get('plugins.api-doc-import'));
    }
}
