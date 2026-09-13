<?php

namespace LinkRobins\Referral;

use Flarum\Foundation\ValidationException;
use Flarum\Locale\TranslatorInterface;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\Event\Saving;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;

class ValidateInviteCode
{
    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected TranslatorInterface $translator,
        protected Container $container
    ) {}

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(Saving::class, [$this, 'handle']);
    }

    public function handle(Saving $event): void
    {
        $user = $event->user;

        if ($user->exists) return;

        $required = (bool) $this->settings->get('linkrobins-referral.require_referral', false);

        // The code was captured off the PSR-7 request by
        // CaptureReferralCookieMiddleware into the request-scoped state.
        $state = $this->container->make(PendingReferralState::class);
        $code  = trim($state->getCode() ?? '');

        if ($required && !$code) {
            throw new ValidationException([
                'inviteCode' => $this->translator->trans('linkrobins-referral.validation.required'),
            ]);
        }

        if ($code) {
            $token = bin2hex(random_bytes(24));
            $invite = InviteCode::reserveForRegistration($code, $token);

            if (! $invite) {
                throw new ValidationException([
                    'inviteCode' => $this->translator->trans('linkrobins-referral.validation.invalid'),
                ]);
            }

            $state->setInviteId($invite->id);
            $state->setReservationToken($token);
        }
    }
}
