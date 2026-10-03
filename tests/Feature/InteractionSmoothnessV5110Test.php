<?php

namespace Tests\Feature;

use Tests\TestCase;

class InteractionSmoothnessV5110Test extends TestCase
{
    public function test_instant_navigation_preserves_scroll_position_per_history_entry(): void
    {
        $instant = file_get_contents(resource_path('js/instant-navigation.mjs'));

        $this->assertStringContainsString('const currentScrollPosition = () => ({', $instant);
        $this->assertStringContainsString('const rememberCurrentScroll = () => {', $instant);
        $this->assertStringContainsString('canoviaScroll: currentScrollPosition()', $instant);
        $this->assertStringContainsString("windowRef.history.scrollRestoration = 'manual';", $instant);
        $this->assertStringContainsString(
            "scroll: event.state?.canoviaScroll || { x: 0, y: 0 }",
            $instant,
        );
        $this->assertStringContainsString(
            "if (historyMode === 'push') {\n            rememberCurrentScroll();",
            $instant,
        );
    }

    public function test_touch_prefetch_waits_for_tap_intent_and_cancels_on_swipe(): void
    {
        $instant = file_get_contents(resource_path('js/instant-navigation.mjs'));

        $this->assertStringContainsString("if (event.pointerType === 'touch') return;", $instant);
        $this->assertStringContainsString('const onTouchStart = (event) => {', $instant);
        $this->assertStringContainsString('}, 90);', $instant);
        $this->assertStringContainsString('Math.hypot(deltaX, deltaY) > 12', $instant);
        $this->assertStringContainsString(
            'clearTouchIntent({ prefetchNow: true });',
            $instant,
        );
        $this->assertStringContainsString(
            "documentRef.addEventListener('touchmove', onTouchMove",
            $instant,
        );
        $this->assertStringContainsString(
            "documentRef.addEventListener('touchcancel', onTouchCancel",
            $instant,
        );
        $this->assertStringNotContainsString(
            "documentRef.addEventListener('touchstart', onIntent",
            $instant,
        );
    }

    public function test_idle_revalidate_is_coalesced_per_url(): void
    {
        $instant = file_get_contents(resource_path('js/instant-navigation.mjs'));

        $this->assertStringContainsString('const revalidateByKey = new Map();', $instant);
        $this->assertStringContainsString('if (revalidateByKey.has(key)) return;', $instant);
        $this->assertStringContainsString('revalidateByKey.set(key, handle);', $instant);
        $this->assertStringContainsString('revalidateByKey.delete(key);', $instant);
        $this->assertStringContainsString('revalidateByKey.clear();', $instant);

        // V51.8's visit-semantics boundary remains intact: cache/prefetch
        // renders still eventually perform a real navigate request.
        $this->assertStringContainsString("fetchPayload(target, 'navigate')", $instant);
    }

    public function test_dispose_cleans_up_all_touch_intent_listeners(): void
    {
        $instant = file_get_contents(resource_path('js/instant-navigation.mjs'));

        foreach ([
            "removeEventListener('touchstart', onTouchStart, true)",
            "removeEventListener('touchmove', onTouchMove, true)",
            "removeEventListener('touchend', onTouchEnd, true)",
            "removeEventListener('touchcancel', onTouchCancel, true)",
        ] as $contract) {
            $this->assertStringContainsString($contract, $instant);
        }

        $this->assertStringContainsString('clearTouchIntent();', $instant);
    }
}
