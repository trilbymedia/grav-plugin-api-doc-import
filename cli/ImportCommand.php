<?php

namespace Grav\Plugin\Console;

use Grav\Common\Grav;
use Grav\Console\ConsoleCommand;
use Grav\Plugin\ApiDocImport\OpenApiImporter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * CLI command for importing OpenAPI specifications
 *
 * Usage:
 *   bin/plugin api-doc-import import openapi.yaml api-reference
 *   bin/plugin api-doc-import import https://api.example.com/openapi.json api-reference
 *   bin/plugin api-doc-import import openapi.yaml api-reference --update
 */
class ImportCommand extends ConsoleCommand
{
    protected function configure(): void
    {
        $this
            ->setName('import')
            ->setDescription('Import OpenAPI/Swagger specification into Grav pages')
            ->addArgument(
                'source',
                InputArgument::REQUIRED,
                'Path to OpenAPI spec file (JSON/YAML) or URL'
            )
            ->addArgument(
                'output',
                InputArgument::REQUIRED,
                'Output path for generated pages (relative to pages folder)'
            )
            ->addOption(
                'update',
                'u',
                InputOption::VALUE_NONE,
                'Update existing pages instead of skipping them'
            )
            ->addOption(
                'no-preserve',
                null,
                InputOption::VALUE_NONE,
                'Do not preserve manual content when updating'
            )
            ->addOption(
                'flat',
                'f',
                InputOption::VALUE_NONE,
                'Do not organize by tags (flat structure)'
            )
            ->setHelp(<<<'HELP'
The <info>%command.name%</info> command imports an OpenAPI/Swagger specification
and generates Grav pages compatible with the Helios theme's api-endpoint template.

<info>Import from local file:</info>
  bin/plugin api-doc-import import openapi.yaml v3/api-reference

<info>Import from URL:</info>
  bin/plugin api-doc-import import https://api.example.com/openapi.json v3/api-reference

<info>Update existing pages:</info>
  bin/plugin api-doc-import import openapi.yaml v3/api-reference --update

<info>Update without preserving manual content:</info>
  bin/plugin api-doc-import import openapi.yaml v3/api-reference --update --no-preserve

<info>Flat structure (no tag folders):</info>
  bin/plugin api-doc-import import openapi.yaml v3/api-reference --flat

The command will:
- Parse the OpenAPI 3.x or Swagger 2.x specification
- Create chapter pages for each API tag (group)
- Create api-endpoint pages for each operation
- Generate request/response examples from schemas
- Extract parameters and response codes

HELP
            );
    }

    protected function serve(): int
    {
        $io = new SymfonyStyle($this->input, $this->output);

        $source = $this->input->getArgument('source');
        $outputPath = $this->input->getArgument('output');

        // Merge options with plugin config
        $grav = Grav::instance();
        $config = $grav['config']->get('plugins.api-doc-import', []);

        if ($this->input->getOption('update')) {
            $config['update_existing'] = true;
        }
        if ($this->input->getOption('no-preserve')) {
            $config['preserve_content'] = false;
        }
        if ($this->input->getOption('flat')) {
            $config['organize_by_tags'] = false;
        }

        $io->title('API Documentation Import');
        $io->text("Source: <info>$source</info>");
        $io->text("Output: <info>$outputPath</info>");
        $io->newLine();

        // Create importer with merged config
        $importer = new OpenApiImporter($grav, $config);

        // Logger callback for CLI output
        $logger = function (string $message, string $type = 'info') use ($io) {
            switch ($type) {
                case 'error':
                    $io->error($message);
                    break;
                case 'warning':
                    $io->warning($message);
                    break;
                default:
                    $io->writeln($message);
            }
        };

        try {
            // Determine if source is URL or file
            if (filter_var($source, FILTER_VALIDATE_URL)) {
                $stats = $importer->importFromUrl($source, $outputPath, $logger);
            } else {
                // Handle relative paths
                if (!str_starts_with($source, '/')) {
                    $source = GRAV_ROOT . '/' . $source;
                }
                $stats = $importer->importFromFile($source, $outputPath, $logger);
            }

            $io->newLine();

            if (!empty($stats['errors'])) {
                $io->warning('Import completed with errors');
                return 1;
            }

            $io->success('Import completed successfully!');
            return 0;

        } catch (\Exception $e) {
            $io->error($e->getMessage());
            return 1;
        }
    }
}
