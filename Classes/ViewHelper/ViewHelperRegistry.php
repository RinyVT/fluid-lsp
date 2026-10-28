<?php

declare(strict_types=1);

namespace RinyVT\FluidLsp\ViewHelper;

use Composer\Autoload\ClassLoader;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;
use Throwable;
use TYPO3Fluid\Fluid\Core\ViewHelper\ArgumentDefinition;
use TYPO3Fluid\Fluid\Core\ViewHelper\ViewHelperInterface;
use TYPO3Fluid\Fluid\Core\ViewHelper\ViewHelperResolver;

final class ViewHelperRegistry
{
    private const SUFFIX = 'ViewHelper';

    /** @var array<string, array<string, class-string<ViewHelperInterface>>> */
    private array $classesByPhpNamespace = [];

    /** @var array<class-string<ViewHelperInterface>, array<string, ArgumentDefinition>> */
    private array $argumentsByClass = [];

    public function __construct(
        private readonly ClassLoader $classLoader,
        private readonly ViewHelperResolver $resolver,
    ) {
    }

    /**
     * Global namespaces plus the ones the template declares itself.
     *
     * @return array<string, list<string>> prefix => PHP namespaces, last one wins
     */
    public function namespacesFor(string $template): array
    {
        $namespaces = [];
        foreach ($this->resolver->getNamespaces() as $prefix => $phpNamespaces) {
            // Fluid 4+ also allows delegates and null (ignored namespace) here.
            $namespaces[$prefix] = array_values(array_filter((array)$phpNamespaces, 'is_string'));
        }
        foreach (self::declaredNamespaces($template) as $prefix => $phpNamespace) {
            $namespaces[$prefix][] = $phpNamespace;
        }

        return $namespaces;
    }

    /**
     * @return array<string, string> prefix => PHP namespace
     */
    public static function declaredNamespaces(string $template): array
    {
        $namespaces = [];
        preg_match_all('/xmlns:(\w+)="http:\/\/typo3\.org\/ns\/([^"]+)"/', $template, $matches, PREG_SET_ORDER);
        foreach ($matches as [, $prefix, $path]) {
            $namespaces[$prefix] = str_replace('/', '\\', trim($path, '/'));
        }
        preg_match_all('/\{namespace\s+(\w+)\s*=\s*([\w\\\\]+)\s*\}/', $template, $matches, PREG_SET_ORDER);
        foreach ($matches as [, $prefix, $phpNamespace]) {
            $namespaces[$prefix] = trim($phpNamespace, '\\');
        }

        return $namespaces;
    }

    /**
     * @param array<string, list<string>> $namespaces
     * @return array<string, class-string<ViewHelperInterface>> "f:format.date" => class
     */
    public function all(array $namespaces): array
    {
        $all = [];
        foreach ($namespaces as $prefix => $phpNamespaces) {
            foreach ($phpNamespaces as $phpNamespace) {
                foreach ($this->classesIn($phpNamespace) as $name => $className) {
                    $all[$prefix . ':' . $name] = $className;
                }
            }
        }
        ksort($all);

        return $all;
    }

    /**
     * @param class-string<ViewHelperInterface> $className
     * @return array<string, ArgumentDefinition>
     */
    public function argumentsOf(string $className): array
    {
        if (!isset($this->argumentsByClass[$className])) {
            try {
                $viewHelper = $this->resolver->createViewHelperInstanceFromClassName($className);
                $this->argumentsByClass[$className] = $this->resolver->getArgumentDefinitionsForViewHelper($viewHelper);
            } catch (Throwable) {
                // ViewHelpers whose dependencies can't be built still get completed by name.
                $this->argumentsByClass[$className] = [];
            }
        }

        return $this->argumentsByClass[$className];
    }

    /**
     * @param class-string $className
     */
    public static function descriptionOf(string $className): string
    {
        $lines = [];
        foreach (explode("\n", (string)(new ReflectionClass($className))->getDocComment()) as $line) {
            $line = (string)preg_replace('/^\s*(?:\/\*\*|\*\/|\*)\s?/', '', rtrim($line));
            if (!str_starts_with($line, '@')) {
                $lines[] = $line;
            }
        }

        return trim(implode("\n", $lines));
    }

    /**
     * @return array<string, class-string<ViewHelperInterface>>
     */
    private function classesIn(string $phpNamespace): array
    {
        if (isset($this->classesByPhpNamespace[$phpNamespace])) {
            return $this->classesByPhpNamespace[$phpNamespace];
        }

        $classes = [];
        foreach ($this->classLoader->getPrefixesPsr4() as $prefix => $directories) {
            $prefix = rtrim($prefix, '\\');
            if (!str_starts_with($phpNamespace . '\\', $prefix . '\\')) {
                continue;
            }
            $relativePath = str_replace('\\', '/', substr($phpNamespace, strlen($prefix)));
            foreach ($directories as $directory) {
                $classes += $this->classesInDirectory($directory . $relativePath, $phpNamespace);
            }
        }

        return $this->classesByPhpNamespace[$phpNamespace] = $classes;
    }

    /**
     * @return array<string, class-string<ViewHelperInterface>>
     */
    private function classesInDirectory(string $directory, string $phpNamespace): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $classes = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            if (!str_ends_with($file->getFilename(), self::SUFFIX . '.php')) {
                continue;
            }
            $relativeClass = str_replace('/', '\\', substr($file->getPathname(), strlen($directory) + 1, -4));
            $className = $phpNamespace . '\\' . $relativeClass;
            try {
                if (!is_subclass_of($className, ViewHelperInterface::class) || (new ReflectionClass($className))->isAbstract()) {
                    continue;
                }
            } catch (Throwable) {
                // Classes extending an optional, uninstalled package fail to autoload.
                continue;
            }
            $name = implode('.', array_map('lcfirst', explode('\\', substr($relativeClass, 0, -strlen(self::SUFFIX)))));
            $classes[$name] = $className;
        }

        return $classes;
    }
}
