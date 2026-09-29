<div hidden data-map-surface-templates>
    @foreach ($nodes as $node)
        @php
            $surface = $node['classic_surface'] ?? [];
            $surfacePresentation = \App\Support\MapNodePresentation::for($node);
            $surfaceKind = ($surfacePresentation['kind'] ?? null) === 'leaf'
                ? ($surfacePresentation['label'] ?? '詳細')
                : ($surface['kind'] ?? $node['type']);
        @endphp
        @if (! empty($surface))
            <template data-map-surface-template="{{ $node['id'] }}">
                <section class="canovia-map-classic-content" data-map-classic-content="{{ $node['id'] }}">
                    <p class="canovia-map-classic-kind">{{ $surfaceKind }}</p>
                    <h2 class="canovia-map-classic-title">{{ $surface['title'] ?? $node['label'] }}</h2>
                    @if (filled($surface['summary'] ?? null))
                        <p class="canovia-map-classic-summary">{{ $surface['summary'] }}</p>
                    @endif

                    @if (! empty($surface['meta']))
                        <div class="canovia-map-classic-meta">
                            @foreach ($surface['meta'] as $meta)
                                <span>{{ $meta }}</span>
                            @endforeach
                        </div>
                    @endif

                    @if ($isIntentHub && ($node['id'] ?? null) === 'intent:space-station')
                        @include('map.partials.space-station-surface')
                    @else
                        <div class="canovia-map-classic-actions">
                            @foreach (($surface['actions'] ?? []) as $action)
                                @php
                                    $actionNavigationKind = (string) ($action['navigation_kind'] ?? '');
                                    $actionIsZoom = str_starts_with($actionNavigationKind, 'zoom-');
                                    $actionIsExternal = $actionNavigationKind === 'external' || (bool) ($action['external'] ?? false);
                                    $actionZoomDirection = $actionNavigationKind === 'zoom-out' ? 'out' : 'in';
                                @endphp
                                <a
                                    href="{{ $action['url'] }}"
                                    class="{{ ($action['primary'] ?? false) ? 'btn-primary' : 'btn-secondary' }} w-full justify-center"
                                    data-map-classic-action
                                    data-map-action-role="{{ $actionIsZoom ? 'zoom' : ($actionIsExternal ? 'external_tool' : (($action['primary'] ?? false) ? 'primary' : 'secondary')) }}"
                                    @if ($actionIsExternal)
                                        target="_blank"
                                        rel="noopener noreferrer"
                                    @endif
                                    @if ($actionIsZoom)
                                        data-map-semantic-zoom
                                        data-map-zoom-direction="{{ $actionZoomDirection }}"
                                    @endif
                                >{{ $action['label'] }}</a>
                            @endforeach
                        </div>
                        @auth
                            @if ($isExecutionLevel && (bool) data_get(config('features.flags.'.\App\Enums\FeatureKey::CanoviaCompanion->value), 'enabled', false))
                                <div class="canovia-map-companion-entry">
                                    <div>
                                        <p class="canovia-map-companion-kicker">COMPANION</p>
                                        <p class="canovia-map-companion-copy">このNodeと直接つながるContextを引き継いで相談します。</p>
                                    </div>
                                    <form method="POST" action="{{ route('companion.entry') }}" data-mutation-once data-map-companion-form>
                                        @csrf
                                        <input type="hidden" name="entry_type" value="map">
                                        <input type="hidden" name="map_node_id" value="{{ $node['id'] }}">
                                        <input type="hidden" name="source_path" value="{{ $mapReturnUrl }}#focus={{ rawurlencode($node['id']) }}">
                                        <input type="hidden" name="source_route" value="map.index">
                                        <button type="submit" class="btn-secondary w-full justify-center" data-map-classic-action>
                                            ✦ このContextについて相談
                                        </button>
                                    </form>
                                </div>
                            @endif
                        @endauth
                    @endif
                </section>
            </template>
        @endif
    @endforeach

    @if (is_array($graph['spatial_dock'] ?? null))
        <template data-map-global-surface-template="space-station">
            <section class="canovia-map-classic-content" data-map-global-classic-content="space-station">
                <p class="canovia-map-classic-kind">Spatial Dock</p>
                <h2 class="canovia-map-classic-title">Space Station</h2>
                <p class="canovia-map-classic-summary">
                    今見ている{{ $hierarchy['current_label'] ?? 'Context' }}を離れず、Capture・接続候補・Companionを開きます。
                </p>
                @include('map.partials.space-station-surface')
            </section>
        </template>
    @endif
</div>
