<?php

namespace LinkRobins\Referral\Http;

use Illuminate\Contracts\Container\Container;
use LinkRobins\Referral\InviteCode;
use LinkRobins\Referral\PendingReferralState;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Reads the `referral_code` cookie off the PSR-7 request and hands it to the
 * request-scoped PendingReferralState, so the Saving listener never has to
 * touch the `$_COOKIE` superglobal. Going through getCookieParams() keeps the
 * extension inside the PSR-7 abstraction (any cookie-normalising middleware
 * still applies) and works identically across runtimes.
 */
class CaptureReferralCookieMiddleware implements MiddlewareInterface
{
    public function __construct(
        protected Container $container
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $cookies = $request->getCookieParams();
        $code = isset($cookies['referral_code']) ? trim((string) $cookies['referral_code']) : '';

        // Valid codes are 8 characters; reject an implausibly long cookie value
        // (sent unauthenticated on every POST /api/users) before it ever reaches
        // a DB lookup. 16 leaves generous headroom over the real length.
        if (strlen($code) > 16) {
            $code = '';
        }

        // Resolve at call time (not via constructor injection) so we always
        // get the current request's scoped instance, even if this middleware
        // is itself cached by a persistent runtime.
        $this->container->make(PendingReferralState::class)->setCode($code !== '' ? $code : null);

        try {
            return $handler->handle($request);
        } finally {
            // Saving may reserve a code before a later user-field validation
            // fails. Release that request's reservation immediately; a
            // successful Registered event clears the state after redemption.
            $state = $this->container->make(PendingReferralState::class);
            InviteCode::releaseReservation($state->getInviteId(), $state->getReservationToken());
            $state->setInviteId(null);
            $state->setReservationToken(null);
        }
    }
}
