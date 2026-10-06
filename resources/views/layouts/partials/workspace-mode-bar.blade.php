@php
    $workspaceModeRegistry ??= app(\App\Services\WorkspaceModeRegistry::class);
    $workspaceModeContext ??= app(\App\Services\WorkspaceModeResolver::class)->resolve(request());
    $workspaceModeDefinition ??= $workspaceModeRegistry->definition($workspaceModeContext->mode);
    $workspaceModeOptions ??= $workspaceModeRegistry->all();
    $workspaceModeCompact ??= false;
    $workspaceModeInline ??= false;
    $workspaceModePreference ??= app(\App\Services\WorkspaceModePreference::class)->selected(request());
    $workspaceModeContextLabel = match ($workspaceModeContext->source) {
        \App\Enums\WorkspaceModeSource::ManualPreference => '固定中',
        \App\Enums\WorkspaceModeSource::RouteHint => '画面に追従',
        \App\Enums\WorkspaceModeSource::PlanProfile => 'Planに追従',
        default => '自動',
    };
@endphp

<div
    class="workspace-mode-bar {{ $workspaceModeCompact ? 'is-compact' : '' }} {{ $workspaceModeInline ? 'is-inline' : '' }}"
    @if ($workspaceModeCompact)
        style="display:inline-flex;min-width:0;border:0;background:transparent;backdrop-filter:none;-webkit-backdrop-filter:none;"
    @endif
    data-workspace-mode-bar
    data-workspace-mode-compact="{{ $workspaceModeCompact ? '1' : '0' }}"
    data-workspace-mode-inline="{{ $workspaceModeInline ? '1' : '0' }}"
    data-current-workspace-mode="{{ $workspaceModeDefinition->mode->value }}"
    data-workspace-mode-source="{{ $workspaceModeContext->source->value }}"
    data-workspace-mode-preference="{{ $workspaceModePreference?->value ?? 'auto' }}"
    data-workspace-mode-event-url="{{ route('behavior_events.store') }}"
