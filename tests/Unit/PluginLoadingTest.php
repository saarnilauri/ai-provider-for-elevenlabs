<?php

declare(strict_types=1);

namespace AiProviderForElevenLabs\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Guards the manual class loading in plugin.php.
 *
 * The packaged plugin ships without an autoloader: .distignore excludes
 * /vendor and /composer.json, so load_classes() is the only thing that makes a
 * class reachable at runtime. This suite bootstraps vendor/autoload.php, which
 * means a class missing from that list passes every other test and fatals only
 * once the plugin is actually installed.
 *
 * That is precisely how TextChunker reached main in 0.4.0: never required, used by
 * ProviderForElevenLabsTextToSpeechModel on the long-form path, and invisible
 * to 130 passing tests. This guard closes that gap permanently, because the
 * cost of forgetting is a fatal in the wild rather than a red test.
 *
 * @coversNothing
 */
class PluginLoadingTest extends TestCase
{
    public function testEveryClassFileUnderSrcIsRequiredByLoadClasses(): void
    {
        $classFiles = $this->classFilePaths();

        // Without this the guard would pass vacuously if the scan ever broke.
        $this->assertNotEmpty(
            $classFiles,
            'Found no PHP files under src/, so this guard proves nothing.'
        );

        $missing = array_values(array_diff($classFiles, $this->requiredPaths()));

        $this->assertSame(
            [],
            $missing,
            'These files under src/ are not required by load_classes() in plugin.php. '
            . 'The packaged plugin has no autoloader, so using them fatals in a real install. '
            . 'Add a require_once for each to load_classes().'
        );
    }

    public function testEveryRequiredPathExistsOnDisk(): void
    {
        $srcDir = $this->repositoryRoot() . '/src';
        $required = $this->requiredPaths();

        $this->assertNotEmpty(
            $required,
            'Parsed no require_once calls out of load_classes(), so this guard proves nothing.'
        );

        $missing = [];

        foreach ($required as $path) {
            if (!is_file($srcDir . $path)) {
                $missing[] = $path;
            }
        }

        $this->assertSame(
            [],
            $missing,
            'load_classes() requires files that do not exist, which fatals on activation.'
        );
    }

    /**
     * Returns the paths load_classes() requires, relative to src/.
     *
     * Read as text rather than by calling load_classes(), because plugin.php
     * returns early when ABSPATH is undefined and never declares its functions.
     *
     * @return list<string> Slash-prefixed paths, e.g. '/Text/TextChunker.php'.
     */
    private function requiredPaths(): array
    {
        $pluginFile = $this->repositoryRoot() . '/plugin.php';
        $source = file_get_contents($pluginFile);

        if ($source === false) {
            $this->fail(sprintf('Unable to read %s.', $pluginFile));
        }

        preg_match_all(
            '~require_once\s+\$plugin_dir\s*\.\s*\'([^\']+)\'~',
            $source,
            $matches
        );

        $paths = $matches[1];
        sort($paths);

        return array_values($paths);
    }

    /**
     * Returns every PHP file under src/, relative to src/.
     *
     * @return list<string> Slash-prefixed paths, e.g. '/Text/TextChunker.php'.
     */
    private function classFilePaths(): array
    {
        $srcDir = $this->repositoryRoot() . '/src';

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        $paths = [];

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($srcDir));
            $paths[] = str_replace(DIRECTORY_SEPARATOR, '/', $relative);
        }

        sort($paths);

        return $paths;
    }

    private function repositoryRoot(): string
    {
        return dirname(__DIR__, 2);
    }
}
