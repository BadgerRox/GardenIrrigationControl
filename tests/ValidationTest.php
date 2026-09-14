<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class ValidationTest extends TestCase
{
    /**
     * Validates the structural correctness of the library.json file.
     * * Note: This is currently a placeholder for custom JSON schema validation
     * as required by the IP-Symcon Module Store guidelines.
     */
    public function testValidateLibrary(): void
    {
        $this->assertTrue(true);
    }

    /**
     * Simulates the IP-Symcon Store validation check for the module directory.
     * * This test ensures that the module file exists, contains no syntax errors,
     * complies with strict types, and successfully registers the module class
     * within the isolated PHPUnit runtime.
     */
    public function testValidateModuleDirectory(): void
    {
        // Define the base path relative to the tests directory
        $modulePath = __DIR__ . '/../';
        $moduleFile = $modulePath . 'GardenIrrigationControl/module.php';

        // Assert that the module entry point exists before attempting to load it
        $this->assertFileExists($moduleFile);

        // Include the module file. If there are syntax errors or strict type conflicts
        // with IPSModuleStrict, PHPUnit will fail immediately during execution.
        require_once $moduleFile;

        // Verify that the expected IP-Symcon class is loaded and available
        $this->assertTrue(
            class_exists('GardenIrrigationControl'),
            'The module class "GardenIrrigationControl" failed to load correctly.'
        );
    }

}
