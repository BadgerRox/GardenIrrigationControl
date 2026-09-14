<?php

declare(strict_types=1);

if (!function_exists('gardenIrrigationControlRegisterAutoload')) {
    /**
     * Registers a PSR-4 autoloader for project library classes.
     *
     * Technical rule: install one project-local class loader for the
     * GardenIrrigationControl\Libs namespace and ignore invalid class names.
     * Functional behavior: library classes can be loaded lazily without manual
     * require statements in the module entry point.
     * Domain rationale: the Symcon module keeps GardenIrrigationControl/module.php lean and moves reusable irrigation logic into libs/.
     */
    function gardenIrrigationControlRegisterAutoload(): void
    {
        $prefix = 'GardenIrrigationControl\\Libs\\';
        $baseDir = __DIR__ . '/';

        spl_autoload_register(static function (string $class) use ($prefix, $baseDir): void
        {
            if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
                return;
            }

            $relativeClass = substr($class, strlen($prefix));
            if ($relativeClass === '' || preg_match('/[^A-Za-z0-9_\\\\]/', $relativeClass)) {
                return;
            }

            $file = $baseDir . str_replace('\\\\', '/', $relativeClass) . '.php';
            if (is_file($file)) {
                require_once $file;
            }
        });
    }
}
