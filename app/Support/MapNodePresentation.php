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
     * @param  array<string,mixed>  $node
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
            'label' => $label !== '' ? $label : self::fallbackLabel($type),
            'eyebrow' => self::leafLabel($type, $state, $positionRole, $eyebrow),
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
                str_contains($eyebrow, 'COMPLETED') => '完了',
                str_contains($eyebrow, 'REFLECTION') => '振り返り',
                default => 'タスク',
            };
        }

        if ($type === 'evidence') {
            return match (true) {
                str_contains($eyebrow, 'NEXT OPTION') => '次の選択肢',
                str_contains($eyebrow, 'REFLECTION') => '振り返り',
                default => '記録',
            };
        }

        return match ($type) {
            'tool' => '実行ツール',
            'inbox' => '受信トレイ',
            default => self::fallbackLabel($type),
        };
    }

    private static function fallbackLabel(string $type): string
    {
        return match ($type) {
            'task' => '名前のないタスク',
            'evidence' => '記録',
            'tool' => '実行ツール',
            'inbox' => '受信トレイ',
            'collaboration_item' => '共同作業',
            'space_station' => 'Space Station',
            'plan', 'satellite_plan' => 'Plan',
            'goal' => 'Goal',
            'domain' => 'Domain',
            'intent' => 'Context',
            'collaboration_hub', 'collaboration_context' => '共同',
            default => 'Context',
        };
    }

    /** Presentation only: explains an existing role, never infers readiness. */
    public static function reason(array $node): ?string
    {
        $role = (string) ($node['position_role'] ?? '');
        $eyebrow = strtoupper((string) ($node['eyebrow'] ?? ''));

        return match (true) {
            str_contains($role, 'dependency'), str_contains($eyebrow, 'DEPENDENCY') => '次の作業につながる前提タスクです。',
            $role === 'action-primary' => 'この計画で、今取り組む候補です。',
            $role === 'future-next' => '現在の作業の次に取り組む候補です。前提条件は状態欄で確認できます。',
            str_contains($eyebrow, 'REVIEW') => 'レビューを待っている共同作業です。',
            str_contains($eyebrow, 'WAITING') => '相手の対応を待っている共同作業です。',
            str_contains($eyebrow, 'EXTERNAL') => '外部サービスで状況を確認する項目です。',
            str_contains($eyebrow, 'COMPLETED') => '完了した作業を振り返るための項目です。',
            ($node['type'] ?? '') === 'evidence' => 'この文脈に関連する記録です。',
            ($node['type'] ?? '') === 'tool' => '作業を進めるために使えるツールです。',
            ($node['type'] ?? '') === 'inbox' => '取り込んだ情報を確認する入口です。',
            default => null,
        };
    }

    private function __construct() {}
}
