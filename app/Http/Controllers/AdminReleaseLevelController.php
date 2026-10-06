<?php

namespace App\Http\Controllers;

use App\Enums\ReleaseLevel;
use App\Models\User;
use App\Services\AdminAccessService;
use App\Services\ReleaseLevelService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class AdminReleaseLevelController extends Controller
{
    public function __construct(
        private readonly AdminAccessService $access,
        private readonly ReleaseLevelService $levels,
    ) {}

    public function updatePreview(Request $request)
    {
        abort_unless($this->access->authorized($request), 403);

        $validated = $request->validate([
            'level' => [
                'required',
                Rule::in([
                    'public',
                    ...array_map(
                        static fn (ReleaseLevel $level) => (string) $level->value,
                        ReleaseLevel::cases(),
                    ),
                ]),
            ],
        ]);

        $value = (string) $validated['level'];
        $level = $value === 'public'
            ? null
            : ReleaseLevel::from((int) $value);

        $this->levels->setAdminPreview($request, $level);

        return back()->with(
            'status',
            $level
                ? '管理者のRelease Previewを '.$level->label().' に切り替えました。'
                : '管理者のRelease PreviewをPublicへ戻しました。',
        );
    }

    public function updateUser(
        Request $request,
        User $user,
    ) {
        abort_unless($this->access->authorized($request), 403);

        $allowed = ['public'];
        foreach (ReleaseLevel::cases() as $level) {
            if (
                $level->value
                <= (int) config(
                    'release_levels.maximum_user_override',
                    ReleaseLevel::BetaExpansion->value,
                )
            ) {
                $allowed[] = (string) $level->value;
            }
        }

        $validated = $request->validate([
            'level' => ['required', Rule::in($allowed)],
        ]);

        $value = (string) $validated['level'];
        $level = $value === 'public'
            ? null
            : ReleaseLevel::from((int) $value);

        $this->levels->setUserOverride($user, $level);

        return redirect()
            ->route('admin.economy.index', ['user_id' => $user->id])
            ->with(
                'success',
                $level
                    ? 'User Release Levelを '.$level->label().' に変更しました。'
                    : 'User Release LevelをPublic継承へ戻しました。',
            );
    }
}
