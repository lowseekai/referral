<?php

namespace LinkRobins\Referral\Api\Controller;

use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use LinkRobins\Referral\CodeQuotaService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Generates one group-entitlement invite code for the actor.
 *
 * Kept on the old /referral/my-code route for backward compatibility, but it
 * now follows the multi-code quota rules instead of returning one fixed code.
 */
class GenerateMyCodeController implements RequestHandlerInterface
{
    public function __construct(
        protected CodeQuotaService $quota
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        $code = $this->quota->createGroupCode($actor);

        return new JsonResponse(['data' => $code->toOwnerArray()], 201);
    }
}
