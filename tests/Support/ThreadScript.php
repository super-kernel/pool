<?php

declare(strict_types=1);

namespace SuperKernel\Pool\Tests\Support;

use RuntimeException;

use function dirname;
use function file_put_contents;
use function sys_get_temp_dir;
use function tempnam;
use function var_export;

/** Generates an executable boundary outside the declaration-only test namespace. */
final class ThreadScript
{
    /** @throws RuntimeException When the temporary runner cannot be written */
    public static function create(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pool-thread-');
        if (false === $path) {
            throw new RuntimeException('Unable to allocate a thread runner.');
        }
        $autoload = var_export(dirname(__DIR__, 4) . '/vendor/autoload.php', true);
        $tests = var_export(dirname(__DIR__), true);
        $code = "<?php\nrequire $autoload;\n"
            . '$loader = new Composer\\Autoload\\ClassLoader();' . "\n"
            . '$loader->addPsr4("SuperKernel\\\\Pool\\\\Tests\\\\", ' . $tests . ");\n"
            . '$loader->register(true);' . "\n"
            . 'SuperKernel\\Pool\\Tests\\Support\\ThreadWorker::run();' . "\n";
        if (false === file_put_contents($path, $code)) {
            throw new RuntimeException('Unable to write a thread runner.');
        }

        return $path;
    }
}
