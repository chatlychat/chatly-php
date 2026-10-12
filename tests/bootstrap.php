<?php

declare(strict_types=1);

/**
 * Autoloading for both runners.
 *
 * If Composer's autoloader is present it is used, so `vendor/bin/phpunit`
 * behaves normally. If it is not — `php tests/run.php` on a fresh clone with
 * no `composer install` — a 20-line PSR-4 loader stands in. The suite is
 * verifiable with nothing but PHP, which is the point: a test you cannot run
 * is a test nobody runs.
 */
$composer = __DIR__ . '/../vendor/autoload.php';
if (is_file($composer)) {
    require $composer;

    return;
}

spl_autoload_register(static function (string $class): void {
    $prefixes = [
        'Chatly\\Tests\\' => __DIR__ . '/',
        'Chatly\\' => __DIR__ . '/../src/',
    ];
    foreach ($prefixes as $prefix => $base) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }
        $relative = substr($class, \strlen($prefix));
        $file = $base . str_replace('\\', '/', $relative) . '.php';
        if (is_file($file)) {
            require $file;

            return;
        }
    }
});
