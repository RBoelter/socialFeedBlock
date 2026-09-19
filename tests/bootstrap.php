<?php

declare(strict_types=1);

/**
 * The unit tests run without booting OJS: plain Composer autoloading from the
 * OJS checkout the plugin is mounted in, plus a loader for the plugin's own
 * classes. The plugin is expected at <ojs>/plugins/blocks/socialFeedBlock.
 */
require dirname(__DIR__, 4) . '/lib/pkp/lib/vendor/autoload.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'APP\\plugins\\blocks\\socialFeedBlock\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $file = dirname(__DIR__) . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
