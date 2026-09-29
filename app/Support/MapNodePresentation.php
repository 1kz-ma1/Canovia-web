<?php

namespace App\Support;

final class MapNodePresentation
{
    /**
     * Translate internal semantic node metadata into compact user-facing copy.
     *
     * Internal label / eyebrow / subtitle remain untouched on the node and are
     * still available to the detail palette and telemetry. This class only
     * decides what should be printed directly on the spatial canvas.
     *
     * @param array<string,mixed> $node
     * @return array{
     *   kind:'container'|'leaf',
     *   label:string,
     *   eyebrow:?string,
     *   subtitle:?string
     * }
     */
    public static function for(array $node): array
    {
        $type = (string) ($node['type'] ?? '');
        $state = (string) ($node['state'] ?? '');
        $positionRole = (string) ($node['position_role'] ?? '');
        $eyebrow = strtoupper(trim((string) ($node['eyebrow'] ?? '')));
        $label = trim((string) ($node['label'] ?? ''));

        if (! self::isLeafType($type)) {
            return [
                'kind' => 'container',
                'label' => $label !== '' ? $label : self::fallbackLabel($type),
                'eyebrow' => null,
                'subtitle' => null,
            ];
        }

        return [
            'kind' => 'leaf',
            'label' => self::leafLabel($type, $state, $positionRole, $eyebrow),
            'eyebrow' => null,
            'subtitle' => null,
        ];
    }

    private static function isLeafType(string $type): bool
    {
        return in_array($type, [
            'task',
            'tool',
            'evidence',
            'inbox',
            'collaboration_item',
        ], true);
    }

    private static function leafLabel(
        string $type,
        string $state,
        string $positionRole,
        string $eyebrow,
    ): string {
        if ($type === 'collaboration_item') {
            return match (true) {
                str_contains($eyebrow, 'REVIEW') => 'レビュー待ち',
                str_contains($eyebrow, 'WAITING') => '相手待ち',
                str_contains($eyebrow, 'EXTERNAL') => '外部確認',
                str_contains($eyebrow, 'NEXT OPTION') => '次の選択肢',
                str_contains($eyebrow, 'NEXT ACTION'),
                str_contains($eyebrow, 'MY ASSIGNMENT') => '自分が進める',
                default => '共同',
            };
        }

        if ($type === 'task') {
            return match (true) {
                $state === 'primary',
                $positionRole === 'action-primary',
                str_contains($eyebrow, 'PRIMARY') => 'おすすめ',
                $positionRole === 'future-next',
                str_contains($eyebrow, 'NEXT TASK') => '次にやる',
                str_contains($positionRole, 'dependency'),
                str_contains($eyebrow, 'DEPENDENCY') => '前提',
                str_contains($eyebrow, 'COMPLETED') => '完了Task',
                str_contains($eyebrow, 'REFLECTION') => '振り返り',
                default => 'Task',
            };
        }

        if ($type === 'evidence') {
            return match (true) {
                str_contains($eyebrow, 'NEXT OPTION') => '次の選択肢',
                str_contains($eyebrow, 'REFLECTION') => '振り返り',
                default => 'Evidence',
            };
        }

        return match ($type) {
            'tool' => 'Tool',
            'inbox' => 'Inbox',
            default => self::fallbackLabel($type),
        };
    }

    private static function fallbackLabel(string $type): string
    {
        return match ($type) {
            'space_station' => 'Space Station',
            'plan', 'satellite_plan' => 'Plan',
            'goal' => 'Goal',
            'domain' => 'Domain',
            'intent' => 'Context',
            'collaboration_hub', 'collaboration_context' => '共同',
            default => 'Context',
        };
    }

    private function __construct()
    {
    }
}
