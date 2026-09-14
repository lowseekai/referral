<?php

namespace LinkRobins\Referral;

use Flarum\Foundation\ValidationException;
use Flarum\Locale\TranslatorInterface;
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;

class CodeQuotaService
{
    public function __construct(
        protected EligibilityChecker $eligibility,
        protected ConnectionInterface $db,
        protected TranslatorInterface $translator,
    ) {}

    /**
     * @return array{
     *     eligible: bool,
     *     maxQuantity: int,
     *     expiryHours: int,
     *     sourceGroupId: int|null,
     *     sourceGroupName: string|null
     * }
     */
    public function entitlement(User $user): array
    {
        if (! $this->eligibility->isEligible($user)) {
            return [
                'eligible' => false,
                'maxQuantity' => 0,
                'expiryHours' => 0,
                'sourceGroupId' => null,
                'sourceGroupName' => null,
            ];
        }

        $rule = $this->eligibility->matchingGroupRule($user);

        if ($rule) {
            $group = $user->groups->first(fn ($group) => (int) $group->id === $rule['groupId']);

            return [
                'eligible' => $rule['quantity'] > 0,
                'maxQuantity' => $rule['quantity'],
                'expiryHours' => $rule['expiryHours'],
                'sourceGroupId' => $rule['groupId'],
                'sourceGroupName' => $group?->name_plural ?: $group?->name_singular,
            ];
        }

        return [
            'eligible' => false,
            'maxQuantity' => 0,
            'expiryHours' => 0,
            'sourceGroupId' => null,
            'sourceGroupName' => null,
        ];
    }

    public function ownedActiveGroupCodeCount(User $user): int
    {
        return InviteCode::query()
            ->where('user_id', $user->id)
            ->whereIn('channel', ['legacy', 'group'])
            ->where('uses', 0)
            ->whereNull('used_at')
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', ReferralTime::now()->utc());
            })
            ->count();
    }

    public function remaining(User $user): int
    {
        $entitlement = $this->entitlement($user);

        return max(0, $entitlement['maxQuantity'] - $this->ownedActiveGroupCodeCount($user));
    }

    public function lockOwner(User $user): User
    {
        return User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
    }

    public function createGroupCode(User $user): InviteCode
    {
        return $this->db->transaction(function () use ($user) {
            $this->lockOwner($user);

            $entitlement = $this->entitlement($user);
            if (! $entitlement['eligible']) {
                throw new PermissionDeniedException();
            }

            if ($this->ownedActiveGroupCodeCount($user) >= $entitlement['maxQuantity']) {
                throw new ValidationException([
                    'inviteCode' => $this->translator->trans('linkrobins-referral.api.group_quota_reached'),
                ]);
            }

            return InviteCode::createPersonalCode(
                $user,
                InviteCode::CHANNEL_GROUP,
                $entitlement['expiryHours'],
                $entitlement['sourceGroupId'],
            );
        });
    }
}
