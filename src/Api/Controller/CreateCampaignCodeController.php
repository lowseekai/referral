<?php

namespace LinkRobins\Referral\Api\Controller;

use Carbon\Carbon;
use Flarum\Foundation\ValidationException;
use Flarum\Http\RequestUtil;
use Flarum\Locale\TranslatorInterface;
use Laminas\Diactoros\Response\JsonResponse;
use LinkRobins\Referral\InviteCode;
use LinkRobins\Referral\ReferralTime;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class CreateCampaignCodeController implements RequestHandlerInterface
{
    public function __construct(
        protected TranslatorInterface $translator
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        $body = (array) $request->getParsedBody();
        $attributes = $body['data']['attributes'] ?? $body;

        $label = isset($attributes['label']) ? trim((string) $attributes['label']) : '';
        // The label column is VARCHAR(255); reject an over-long label with a
        // clean 422 instead of letting it hit the database and surface as a
        // truncation/constraint 500.
        if (mb_strlen($label) > 255) {
            throw new ValidationException([
                'label' => $this->translator->trans('linkrobins-referral.api.label_too_long'),
            ]);
        }
        $label = $label !== '' ? $label : null;

        $expiresAt = null;
        if (! empty($attributes['expiresAt'])) {
            try {
                $expiresAt = Carbon::parse($attributes['expiresAt'], ReferralTime::TIMEZONE);
            } catch (\Throwable $e) {
                throw new ValidationException([
                    'expiresAt' => $this->translator->trans('linkrobins-referral.api.invalid_expiry'),
                ]);
            }
        }

        $code = InviteCode::createCampaignCode($label, $expiresAt);

        return new JsonResponse(['data' => $code->toAdminArray()], 201);
    }
}
