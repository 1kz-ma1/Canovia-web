@php
    $workspaceModeRegistry ??= app(\App\Services\WorkspaceModeRegistry::class);
    $workspaceModeContext ??= app(\App\Services\WorkspaceModeResolver::class)->resolve(request());
    $workspaceModeDefinition ??= $workspaceModeRegistry->definition($workspaceModeContext->mode);
    $workspaceModeOptions ??= $workspaceModeRegistry->all();
@endphp

<div
    class="workspace-mode-bar"
    data-workspace-mode-bar
    data-current-workspace-mode="{{ $workspaceModeDefinition->mode->value }}"
    data-workspace-mode-source="{{ $workspaceModeContext->source->value }}"
>
    <div class="workspace-mode-bar-inner">
        <span class="workspace-mode-kicker">WORKSPACE</span>

        <details class="workspace-mode-switcher">
            <summary class="workspace-mode-trigger" data-workspace-mode-trigger>
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
                                @default
                                    <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M12 3v3M12 18v3M3 12h3M18 12h3"/><path d="m5.6 5.6 2.1 2.1m8.6 8.6 2.1 2.1m0-12.8-2.1 2.1m-8.6 8.6-2.1 2.1"/></svg>
                            @endswitch
                        </span>
                    @endforeach
                </span>

                <span class="workspace-mode-current-copy">
                    <strong data-workspace-mode-label>{{ $workspaceModeDefinition->label }}</strong>
                    <small data-workspace-mode-context-label>{{ $workspaceModeContext->planId ? 'Plan Context' : 'Canovia Context' }}</small>
                </span>

                <svg class="workspace-mode-chevron" viewBox="0 0 20 20" aria-hidden="true">
                    <path d="m6 8 4 4 4-4"/>
                </svg>
            </summary>

            <div class="workspace-mode-menu" role="menu" aria-label="Workspaceを切り替える">
                <div class="workspace-mode-menu-heading">
                    <strong>Workspace</strong>
                    <span>目的に合わせてCanoviaの見方を切り替えます。</span>
                </div>

                @foreach ($workspaceModeOptions as $modeOption)
                    @php
                        $isCurrentMode = $modeOption->mode === $workspaceModeDefinition->mode;
                    @endphp
                    <a
                        href="{{ route('workspace_modes.enter', ['workspaceMode' => $modeOption->mode->value]) }}"
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
                                @default
                                    <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M12 3v3M12 18v3M3 12h3M18 12h3"/><path d="m5.6 5.6 2.1 2.1m8.6 8.6 2.1 2.1m0-12.8-2.1 2.1m-8.6 8.6-2.1 2.1"/></svg>
                            @endswitch
                        </span>

                        <span class="min-w-0 flex-1">
                            <strong>{{ $modeOption->label }}</strong>
                            <small>{{ $modeOption->description }}</small>
                        </span>

                        <span
                            class="workspace-mode-current-mark {{ $isCurrentMode ? '' : 'hidden' }}"
                            data-workspace-mode-current-mark="{{ $modeOption->mode->value }}"
                            aria-hidden="true"
                        >✓</span>
                    </a>
                @endforeach

                <p class="workspace-mode-menu-note">
                    Modeの固定・端末間同期は次のV54.2で追加します。
                </p>
            </div>
        </details>
    </div>
</div>
