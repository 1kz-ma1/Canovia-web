<?php

namespace App\Support;

final class MapNodeVisualGrammar
{
    public static function kind(array $node): string
    {
        $type = (string) ($node['type'] ?? '');
        $state = (string) ($node['state'] ?? '');
        $positionRole = (string) ($node['position_role'] ?? '');

        return match (true) {
            $type === 'space_station' => 'station',
            in_array($type, ['satellite_plan', 'satellite_tool'], true) => 'satellite',
            $type === 'intent' => 'planet',
            in_array($type, ['domain', 'intent_context'], true) => 'planet',
            $type === 'plan' => 'moon',
            $type === 'goal' => 'star',
            $type === 'task' && ($state === 'primary' || $positionRole === 'now') => 'rocket',
            $type === 'task' => 'beacon',
            $type === 'tool' => 'module',
            $type === 'evidence' => 'archive',
            $type === 'inbox' => 'inbox-dock',
            $type === 'collaboration_hub' => 'crew-station',
            in_array($type, ['collaboration_context', 'collaboration_item'], true) => 'crew',
            default => 'context',
        };
    }


    private function __construct()
    {
    }
}
