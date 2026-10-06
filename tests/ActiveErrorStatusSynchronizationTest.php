<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class ActiveErrorStatusSynchronizationTest extends TestCase
{
    public function testClearingLastErrorContextRestoresActiveInstanceStatus(): void
    {
        $module = $this->createModule(['UpdateEToHistory' => 421], 421);

        $module->clearContext('UpdateEToHistory');

        $this->assertSame([], $module->activeErrors());
        $this->assertSame(102, $module->status());
    }

    public function testClearingErrorContextKeepsStatusWhenAnotherErrorRemains(): void
    {
        $module = $this->createModule([
            'UpdateEToHistory' => 421,
            'GetRainForecast'  => 470,
        ], 421);

        $module->clearContext('UpdateEToHistory');

        $this->assertSame(['GetRainForecast' => 470], $module->activeErrors());
        $this->assertSame(421, $module->status());
    }

    public function testClearingLastErrorContextDoesNotActivateUnconfiguredInstance(): void
    {
        $module = $this->createModule(['UpdateEToHistory' => 421], 104);

        $module->clearContext('UpdateEToHistory');

        $this->assertSame([], $module->activeErrors());
        $this->assertSame(104, $module->status());
    }

    /**
     * @param array<string, int> $activeErrors
     */
    private function createModule(array $activeErrors, int $status): object
    {
        return new class($activeErrors, $status) extends GardenIrrigationControl {
            /** @var array<string, int> */
            private array $errors;

            private int $instanceStatus;

            /**
             * @param array<string, int> $activeErrors
             */
            public function __construct(array $activeErrors, int $status)
            {
                parent::__construct(99999);
                $this->errors = $activeErrors;
                $this->instanceStatus = $status;
            }

            public function clearContext(string $context): void
            {
                $this->ClearContextError($context);
            }

            /**
             * @return array<string, int>
             */
            public function activeErrors(): array
            {
                return $this->errors;
            }

            public function status(): int
            {
                return $this->instanceStatus;
            }

            protected function ReadAttributeString(string $name): string
            {
                $encodedErrors = json_encode($this->errors);
                if ($encodedErrors === false) {
                    throw new RuntimeException('Active errors could not be serialized.');
                }

                return $encodedErrors;
            }

            protected function WriteAttributeString(string $name, string $value): bool
            {
                $decodedErrors = json_decode($value, true);
                if (!is_array($decodedErrors)) {
                    throw new RuntimeException('Active errors could not be deserialized.');
                }

                /** @var array<string, int> $decodedErrors */
                $this->errors = $decodedErrors;
                return true;
            }

            protected function GetStatus(): int
            {
                return $this->instanceStatus;
            }

            protected function SetStatus(int $status): bool
            {
                $this->instanceStatus = $status;
                return true;
            }

            protected function SendDebug(string $message, string $data, int $format): bool
            {
                return true;
            }
        };
    }
}