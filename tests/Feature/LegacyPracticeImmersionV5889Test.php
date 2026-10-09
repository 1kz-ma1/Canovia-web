<?php

namespace Tests\Feature;

use Tests\TestCase;

class LegacyPracticeImmersionV5889Test extends TestCase
{
    public function test_legacy_practice_route_is_in_immersive_shell_and_exit_remains_visible(): void
    {
        $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));
        $this->assertStringContainsString("request()->routeIs('plans.tasks.study_practice.show')", $layout);
        $this->assertStringContainsString('data-learning-immersion-header', $layout);
        $this->assertStringContainsString('data-learning-immersion-exit', $layout);
        $this->assertStringContainsString('data-learning-immersion-home', $layout);
        $this->assertStringContainsString('@if (! $learningImmersion)', $layout);
    }

    public function test_legacy_answer_and_reasoning_textarea_remains_editable_and_larger_on_mobile(): void
    {
        $practice = file_get_contents(resource_path('views/study_practice/show.blade.php'));
        $this->assertStringContainsString('name="{{ $fieldName }}"', $practice);
        $this->assertStringContainsString('min-h-[168px] w-full resize-y sm:min-h-[192px]" rows="6"', $practice);
        $this->assertStringNotContainsString('readonly name="{{ $fieldName }}"', $practice);
        $this->assertStringContainsString('data-study-practice-one-question', $practice);
        $this->assertStringContainsString('data-study-practice-pager hidden', $practice);
        $this->assertStringContainsString('data-study-practice-question>', $practice);
        $this->assertStringContainsString('data-study-practice-previous', $practice);
        $this->assertStringContainsString('data-study-practice-next', $practice);
        $this->assertStringContainsString('data-study-practice-final-submit', $practice);
        $this->assertStringContainsString('data-practice-answer-error', $practice);
        $script = file_get_contents(resource_path('js/study-practice-one-question.mjs'));
        $this->assertStringContainsString("event.preventDefault()", $script);
        $this->assertStringContainsString("firstIncomplete", $script);
        $this->assertStringContainsString("pagers.forEach", $script);
    }
}
