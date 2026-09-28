<?php

namespace App\Services;

use App\Enums\MapLevel;
use Illuminate\Http\Request;

final class MapSpatialMemoryService
{
    public function __construct(
        private readonly SpaceStationContextService $spaceStation,
    ) {}

    /**
     * Decorate a level projection with stable cross-level navigation memory.
     *
     * Spatial Dock is navigation chrome, not a domain graph entity. Its existence
     * is fixed by Map level and never controlled by recommendation.
     *
     * @param array<string,mixed> $projection
     * @return array<string,mixed>
     */
    public function decorate(Request $request, MapLevel $level, array $projection): array
    {
        if (! isset($projection['space_station'])) {
            $projection['space_station'] = $this->spaceStation->build($request);
        }

        $projection['hierarchy'] = array_merge(
            $this->defaultHierarchy($level),
            is_array($projection['hierarchy'] ?? null) ? $projection['hierarchy'] : [],
        );

        $projection['spatial_dock'] = $level === MapLevel::Intent
            ? null
            : [
                'id' => 'space-station',
                'label' => 'Space Station',
                'position' => 'bottom-right',
                'surface_template' => 'space-station',
                'open_hash' => '#dock=space-station',
            ];

        if ($level !== MapLevel::Intent) {
            $projection['projection_key'] = hash('sha256', implode('|', [
                (string) ($projection['projection_key'] ?? ''),
                (string) data_get($projection, 'space_station.state_key', ''),
                $level->value,
            ]));
        }

        return $projection;
    }

    /**
     * @return array<string,mixed>
     */
    private function defaultHierarchy(MapLevel $level): array
    {
        if ($level === MapLevel::Intent) {
            return [
                'depth' => 0,
                'current_label' => 'Canovia',
                'parent_url' => null,
                'breadcrumbs' => [
                    ['label' => 'Canovia', 'url' => null],
                ],
            ];
        }

        return [
            'depth' => match ($level) {
                MapLevel::Domain => 1,
                MapLevel::Plan => 2,
                MapLevel::Execution => 3,
                default => 0,
            },
            'breadcrumbs' => [],
        ];
    }
}
