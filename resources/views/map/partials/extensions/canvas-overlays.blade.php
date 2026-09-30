{{-- Merge-safe extension boundary.
     Own: non-canonical visual overlays rendered above the projected map scene
     (progress, deadline, owner, evidence, dependency, personalization hints).
     Overlay state must not mutate canonical Plan/Task data. --}}

@if ($isIntentHub)
    <div
        class="canovia-map-personalization-hidden-status"
        data-map-personalization-hidden-status
        hidden
        aria-live="polite"
    >
        <span>
            この端末で非表示
            <strong data-map-personalization-hidden-count>0</strong>
        </span>
        <button
            type="button"
            data-map-personalization-reset
            aria-label="この端末のパーソナライズ非表示設定を戻す"
        >
            戻す
        </button>
    </div>
@endif
