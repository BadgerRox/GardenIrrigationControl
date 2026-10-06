<?php

declare(strict_types=1);

// Include Symcon stubs
require_once __DIR__ . '/stubs/autoload.php';

// Project PSR-4 autoloader for libs
require_once __DIR__ . '/../libs/Autoload.php';
gardenIrrigationControlRegisterAutoload();

// Load your module
require_once __DIR__ . '/../GardenIrrigationControl/module.php';
