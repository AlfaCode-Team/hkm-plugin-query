<?php

declare(strict_types=1);

/**
 * The plugin is developed against the kernel checked out beside it, so the test
 * suite autoloads both: the kernel's vendor tree for PHPUnit and the kernel
 * classes, plus this plugin's own PSR-4 root.
 */
// …/HKMCODE/PLUGINS/PHP/hkm-plugin-query/tests → …/HKMCODE/PHP
$kernel = dirname(__DIR__, 4) . '/PHP/vendor/autoload.php';

if (!is_file($kernel)) {
    fwrite(STDERR, "Kernel autoloader not found at {$kernel}\n");
    exit(1);
}

require $kernel;

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'Plugins\\Query\\')) {
        return;
    }

    $relative = str_replace('\\', '/', substr($class, strlen('Plugins\\Query\\')));
    $path     = str_starts_with($relative, 'Tests/')
        ? dirname(__DIR__) . '/tests/' . substr($relative, strlen('Tests/')) . '.php'
        : dirname(__DIR__) . '/' . $relative . '.php';

    if (is_file($path)) {
        require $path;
    }
});
