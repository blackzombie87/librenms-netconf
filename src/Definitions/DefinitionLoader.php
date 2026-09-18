<?php

namespace SafferIt\LibrenmsNetconf\Definitions;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Loads YAML definitions from one or more directories (recursively). Later directories
 * override earlier ones by definition `name`, so a user directory can replace shipped
 * files. Results are cached per process.
 */
class DefinitionLoader
{
    /** @var list<string> */
    private array $directories;

    /** @var array<string, Definition>|null */
    private ?array $cache = null;

    /** @var list<string> */
    private array $errors = [];

    /**
     * @param  list<string>  $directories  lowest priority first
     */
    public function __construct(array $directories)
    {
        $this->directories = array_values(array_filter($directories, fn ($d) => $d !== '' && $d !== null));
    }

    public static function shippedDirectory(): string
    {
        return realpath(__DIR__ . '/../../resources/definitions') ?: __DIR__ . '/../../resources/definitions';
    }

    /**
     * @return list<string>
     */
    public function directories(): array
    {
        return $this->directories;
    }

    /**
     * All valid definitions keyed by name. Invalid files are collected in errors().
     *
     * @return array<string, Definition>
     */
    public function all(): array
    {
        if ($this->cache === null) {
            $this->cache = [];
            $this->errors = [];
            foreach ($this->directories as $dir) {
                foreach ($this->files($dir) as $file) {
                    try {
                        $definition = $this->load($file);
                        $this->cache[$definition->name] = $definition;
                    } catch (DefinitionException $e) {
                        $this->errors[] = $e->getMessage();
                    }
                }
            }
            ksort($this->cache);
        }

        return $this->cache;
    }

    public function get(string $name): ?Definition
    {
        return $this->all()[$name] ?? null;
    }

    /**
     * Problems found while loading (file + reason); empty when everything parsed.
     *
     * @return list<string>
     */
    public function errors(): array
    {
        $this->all();

        return $this->errors;
    }

    public function forget(): void
    {
        $this->cache = null;
    }

    /**
     * @throws DefinitionException
     */
    public function load(string $file): Definition
    {
        if (! is_readable($file)) {
            throw new DefinitionException("$file: not readable");
        }

        try {
            $data = Yaml::parseFile($file);
        } catch (ParseException $e) {
            throw new DefinitionException("$file: YAML parse error: " . $e->getMessage());
        }

        if (! is_array($data)) {
            throw new DefinitionException("$file: top level must be a mapping");
        }

        return (new DefinitionParser)->parse($data, $file);
    }

    /**
     * @return list<string>
     */
    private function files(string $dir): array
    {
        if (! is_dir($dir)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && in_array(strtolower($file->getExtension()), ['yaml', 'yml'], true)) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }
}
