<?php

namespace App\Data;

use App\Enums\WorkspaceMode;

final readonly class WorkspaceModeDefinitionData
{
    /**
     * @param array<int,string> $supportedProfileKeys
     * @param array<int,string> $navigationKeys
     */
    public function __construct(
        public WorkspaceMode $mode,
        public string $label,
        public string $iconKey,
        public string $description,
        public string $accentTone,
        public string $homeStrategy,
        public array $supportedProfileKeys,
        public array $navigationKeys,
        public string $emptyStateTitle,
        public string $emptyStateDescription,
        public string $emptyStateActionKey,
    ) {}

    public function supportsProfile(string $profileKey): bool
    {
        return in_array(
            mb_strtolower(trim($profileKey)),
            $this->supportedProfileKeys,
            true,
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->mode->value,
            'label' => $this->label,
            'icon_key' => $this->iconKey,
            'description' => $this->description,
            'accent_tone' => $this->accentTone,
            'home_strategy' => $this->homeStrategy,
            'supported_profile_keys' => $this->supportedProfileKeys,
            'navigation_keys' => $this->navigationKeys,
            'empty_state' => [
                'title' => $this->emptyStateTitle,
                'description' => $this->emptyStateDescription,
                'action_key' => $this->emptyStateActionKey,
            ],
        ];
    }
}
