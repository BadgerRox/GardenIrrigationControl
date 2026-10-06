<?php

declare(strict_types=1);

if (!function_exists('AC_GetAggregatedValues')) {
    /**
     * IP-Symcon archive API function stub for static analysis.
     *
     * @return array<int, array<string, mixed>>|false
     */
    function AC_GetAggregatedValues(
        int $archiveID,
        int $variableID,
        int $aggregationLevel,
        int $startTime,
        int $endTime,
        int $limit
    ): array|false {
        return [];
    }
}

if (!function_exists('AC_GetLoggedValues')) {
    /**
     * IP-Symcon archive API function stub for static analysis.
     *
     * @return array<int, array<string, mixed>>|false
     */
    function AC_GetLoggedValues(
        int $archiveID,
        int $variableID,
        int $startTime,
        int $endTime,
        int $limit
    ): array|false {
        return [];
    }
}

if (!function_exists('AC_GetLoggingStatus')) {
    /**
     * IP-Symcon archive API function stub for static analysis.
     */
    function AC_GetLoggingStatus(int $archiveID, int $variableID): bool
    {
        return true;
    }
}

if (!function_exists('VISU_PostNotification')) {
    /**
     * IP-Symcon Tile Visualization notification function stub for static analysis.
     */
    function VISU_PostNotification(int $InstanceID, string $Title, string $Text, string $Type, int $TargetID): int
    {
        return 1;
    }
}

if (!function_exists('IPS_GetChildrenIDs')) {
    /**
     * @return array<int, int>
     */
    function IPS_GetChildrenIDs(int $parentID): array
    {
        return [];
    }
}

if (!function_exists('IPS_GetObject')) {
    /**
     * @return array{ObjectType: int, ObjectIdent: string}
     */
    function IPS_GetObject(int $objectID): array
    {
        return [
            'ObjectType'  => 2,
            'ObjectIdent' => '',
        ];
    }
}
