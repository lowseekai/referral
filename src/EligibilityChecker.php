<?php

namespace LinkRobins\Referral;

use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;

/**
 * Decides whether a given user is allowed to have personal invite codes.
 *
 * Admins and whitelisted usernames always qualify. Everyone else must satisfy
 * every configured rule (allowed groups, minimum post count, minimum account
 * age). Any rule left at its empty/zero default is skipped.
 */
class EligibilityChecker
{
    public function __construct(
        protected SettingsRepositoryInterface $settings
    ) {}

    public function isEligible(User $user): bool
    {
        if (! $user->relationLoaded('groups')) {
            $user->load('groups');
        }

        if ($user->isAdmin()) {
            return true;
        }

        $whitelist = $this->whitelist();
        if ($whitelist && in_array(strtolower($user->username), $whitelist, true)) {
            return true;
        }

        $rules = $this->groupRules();
        if ($rules) {
            $userGroupIds = $user->groups->pluck('id')->map(fn ($id) => (int) $id)->all();
            if (! array_filter($rules, fn (array $rule) => $rule['quantity'] > 0
                && in_array($rule['groupId'], $userGroupIds, true))) {
                return false;
            }
        }

        $minPosts = (int) $this->settings->get('linkrobins-referral.eligibility_min_posts', 0);
        if ($minPosts > 0 && (int) $user->comment_count < $minPosts) {
            return false;
        }

        $minAge = (int) $this->settings->get('linkrobins-referral.eligibility_min_age_days', 0);
        if ($minAge > 0) {
            $joined = $user->joined_at;
            // Not eligible until the account is at least $minAge days old.
            if (! $joined || $joined->copy()->addDays($minAge)->isFuture()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Return the configured rule with the highest quantity for this user.
     *
     * Equal quantities use the longer expiry as a deterministic tie-breaker.
     *
     * @return array{groupId: int, quantity: int, expiryHours: int}|null
     */
    public function matchingGroupRule(User $user): ?array
    {
        if (! $user->relationLoaded('groups')) {
            $user->load('groups');
        }

        $userGroupIds = $user->groups->pluck('id')->map(fn ($id) => (int) $id)->all();
        $matches = array_filter(
            $this->groupRules(),
            fn (array $rule) => $rule['quantity'] > 0
                && in_array($rule['groupId'], $userGroupIds, true)
        );

        usort($matches, function (array $left, array $right): int {
            return [$right['quantity'], $right['expiryHours'], $left['groupId']]
                <=> [$left['quantity'], $left['expiryHours'], $right['groupId']];
        });

        return $matches[0] ?? null;
    }

    /**
     * @return list<array{groupId: int, quantity: int, expiryHours: int}>
     */
    public function groupRules(): array
    {
        $raw = $this->settings->get('linkrobins-referral.group_rules', '');
        $decoded = is_array($raw) ? $raw : json_decode((string) $raw, true);

        if (is_array($decoded)) {
            $rules = [];

            foreach ($decoded as $key => $value) {
                // Accept both a list of rules and an object keyed by group ID.
                $groupId = is_array($value) ? ($value['groupId'] ?? $key) : $key;
                $quantity = is_array($value) ? ($value['quantity'] ?? 0) : 1;
                $expiryHours = is_array($value)
                    ? ($value['expiryHours'] ?? (($value['expiryDays'] ?? 0) * 24))
                    : 0;

                $groupId = (int) $groupId;
                $quantity = max(0, (int) $quantity);
                $expiryHours = max(0, (int) $expiryHours);

                if ($groupId > 0) {
                    $rules[$groupId] = [
                        'groupId' => $groupId,
                        'quantity' => $quantity,
                        'expiryHours' => $expiryHours,
                    ];
                }
            }

            return array_values($rules);
        }

        // Keep the old setting readable so existing installations migrate
        // without requiring an admin to re-save their group selection.
        $legacyRaw = $this->settings->get('linkrobins-referral.eligibility_groups', '');
        $legacy = is_array($legacyRaw) ? $legacyRaw : json_decode((string) $legacyRaw, true);

        if (! is_array($legacy)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($groupId) => [
                'groupId' => (int) $groupId,
                'quantity' => 1,
                'expiryHours' => 0,
            ],
            $legacy
        ), fn (array $rule) => $rule['groupId'] > 0));
    }

    /**
     * @return string[] lowercased usernames
     */
    protected function whitelist(): array
    {
        $raw = (string) $this->settings->get('linkrobins-referral.eligibility_whitelist', '');
        if (trim($raw) === '') {
            return [];
        }

        $parts = preg_split('/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return array_map(fn ($p) => strtolower(trim($p)), $parts);
    }
}
