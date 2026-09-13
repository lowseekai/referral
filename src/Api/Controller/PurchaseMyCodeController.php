<?php

namespace LinkRobins\Referral\Api\Controller;

use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use LinkRobins\Referral\PurchaseService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class PurchaseMyCodeController implements RequestHandlerInterface
{
    public function __construct(
        protected PurchaseService $purchase,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        $code = $this->purchase->purchase($actor);

        return new JsonResponse([
            'data' => $code->toOwnerArray(),
            'meta' => ['purchase' => $this->purchase->summary($actor)],
        ], 201);
    }
}
