<?php

namespace LinkRobins\Referral\Api\Controller;

use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use LinkRobins\Referral\CodeQuotaService;
use LinkRobins\Referral\InviteCode;
use LinkRobins\Referral\PurchaseService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class ListMyCodesController implements RequestHandlerInterface
{
    public function __construct(
        protected CodeQuotaService $quota,
        protected PurchaseService $purchase,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        $codes = InviteCode::query()
            ->where('user_id', $actor->id)
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        return new JsonResponse([
            'data' => $codes->map(fn (InviteCode $code) => $code->toOwnerArray())->all(),
            'meta' => [
                'referralCount' => (int) ($actor->referral_count ?? 0),
                'entitlement' => $this->quota->entitlement($actor) + [
                    'activeCount' => $this->quota->ownedActiveGroupCodeCount($actor),
                    'remaining' => $this->quota->remaining($actor),
                ],
                'purchase' => $this->purchase->summary($actor),
            ],
        ]);
    }
}
