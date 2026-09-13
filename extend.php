<?php

use Flarum\Api\Resource\UserResource;
use Flarum\Extend;
use LinkRobins\Referral\Api\Controller\CreateCampaignCodeController;
use LinkRobins\Referral\Api\Controller\DeleteCampaignCodeController;
use LinkRobins\Referral\Api\Controller\GenerateMyCodeController;
use LinkRobins\Referral\Api\Controller\ListMyCodesController;
use LinkRobins\Referral\Api\Controller\ListCampaignCodesController;
use LinkRobins\Referral\Api\Controller\PurchaseMyCodeController;
use LinkRobins\Referral\Api\UserResourceFields;
use LinkRobins\Referral\Http\CaptureReferralCookieMiddleware;
use LinkRobins\Referral\Http\StripRefParamMiddleware;
use LinkRobins\Referral\Notification\ReferralRegisteredBlueprint;
use LinkRobins\Referral\RecordReferral;
use LinkRobins\Referral\ReferralServiceProvider;
use LinkRobins\Referral\ValidateInviteCode;

return [
    (new Extend\ServiceProvider())
        ->register(ReferralServiceProvider::class),

    (new Extend\Middleware('forum'))
        ->add(StripRefParamMiddleware::class),

    // Registration is POST /api/users, so the invite-code cookie is captured
    // off the PSR-7 request on the api stack and handed to the scoped state.
    (new Extend\Middleware('api'))
        ->add(CaptureReferralCookieMiddleware::class),

    (new Extend\Frontend('forum'))
        ->js(__DIR__ . '/js/dist/forum.js')
        ->css(__DIR__ . '/less/forum.less'),

    (new Extend\Frontend('admin'))
        ->js(__DIR__ . '/js/dist/admin.js')
        ->css(__DIR__ . '/less/admin.less'),

    new Extend\Locales(__DIR__ . '/locale'),

    (new Extend\ApiResource(UserResource::class))
        ->fields(UserResourceFields::class),

    (new Extend\Event())
        ->subscribe(ValidateInviteCode::class)
        ->subscribe(RecordReferral::class),

    // "Someone joined with your invite code", to the referrer. Alert and
    // email both default on; users can opt out per-channel in their
    // notification preferences.
    (new Extend\Notification())
        ->type(ReferralRegisteredBlueprint::class, ['alert', 'email']),

    (new Extend\View())
        ->namespace('linkrobins-referral', __DIR__ . '/views'),

    // Admin-only management of standalone campaign codes, plus the user's own
    // code-generation endpoint (the write half of the referral-code flow, kept
    // off the GET serialization path).
    (new Extend\Routes('api'))
        ->post('/referral/my-code', 'linkrobins-referral.my-code.generate', GenerateMyCodeController::class)
        ->get('/referral/my-codes', 'linkrobins-referral.my-codes.list', ListMyCodesController::class)
        ->post('/referral/my-codes/generate', 'linkrobins-referral.my-codes.generate', GenerateMyCodeController::class)
        ->post('/referral/my-codes/purchase', 'linkrobins-referral.my-codes.purchase', PurchaseMyCodeController::class)
        ->get('/referral/campaign-codes', 'linkrobins-referral.campaign-codes.list', ListCampaignCodesController::class)
        ->post('/referral/campaign-codes', 'linkrobins-referral.campaign-codes.create', CreateCampaignCodeController::class)
        ->delete('/referral/campaign-codes/{id}', 'linkrobins-referral.campaign-codes.delete', DeleteCampaignCodeController::class),

    (new Extend\Settings())
        ->serializeToForum('referralRequired', 'linkrobins-referral.require_referral', 'boolval')
        ->default('linkrobins-referral.require_referral', '0')
        ->default('linkrobins-referral.eligibility_groups', '')
        ->default('linkrobins-referral.group_rules', '')
        ->default('linkrobins-referral.eligibility_min_posts', '0')
        ->default('linkrobins-referral.eligibility_min_age_days', '0')
        ->default('linkrobins-referral.eligibility_whitelist', '')
        ->default('linkrobins-referral.purchase_enabled', '0')
        ->default('linkrobins-referral.purchase_price', '0')
        ->default('linkrobins-referral.purchase_daily_limit', '0')
        ->default('linkrobins-referral.purchase_expiry_hours', '0')
        ->default('linkrobins-referral.inviter_reward', '0'),
];
