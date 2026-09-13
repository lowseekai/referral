<?php

namespace LinkRobins\Referral;

use Flarum\Foundation\ValidationException;
use Flarum\Locale\TranslatorInterface;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;

class PurchaseService
{
    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected ConnectionInterface $db,
        protected PointSystemBridge $points,
        protected TranslatorInterface $translator,
    ) {}

    /**
     * @return array{
     *     enabled: bool,
     *     price: int,
     *     dailyLimit: int,
     *     purchasedToday: int,
     *     remainingToday: int|null,
     *     expiryHours: int,
     *     pointsAvailable: bool,
     *     balance: int,
     *     currencyName: string
     * }
     */
    public function summary(User $user): array
    {
        $enabled = (bool) $this->settings->get('linkrobins-referral.purchase_enabled', false);
        $dailyLimit = max(0, (int) $this->settings->get('linkrobins-referral.purchase_daily_limit', 0));
        $purchasedToday = $this->purchasedToday($user);

        return [
            'enabled' => $enabled,
            'price' => max(0, (int) $this->settings->get('linkrobins-referral.purchase_price', 0)),
            'dailyLimit' => $dailyLimit,
            'purchasedToday' => $purchasedToday,
            'remainingToday' => $dailyLimit > 0 ? max(0, $dailyLimit - $purchasedToday) : null,
            'expiryHours' => max(0, (int) $this->settings->get('linkrobins-referral.purchase_expiry_hours', 0)),
            'pointsAvailable' => $this->points->isAvailable(),
            'balance' => $this->points->balance($user),
            'currencyName' => $this->points->currencyName(),
        ];
    }

    public function purchase(User $user): InviteCode
    {
        return $this->db->transaction(function () use ($user) {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            if (! (bool) $this->settings->get('linkrobins-referral.purchase_enabled', false)) {
                throw new ValidationException([
                    'purchase' => $this->translator->trans('linkrobins-referral.api.purchase_disabled'),
                ]);
            }

            if (! $this->points->isAvailable()) {
                throw new ValidationException([
                    'purchase' => $this->translator->trans('linkrobins-referral.api.point_system_unavailable'),
                ]);
            }

            $price = (int) $this->settings->get('linkrobins-referral.purchase_price', 0);
            if ($price <= 0) {
                throw new ValidationException([
                    'purchase' => $this->translator->trans('linkrobins-referral.api.purchase_not_configured'),
                ]);
            }

            $dailyLimit = max(0, (int) $this->settings->get('linkrobins-referral.purchase_daily_limit', 0));
            $purchasedToday = $this->purchasedToday($user);
            if ($dailyLimit > 0 && $purchasedToday >= $dailyLimit) {
                throw new ValidationException([
                    'purchase' => $this->translator->trans('linkrobins-referral.api.daily_limit_reached'),
                ]);
            }

            $expiryHours = max(0, (int) $this->settings->get('linkrobins-referral.purchase_expiry_hours', 0));
            $code = InviteCode::createPersonalCode(
                $user,
                InviteCode::CHANNEL_PURCHASE,
                $expiryHours,
                null,
                $price,
            );
            $code->purchased_at = ReferralTime::now()->utc();
            $code->updated_at = ReferralTime::now()->utc();
            $code->save();

            try {
                $this->points->deduct($user, $price, 'referral.invite_code.purchase', (int) $code->id);
            } catch (\DomainException $e) {
                throw new ValidationException([
                    'purchase' => $this->translator->trans('linkrobins-referral.api.insufficient_points'),
                ]);
            }

            return $code;
        });
    }

    public function purchasedToday(User $user): int
    {
        return InviteCode::query()
            ->where('user_id', $user->id)
            ->where('channel', InviteCode::CHANNEL_PURCHASE)
            ->whereNotNull('purchased_at')
            ->where('purchased_at', '>=', ReferralTime::dayStartUtc())
            ->where('purchased_at', '<', ReferralTime::nextDayStartUtc())
            ->count();
    }
}
