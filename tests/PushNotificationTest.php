<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use GardenIrrigationControl\Libs\PushNotificationSender;
use PHPUnit\Framework\TestCase;

class PushNotificationTest extends TestCase
{
    protected function setUp(): void
    {
        IPS\Kernel::reset();
        IPS\ModuleLoader::loadSingleModule(__DIR__ . '/stubs/CoreStubs/Util Control', 'util-control');
        IPS\ModuleLoader::loadSingleModule(__DIR__ . '/stubs/CoreStubs/Tile Visualization', 'tile-visualization');
    }

    public function testSendPushNotificationRejectsWrongModuleInstance(): void
    {
        $wrongInstanceID = IPS_CreateInstance('{B69010EA-96D5-46DF-B885-24821B8C8DBD}');
        $targetID = IPS_CreateCategory();

        $sender = new PushNotificationSender();

        $this->assertFalse(
            $sender->send(
                $wrongInstanceID,
                'Valid title',
                'Text',
                'Info',
                $targetID
            )
        );
    }

    public function testSendPushNotificationAcceptsValidTileVisualizationInstance(): void
    {
        $pushInstanceID = IPS_CreateInstance('{B5B875BB-9B76-45FD-4E67-2607E45B3AC4}');
        $targetID = IPS_CreateCategory();

        $sender = new PushNotificationSender();

        $this->assertTrue(
            $sender->send(
                $pushInstanceID,
                'Valid Title',
                'This is a test notification.',
                'Success',
                $targetID
            )
        );
    }
}
