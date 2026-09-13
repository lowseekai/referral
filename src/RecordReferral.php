<?php

namespace LinkRobins\Referral;

use Flarum\Notification\NotificationSyncer;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\Event\Registered;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use LinkRobins\Referral\Notification\ReferralRegisteredBlueprint;
use Psr\Log\LoggerInterface;

class RecordReferral
{
    public function __construct(
        protected LoggerInterface $logger,
        protected NotificationSyncer $notifications,
        protected ConnectionInterface $db,
        protected SettingsRepositoryInterface $settings,
        protected PointSystemBridge $points,
        // Retained only to resolve the request-scoped PendingReferralState
        // fresh per request (a constructor-injected singleton would leak state
        // across registrations under persistent runtimes).
        protected Container $container
    ) {}

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(Registered::class, [$this, 'handle']);
    }

    public function handle(Registered $event): void
    {
        $user  = $event->user;
        $state = $this->container->make(PendingReferralState::class);

        $inviteId = $state->getInviteId();
        $reservationToken = $state->getReservationToken();
        $state->setInviteId(null);
        $state->setReservationToken(null);

        if (! $inviteId) return;

        try {
            $result = $this->db->transaction(function () use ($inviteId, $reservationToken, $user) {
                /** @var InviteCode|null $invite */
                $invite = InviteCode::query()->lockForUpdate()->find($inviteId);
                if (! $invite || $invite->isUsed() || $invite->isExpired()) {
                    return ['referrer' => null, 'reward' => false, 'inviteId' => null];
                }

                if ($reservationToken !== null && ! $invite->reservationIsValid($reservationToken)) {
                    return ['referrer' => null, 'reward' => false, 'inviteId' => null];
                }

                $referrer = $invite->user; // null for admin campaign codes

                // Self-referral does not consume the code. Release the
                // short-lived reservation so its owner can use it normally.
                if ($referrer && $referrer->id === $user->id) {
                    $invite->reserved_at = null;
                    $invite->reservation_token = null;
                    $invite->updated_at = ReferralTime::now()->utc();
                    $invite->save();

                    return ['referrer' => null, 'reward' => false, 'inviteId' => null];
                }

                $rel = null;
                if ($referrer && ! ReferralRelation::where('user_id', $user->id)->exists()) {
                    $rel = new ReferralRelation();
                    $rel->user_id = $user->id;
                    $rel->referred_by_user_id = $referrer->id;
                    $rel->save();
                    $referrer->increment('referral_count');
                }

                $invite->uses = 1;
                $invite->used_at = ReferralTime::now()->utc();
                $invite->used_by_user_id = $user->id;
                $invite->reserved_at = null;
                $invite->reservation_token = null;
                $invite->updated_at = ReferralTime::now()->utc();
                $invite->save();

                return [
                    'referrer' => $rel ? $referrer : null,
                    'reward' => $rel !== null,
                    'inviteId' => (int) $invite->id,
                ];
            });

            $referrer = $result['referrer'];

            if ($referrer && $result['reward']) {
                $reward = max(0, (int) $this->settings->get('linkrobins-referral.inviter_reward', 0));

                try {
                    $this->points->award(
                        $referrer,
                        $reward,
                        'referral.inviter.reward',
                        $result['inviteId']
                    );
                } catch (\Throwable $e) {
                    // Registration and code redemption remain successful even
                    // if the optional point system is temporarily unavailable.
                    $this->logger->warning('[linkrobins/referral] failed to award inviter points', [
                        'exception' => $e,
                        'referrer_id' => $referrer->id,
                        'invite_id' => $result['inviteId'],
                    ]);
                }

                $this->notifications->sync(
                    new ReferralRegisteredBlueprint($user),
                    [$referrer]
                );
            }
        } catch (\Throwable $e) {
            InviteCode::releaseReservation($inviteId, $reservationToken);
            $this->logger->warning('[linkrobins/referral] failed to record referral', ['exception' => $e]);
        }
    }
}
