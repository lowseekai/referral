<?php

namespace LinkRobins\Referral\Api;

use Flarum\Api\Schema\Attribute;
use Flarum\Api\Schema\Relationship\ToMany;
use Flarum\Api\Schema\Relationship\ToOne;
use Flarum\User\User;
use LinkRobins\Referral\EligibilityChecker;
use LinkRobins\Referral\InviteCode;
use LinkRobins\Referral\ReferralRelation;

class UserResourceFields
{
    /**
     * Upper bound on how many referred users a single ?include=referredUsers
     * loads. The relationship is only exposed to the user themselves or an
     * admin, but a prolific referrer could still have thousands of rows; an
     * uncapped ->get() would pull the whole set into memory and emit a huge
     * JSON:API payload. The forum UI only renders the referral *count*, so
     * this cap is purely a guard for direct API consumers.
     */
    protected const REFERRED_USERS_LIMIT = 100;

    public function __construct(
        protected EligibilityChecker $eligibility
    ) {}

    public function __invoke(): array
    {
        return [
            // Read the denormalised counter off the loaded users row rather
            // than issuing a COUNT() per serialized user (this attribute is
            // shown on every UserCard, including post authors on a discussion,
            // so the old query was an N+1). Maintained in RecordReferral.
            Attribute::make('referralCount')
                ->get(fn (User $user) => (int) ($user->referral_count ?? 0)),

            // Whether this user is allowed a personal invite code (admin rules:
            // groups / min posts / account age / whitelist). Only meaningful to
            // the user themselves, so it is gated to self like the code itself.
            Attribute::make('referralEligible')
                ->visible(fn (User $user, $context) => $context->getActor()->id === $user->id)
                ->get(fn (User $user) => $this->eligibility->isEligible($user)),

            // Backward-compatible single-code attribute. The new forum UI uses
            // /api/referral/my-codes, but older clients should never receive a
            // used or expired code from this field.
            Attribute::make('referralCode')
                ->visible(fn (User $user, $context) => $context->getActor()->id === $user->id)
                ->get(fn (User $user) => $this->eligibility->isEligible($user)
                    ? InviteCode::where('user_id', $user->id)
                        ->where('uses', 0)
                        ->whereNull('used_at')
                        ->where(function ($query) {
                            $query->whereNull('expires_at')
                                ->orWhere('expires_at', '>', \LinkRobins\Referral\ReferralTime::now()->utc());
                        })
                        ->orderByDesc('id')
                        ->value('code')
                    : null),

            // The referral graph (who referred whom) is private to the user
            // and admins; not exposed for arbitrary users via ?include=.
            ToOne::make('referredBy')
                ->type('users')
                ->includable()
                ->visible(fn (User $user, $context) => $context->getActor()->id === $user->id || $context->getActor()->isAdmin())
                ->get(function (User $user) {
                    $rel = ReferralRelation::where('user_id', $user->id)->first();
                    return $rel ? $rel->referrer : null;
                }),

            ToMany::make('referredUsers')
                ->type('users')
                ->includable()
                ->visible(fn (User $user, $context) => $context->getActor()->id === $user->id || $context->getActor()->isAdmin())
                ->get(function (User $user) {
                    // Capped and ordered newest-first (see REFERRED_USERS_LIMIT).
                    return ReferralRelation::where('referred_by_user_id', $user->id)
                        ->with('user')
                        ->orderByDesc('id')
                        ->limit(self::REFERRED_USERS_LIMIT)
                        ->get()
                        ->map(fn ($r) => $r->user)
                        ->filter()
                        ->values()
                        ->all();
                }),
        ];
    }
}
