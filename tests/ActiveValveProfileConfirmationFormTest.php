<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class ActiveValveProfileConfirmationFormTest extends TestCase
{
    public function testPopupIsVisibleAfterFormReloadWhenConfirmationIsPending(): void
    {
        $module = $this->createModule(1234, true);

        $this->assertTrue($this->isConfirmationPopupVisible($module->GetConfigurationForm()));
    }

    public function testPopupStaysHiddenOnOrdinaryConfiguratorOpen(): void
    {
        $module = $this->createModule(1234, false);

        $this->assertFalse($this->isConfirmationPopupVisible($module->GetConfigurationForm()));
    }

    public function testPopupUsesSupportedButtonCallbackInsteadOfPopupWizardPages(): void
    {
        $popup = $this->getConfirmationPopup($this->createModule(1234, true)->GetConfigurationForm());

        $this->assertArrayNotHasKey('pages', (array) $popup->popup);
        $this->assertSame(
            'GIC_ConfirmActiveValveProfile($id);',
            $popup->popup->buttons[0]->onClick
        );
    }

    private function createModule(int $activeValveId, bool $confirmationPending): GardenIrrigationControl
    {
        return new class(98765, $activeValveId, $confirmationPending) extends GardenIrrigationControl {
            public function __construct(
                int $instanceId,
                private readonly int $activeValveId,
                private readonly bool $confirmationPending
            ) {
                parent::__construct($instanceId);
            }

            public function GetStatus(): int
            {
                return 102;
            }

            protected function GetExistingFrontendVariableID(string $ident): int
            {
                return $ident === 'ActiveValve' ? $this->activeValveId : 0;
            }

            protected function ReadPropertyString(string $name): string
            {
                return $name === 'ZonesTree' ? '[]' : '';
            }

            protected function ReadAttributeBoolean(string $name): bool
            {
                return $name === 'ActiveValveProfileConfirmationPending' && $this->confirmationPending;
            }
        };
    }

    private function isConfirmationPopupVisible(string $formJson): bool
    {
        $popup = $this->getConfirmationPopup($formJson);

        return (bool) ($popup->visible ?? false);
    }

    private function getConfirmationPopup(string $formJson): stdClass
    {
        $form = json_decode($formJson, false, 512, JSON_THROW_ON_ERROR);
        if (!$form instanceof stdClass || !is_array($form->elements ?? null)) {
            self::fail('Configuration form did not return an elements list.');
        }

        foreach ($form->elements as $element) {
            if ($element instanceof stdClass
                && ($element->name ?? null) === 'ActiveValveProfileConfirmation') {
                return $element;
            }
        }

        self::fail('ActiveValveProfileConfirmation PopupAlert was not found.');
    }
}