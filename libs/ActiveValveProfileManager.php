<?php

declare(strict_types=1);

namespace GardenIrrigationControl\Libs;

use Throwable;

/**
 * Owns the dynamic maintenance-selector profile without deciding module UI state.
 */
final class ActiveValveProfileManager
{
    public function __construct(private readonly ?DebugLoggerInterface $logger = null)
    {
    }

    public function getProfileName(int $instanceId): string
    {
        // Technically: derive a stable, instance-scoped profile name from the owning module ID.
        // Functional behavior: each controller validates and updates only its own maintenance selector profile.
        // Domain rationale: separate irrigation controllers may have different zones; sharing a profile could expose or open the wrong valve.
        return 'GIC.Ventiles.' . $instanceId;
    }

    /**
     * @param array<int, array{Name: string, ID: int}> $frontendValves
     * @return array<int, array{Value: float, Name: string, Icon: string, Color: int}>
     */
    public function buildExpectedAssociations(array $frontendValves, string $allClosedLabel): array
    {
        // Technically: prepend the zero association and append valves in deterministic sequence order.
        // Functional behavior: the selector always contains an explicit safe "all closed" state and stable valve mappings.
        // Domain rationale: deterministic mappings prevent a reordered zone list from presenting an ambiguous manual valve choice.
        $associations = [[
            'Value' => 0.0,
            'Name'  => $allClosedLabel,
            'Icon'  => '',
            'Color' => -1,
        ]];

        ksort($frontendValves);
        foreach ($frontendValves as ['Name' => $valveName, 'ID' => $valveId]) {
            $associations[] = [
                'Value' => (float) $valveId,
                'Name'  => $valveName,
                'Icon'  => '',
                'Color' => -1,
            ];
        }

        return $associations;
    }

    /**
     * @param array<int, array{Name: string, ID: int}> $frontendValves
     */
    public function isCurrentForInstance(
        int $variableId,
        int $instanceId,
        array $frontendValves,
        string $allClosedLabel
    ): bool {
        return $this->isCurrent(
            $variableId,
            $this->getProfileName($instanceId),
            $this->buildExpectedAssociations($frontendValves, $allClosedLabel)
        );
    }

    /**
     * @param array<int, array{Value: float, Name: string, Icon: string, Color: int}> $expectedAssociations
     */
    public function isCurrent(
        int $variableId,
        string $expectedProfileName,
        array $expectedAssociations
    ): bool {
        if ($variableId <= 0) {
            return false;
        }

        try {
            // Technically: compare the variable's assigned custom profile and every association with the expected payload.
            // Functional behavior: manual profile edits, removed profiles, wrong profile types, and stale valve names are rejected.
            // Domain rationale: a stale association can map a visible selection to a different physical valve after zone maintenance.
            $variable = IPS_GetVariable($variableId);
            $profileName = (string) ($variable['VariableCustomProfile'] ?? '');
            if ($profileName !== $expectedProfileName || !IPS_VariableProfileExists($profileName)) {
                return false;
            }

            $profile = IPS_GetVariableProfile($profileName);
            if ((int) ($profile['ProfileType'] ?? -1) !== 1) {
                return false;
            }

            $actualAssociations = [];
            foreach (($profile['Associations'] ?? []) as $association) {
                if (!is_array($association) || !isset($association['Value'], $association['Name'])) {
                    return false;
                }

                $actualAssociations[] = [
                    'Value' => (float) $association['Value'],
                    'Name'  => (string) $association['Name'],
                    'Icon'  => (string) ($association['Icon'] ?? ''),
                    'Color' => (int) ($association['Color'] ?? -1),
                ];
            }

            return $actualAssociations === $expectedAssociations;
        } catch (Throwable $exception) {
            // Technically: convert profile API or malformed-payload failures into a negative validation result.
            // Functional behavior: an unreadable profile is treated as unusable and cannot authorize a manual valve action.
            // Domain rationale: uncertainty about the selector must fail closed to avoid opening an unintended valve.
            $this->logger?->debug('ActiveValveProfile', 'Profile validation failed: ' . $exception->getMessage());
            return false;
        }
    }

    /**
     * Recreates and assigns the profile after the caller has obtained explicit confirmation.
     *
     * @param array<int, array{Value: float, Name: string, Icon: string, Color: int}> $associations
     */
    public function rebuild(string $profileName, int $variableId, array $associations): void
    {
        // Technically: replace the profile and assign it only after the caller has received explicit user confirmation.
        // Functional behavior: the selector is rebuilt from the current validated zone topology and then becomes usable again.
        // Domain rationale: changing a custom profile is user-owned state; requiring confirmation prevents silent UI and valve remapping.
        if (IPS_VariableProfileExists($profileName)) {
            IPS_DeleteVariableProfile($profileName);
        }

        IPS_CreateVariableProfile($profileName, 1);
        IPS_SetVariableProfileIcon($profileName, 'Valve');

        foreach ($associations as $association) {
            // Technically: write one value-to-label association with the canonical icon and color payload.
            // Functional behavior: every displayed valve entry resolves to the exact valve ID selected by maintenance mode.
            // Domain rationale: a wrong value association could operate a different irrigation circuit than the operator intended.
            IPS_SetVariableProfileAssociation(
                $profileName,
                $association['Value'],
                $association['Name'],
                $association['Icon'],
                $association['Color']
            );
        }

        if ($variableId > 0) {
            IPS_SetVariableCustomProfile($variableId, $profileName);
        }
    }

    /**
     * @param array<int, array{Name: string, ID: int}> $frontendValves
     */
    public function rebuildForInstance(
        int $instanceId,
        int $variableId,
        array $frontendValves,
        string $allClosedLabel
    ): void {
        $this->rebuild(
            $this->getProfileName($instanceId),
            $variableId,
            $this->buildExpectedAssociations($frontendValves, $allClosedLabel)
        );
    }

    public function clearCustomProfile(int $variableId): void
    {
        if ($variableId > 0) {
            // Technically: remove the custom profile assignment without assigning a replacement profile.
            // Functional behavior: the variable falls back to a neutral integer representation and cannot show stale valve labels.
            // Domain rationale: an outdated selector must not remain visually actionable while awaiting explicit profile confirmation.
            IPS_SetVariableCustomProfile($variableId, '');
        }
    }
}