>
    <div
        class="workspace-mode-bar-inner"
        @if ($workspaceModeCompact)
            style="width:auto;min-height:0;margin:0;padding:0;gap:0;"
        @endif
    >
        @unless ($workspaceModeCompact)
            <span class="workspace-mode-kicker">WORKSPACE</span>
        @endunless

        <details
            class="workspace-mode-switcher"
            @if ($workspaceModeCompact)
                style="flex:0 1 auto;max-width:6rem;"
            @endif
        >
            <summary
                class="workspace-mode-trigger"
                data-workspace-mode-trigger
                @if ($workspaceModeCompact)
                    style="width:auto;min-width:0;min-height:1.35rem;gap:.25rem;border-radius:9999px;padding:.14rem .42rem;box-shadow:none;"
                @endif
            >
                @unless ($workspaceModeCompact)
                    <span class="workspace-mode-current-icons" aria-hidden="true">
                        @foreach ($workspaceModeOptions as $modeOption)
                        <span
                            class="workspace-mode-icon {{ $modeOption->mode === $workspaceModeDefinition->mode ? '' : 'hidden' }}"
                            data-workspace-mode-current-icon="{{ $modeOption->mode->value }}"
                        >
                            @switch($modeOption->iconKey)
                                @case('study')
                                    <svg viewBox="0 0 24 24"><path d="M4 5.5A2.5 2.5 0 0 1 6.5 3H11v16H6.5A2.5 2.5 0 0 0 4 21.5v-16Z"/><path d="M20 5.5A2.5 2.5 0 0 0 17.5 3H13v16h4.5a2.5 2.5 0 0 1 2.5 2.5v-16Z"/></svg>
                                    @break
                                @case('development')
                                    <svg viewBox="0 0 24 24"><path d="m8.5 7-5 5 5 5"/><path d="m15.5 7 5 5-5 5"/><path d="m14 4-4 16"/></svg>
                                    @break
                                @case('career')
                                    <svg viewBox="0 0 24 24"><path d="M8 7V5.5A2.5 2.5 0 0 1 10.5 3h3A2.5 2.5 0 0 1 16 5.5V7"/><path d="M4 7h16a1 1 0 0 1 1 1v10.5A2.5 2.5 0 0 1 18.5 21h-13A2.5 2.5 0 0 1 3 18.5V8a1 1 0 0 1 1-1Z"/><path d="M3 12h18M10 12v2h4v-2"/></svg>
                                    @break
                                @default
                                    <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M12 3v3M12 18v3M3 12h3M18 12h3"/><path d="m5.6 5.6 2.1 2.1m8.6 8.6 2.1 2.1m0-12.8-2.1 2.1m-8.6 8.6-2.1 2.1"/></svg>
                            @endswitch
                        </span>
                        @endforeach
                    </span>
                @endunless

                <span class="workspace-mode-current-copy">
                    <strong
                        data-workspace-mode-label
                        @if ($workspaceModeCompact)
                            style="max-width:4.5rem;font-size:.58rem;"
                        @endif
                    >{{ $workspaceModeDefinition->label }}</strong>
                    @unless ($workspaceModeCompact)
                        <small data-workspace-mode-context-label>{{ $workspaceModeContextLabel }}</small>
                    @endunless
                </span>

                <svg
                    class="workspace-mode-chevron"
                    viewBox="0 0 20 20"
                    aria-hidden="true"
                    @if ($workspaceModeCompact)
                        style="width:.65rem;height:.65rem;"
                    @endif
                >
                    <path d="m6 8 4 4 4-4"/>
                </svg>
            </summary>

            <div
                class="workspace-mode-menu"
                role="menu"
                aria-label="Workspaceを切り替える"
                @if ($workspaceModeCompact)
                    style="position:fixed;top:calc(env(safe-area-inset-top) + 4.1rem);right:max(1rem, env(safe-area-inset-right));left:max(1rem, env(safe-area-inset-left));width:auto;"
                @endif
            >
                <div class="workspace-mode-menu-heading">
                    <strong>Workspace</strong>
                    <span>選んだWorkspaceを固定し、Planなど明確な文脈ではその画面に追従します。</span>
                </div>

                @foreach ($workspaceModeOptions as $modeOption)
                    @php
                        $isCurrentMode = $modeOption->mode === $workspaceModeDefinition->mode;
                        $isPreferredMode = $workspaceModePreference === $modeOption->mode;
                    @endphp
                    <form
                        method="POST"
                        action="{{ route('workspace_modes.select', ['workspaceMode' => $modeOption->mode->value]) }}"
                        class="workspace-mode-option-form"
                    >
                        @csrf
                        <button
                            type="submit"
                            class="workspace-mode-option {{ $isCurrentMode ? 'is-active' : '' }}"
                            data-workspace-mode-option="{{ $modeOption->mode->value }}"
                            role="menuitem"
                            @if ($isCurrentMode) aria-current="true" @endif
                        >
                            <span class="workspace-mode-option-icon" aria-hidden="true">
                                @switch($modeOption->iconKey)
                                    @case('study')
                                        <svg viewBox="0 0 24 24"><path d="M4 5.5A2.5 2.5 0 0 1 6.5 3H11v16H6.5A2.5 2.5 0 0 0 4 21.5v-16Z"/><path d="M20 5.5A2.5 2.5 0 0 0 17.5 3H13v16h4.5a2.5 2.5 0 0 1 2.5 2.5v-16Z"/></svg>
                                        @break
                                    @case('development')
                                        <svg viewBox="0 0 24 24"><path d="m8.5 7-5 5 5 5"/><path d="m15.5 7 5 5-5 5"/><path d="m14 4-4 16"/></svg>
                                        @break
                                    @case('career')
                                        <svg viewBox="0 0 24 24"><path d="M8 7V5.5A2.5 2.5 0 0 1 10.5 3h3A2.5 2.5 0 0 1 16 5.5V7"/><path d="M4 7h16a1 1 0 0 1 1 1v10.5A2.5 2.5 0 0 1 18.5 21h-13A2.5 2.5 0 0 1 3 18.5V8a1 1 0 0 1 1-1Z"/><path d="M3 12h18M10 12v2h4v-2"/></svg>
                                        @break
                                    @default
                                        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M12 3v3M12 18v3M3 12h3M18 12h3"/><path d="m5.6 5.6 2.1 2.1m8.6 8.6 2.1 2.1m0-12.8-2.1 2.1m-8.6 8.6-2.1 2.1"/></svg>
                                @endswitch
                            </span>

                            <span class="min-w-0 flex-1">
                                <strong>{{ $modeOption->label }}</strong>
                                <small>{{ $modeOption->description }}</small>
                            </span>

                            @if ($isPreferredMode)
                                <span class="workspace-mode-preference-mark">固定</span>
                            @elseif ($isCurrentMode)
                                <span
                                    class="workspace-mode-current-mark"
                                    data-workspace-mode-current-mark="{{ $modeOption->mode->value }}"
                                    aria-hidden="true"
                                >✓</span>
                            @else
                                <span
                                    class="workspace-mode-current-mark hidden"
                                    data-workspace-mode-current-mark="{{ $modeOption->mode->value }}"
                                    aria-hidden="true"
                                >✓</span>
                            @endif
                        </button>
                    </form>
                @endforeach

                <div class="workspace-mode-auto-row">
                    @if ($workspaceModePreference)
                        <form method="POST" action="{{ route('workspace_modes.preference.reset') }}" data-workspace-mode-reset-form>
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="workspace-mode-auto-action">
                                <strong>自動判定に戻す</strong>
                                <span>Planや現在の画面に合わせてWorkspaceを決めます。</span>
                            </button>
                        </form>
                    @else
                        <p class="workspace-mode-menu-note">
                            現在は自動判定です。文脈のない画面ではOverviewを使います。
                        </p>
                    @endif
                </div>
            </div>
        </details>
    </div>
</div>
