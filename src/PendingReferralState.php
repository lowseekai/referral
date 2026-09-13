<?php

namespace LinkRobins\Referral;

/**
 * Request-scoped carrier for the referral data captured during a single
 * registration request.
 *
 * The cookie-supplied invite code is read off the PSR-7 request by
 * CaptureReferralCookieMiddleware, validated into an invite id by
 * ValidateInviteCode (the Saving listener), and consumed by RecordReferral
 * (the Registered listener). Because all three run in the same request but do
 * not share a request object, they hand the value off through this object.
 *
 * It is bound as `scoped` in the container (see ReferralServiceProvider) so a
 * fresh instance exists per request -- never a process-lived static. That is
 * what makes the flow correct under persistent runtimes (FrankenPHP / Swoole /
 * RoadRunner), where a static property would leak state between concurrent
 * registrations.
 */
class PendingReferralState
{
    private ?string $code = null;
    private ?int $inviteId = null;
    private ?string $reservationToken = null;

    public function setCode(?string $code): void
    {
        $code = $code !== null ? trim($code) : '';
        $this->code = $code !== '' ? $code : null;
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setInviteId(?int $inviteId): void
    {
        $this->inviteId = $inviteId;
    }

    public function getInviteId(): ?int
    {
        return $this->inviteId;
    }

    public function setReservationToken(?string $token): void
    {
        $this->reservationToken = $token !== null && $token !== '' ? $token : null;
    }

    public function getReservationToken(): ?string
    {
        return $this->reservationToken;
    }
}
