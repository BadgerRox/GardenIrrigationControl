<?php

declare(strict_types=1);

namespace GardenIrrigationControl\Libs;

use Throwable;

/**
 * Validates and dispatches Tile Visualization push notifications.
 *
 * Technical behavior: validates the external notification request and invokes the Symcon API only after all checks pass.
 * Functional behavior: invalid recipients and payloads are rejected while valid notifications are delivered consistently.
 * Domain rationale: clear validation boundaries prevent malformed messages from reaching the visualization layer and make notification faults diagnosable.
 */
final class PushNotificationSender
{
    private const TILE_VISUALIZATION_MODULE_ID = '{B5B875BB-9B76-45FD-4E67-2607E45B3AC4}';
    private const MAX_TITLE_LENGTH = 32;
    private const MAX_TEXT_LENGTH = 256;

    /** @var array<int, string> */
    private const ALLOWED_TYPES = ['Info', 'Success', 'Warning', 'Alarm'];

    /** @var \Closure(string, string, int): void|null */
    private readonly ?\Closure $errorLogger;

    /** @var \Closure(string): void|null */
    private readonly ?\Closure $clearErrorContext;

    /**
     * @param callable(string, string, int): void|null $errorLogger
     * @param callable(string): void|null $clearErrorContext
     */
    public function __construct(
        private readonly ?DebugLoggerInterface $logger = null,
        ?callable $errorLogger = null,
        ?callable $clearErrorContext = null
    ) {
        $this->errorLogger = $errorLogger !== null ? \Closure::fromCallable($errorLogger) : null;
        $this->clearErrorContext = $clearErrorContext !== null ? \Closure::fromCallable($clearErrorContext) : null;
    }

    /**
     * Sends a notification through the configured Tile Visualization instance.
     *
     * Technical behavior: checks the instance, payload limits, type, and target before calling VISU_PostNotification.
     * Functional behavior: returns true only when Symcon accepts the notification and false for validation or delivery failures.
     * Domain rationale: push notifications are user-facing operational feedback, so an invalid destination or message must never be reported as sent.
     *
     * @return bool True on success, false when validation or delivery failed.
     */
    public function send(int $instanceID, string $title, string $text, string $type, int $targetID): bool
    {
        $this->debug(
            'SendPushNotification',
            sprintf(
                'Checking push notification for instance %d, target %d, type %s, title: "%s", text: "%s".',
                $instanceID,
                $targetID,
                $type,
                $title,
                $text
            )
        );

        // Technical behavior: reject an instance ID that is not present in the Symcon kernel.
        // Functional behavior: no metadata lookup or notification call is attempted for a missing destination.
        // Domain rationale: a missing visualization cannot receive an operational alert; continuing would hide a configuration fault.
        if (!IPS_InstanceExists($instanceID)) {
            $this->logError('SendPushNotification', sprintf('Aborted: push instance %d does not exist.', $instanceID), 430);
            return false;
        }

        $instance = IPS_GetInstance($instanceID);
        $moduleID = $instance['ModuleInfo']['ModuleID'] ?? '';
        // Technical behavior: compare the resolved module GUID with the Tile Visualization GUID.
        // Functional behavior: only the API-compatible visualization instance may be used for delivery.
        // Domain rationale: sending through another module could invoke an incompatible interface and silently lose irrigation alerts.
        if ($moduleID !== self::TILE_VISUALIZATION_MODULE_ID) {
            $this->logError(
                'SendPushNotification',
                sprintf(
                    'Aborted: instance %d is not the Tile Visualization module (%s). Module: %s',
                    $instanceID,
                    self::TILE_VISUALIZATION_MODULE_ID,
                    $moduleID !== '' ? $moduleID : 'not set'
                ),
                480
            );
            return false;
        }

        $titleLength = mb_strlen($title, 'UTF-8');
        // Technical behavior: enforce the documented UTF-8 title length limit.
        // Functional behavior: reject titles that the Tile Visualization notification contract cannot display reliably.
        // Domain rationale: a bounded title keeps notification lists readable and prevents platform-side truncation.
        if ($titleLength > self::MAX_TITLE_LENGTH) {
            $this->logError(
                'SendPushNotification',
                sprintf('Aborted: title may contain at most %d characters. Current length: %d.', self::MAX_TITLE_LENGTH, $titleLength),
                481
            );
            return false;
        }

        $textLength = mb_strlen($text, 'UTF-8');
        // Technical behavior: enforce the documented UTF-8 message length limit.
        // Functional behavior: reject notification bodies that exceed the supported payload contract.
        // Domain rationale: irrigation messages must remain completely interpretable; implicit truncation could omit the relevant fault or action.
        if ($textLength > self::MAX_TEXT_LENGTH) {
            $this->logError(
                'SendPushNotification',
                sprintf('Aborted: text may contain at most %d characters. Current length: %d.', self::MAX_TEXT_LENGTH, $textLength),
                482
            );
            return false;
        }

        // Technical behavior: perform a strict allow-list comparison for the notification type.
        // Functional behavior: only the four types supported by Tile Visualization reach the API call.
        // Domain rationale: notification severity communicates operational urgency, so unknown values must not be mapped ambiguously by the platform.
        if (!in_array($type, self::ALLOWED_TYPES, true)) {
            $this->logError(
                'SendPushNotification',
                sprintf('Aborted: type is invalid. Allowed: %s. Received: %s.', implode(', ', self::ALLOWED_TYPES), $type),
                483
            );
            return false;
        }

        // Technical behavior: verify that the target object exists before delivery.
        // Functional behavior: prevent notifications from being posted to stale or invalid object IDs.
        // Domain rationale: a notification aimed at a removed object cannot guide the operator and indicates outdated module configuration.
        if (!IPS_ObjectExists($targetID)) {
            $this->logError('SendPushNotification', sprintf('Aborted: target object %d does not exist.', $targetID), 484);
            return false;
        }

        // Technical behavior: execute the external Symcon call inside a Throwable boundary and clear the error context after success.
        // Functional behavior: delivery failures become a logged false result, while successful delivery clears the prior notification error.
        // Domain rationale: notification transport problems must be visible without interrupting the irrigation controller's caller.
        try {
            $notificationID = VISU_PostNotification($instanceID, $title, $text, $type, $targetID);
            $this->clearContextError('SendPushNotification');
            $this->debug('SendPushNotification', sprintf('Push notification accepted by Tile Visualization. Notification ID: %d.', $notificationID));
            return true;
        } catch (Throwable $exception) {
            $this->logError('SendPushNotification', 'Push notification could not be sent. ' . $exception->getMessage(), 485);
            return false;
        }
    }

    private function debug(string $context, string $message): void
    {
        $this->logger?->debug($context, $message);
    }

    private function logError(string $context, string $message, int $code): void
    {
        if ($this->errorLogger !== null) {
            ($this->errorLogger)($context, $message, $code);
        }
    }

    private function clearContextError(string $context): void
    {
        if ($this->clearErrorContext !== null) {
            ($this->clearErrorContext)($context);
        }
    }
}
