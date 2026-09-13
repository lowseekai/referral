<?php

namespace LinkRobins\Referral;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int         $id
 * @property int|null    $user_id
 * @property string      $code
 * @property int         $uses
 * @property string|null $label
 * @property \Carbon\CarbonInterface|null $created_at
 * @property \Carbon\CarbonInterface|null $updated_at
 * @property \Carbon\CarbonInterface|null $expires_at
 * @property string       $channel
 * @property int|null     $source_group_id
 * @property int|null     $price_paid
 * @property \Carbon\CarbonInterface|null $purchased_at
 * @property \Carbon\CarbonInterface|null $reserved_at
 * @property string|null  $reservation_token
 * @property \Carbon\CarbonInterface|null $used_at
 * @property int|null     $used_by_user_id
 * @property-read User|null $user
 */
class InviteCode extends AbstractModel
{
    public const CHANNEL_LEGACY = 'legacy';
    public const CHANNEL_GROUP = 'group';
    public const CHANNEL_PURCHASE = 'purchase';
    public const CHANNEL_CAMPAIGN = 'campaign';

    private const RESERVATION_TTL_MINUTES = 15;

    protected $table = 'referral_invite_codes';

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'expires_at' => 'datetime',
        'purchased_at' => 'datetime',
        'reserved_at' => 'datetime',
        'used_at' => 'datetime',
        'source_group_id' => 'integer',
        'price_paid' => 'integer',
        'uses' => 'integer',
        'used_by_user_id' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * A campaign code is admin-created and not bound to any referring user.
     */
    public function isCampaign(): bool
    {
        return $this->user_id === null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null
            && ReferralTime::fromDatabase($this->expires_at)?->isPast();
    }

    public function isUsed(): bool
    {
        return $this->used_at !== null || (int) $this->uses > 0;
    }

    public function isReserved(): bool
    {
        return $this->reserved_at !== null
            && ReferralTime::fromDatabase($this->reserved_at)?->addMinutes(self::RESERVATION_TTL_MINUTES)->isFuture();
    }

    public function isAvailable(): bool
    {
        return ! $this->isUsed() && ! $this->isExpired() && ! $this->isReserved();
    }

    public static function generateCode(): string
    {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code  = '';
        for ($i = 0; $i < 8; $i++) {
            $code .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $code;
    }

    protected static function generateUniqueCode(): string
    {
        // Collisions are astronomically unlikely (32^8 ~ 1.1e12 codes), but
        // cap the retries so a saturated table or a narrowed alphabet surfaces
        // as a clear 500 in the logs rather than a hung worker looping forever.
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $code = static::generateCode();
            if (! static::where('code', $code)->exists()) {
                return $code;
            }
        }

        throw new \RuntimeException('Unable to generate a unique referral code after 10 attempts.');
    }

    public static function getOrCreateForUser(User $user): self
    {
        $existing = static::where('user_id', $user->id)
            ->where('channel', self::CHANNEL_LEGACY)
            ->where('uses', 0)
            ->whereNull('used_at')
            ->first();
        if ($existing) return $existing;

        $invite          = new static();
        $invite->user_id = $user->id;
        $invite->code    = static::generateUniqueCode();
        $invite->uses    = 0;
        $invite->channel = self::CHANNEL_LEGACY;
        $invite->created_at = ReferralTime::now()->utc();
        $invite->updated_at = $invite->created_at;

        $invite->save();

        return $invite;
    }

    public static function createPersonalCode(
        User $user,
        string $channel,
        int $expiryHours = 0,
        ?int $sourceGroupId = null,
        ?int $pricePaid = null,
    ): self {
        $now = ReferralTime::now()->utc();
        $invite = new static();
        $invite->user_id = $user->id;
        $invite->code = static::generateUniqueCode();
        $invite->uses = 0;
        $invite->channel = $channel;
        $invite->source_group_id = $sourceGroupId;
        $invite->price_paid = $pricePaid;
        $invite->created_at = $now;
        $invite->updated_at = $now;

        if ($expiryHours > 0) {
            $invite->expires_at = ReferralTime::toDatabase(
                ReferralTime::now()->addHours($expiryHours)
            );
        }

        $invite->save();

        return $invite;
    }

    public static function createCampaignCode(?string $label, ?\DateTimeInterface $expiresAt): self
    {
        $now = ReferralTime::now()->utc();
        $invite             = new static();
        $invite->user_id    = null;
        $invite->code       = static::generateUniqueCode();
        $invite->uses       = 0;
        $invite->channel    = self::CHANNEL_CAMPAIGN;
        $invite->label      = $label;
        $invite->created_at = $now;
        $invite->updated_at = $now;
        $invite->expires_at = $expiresAt !== null
            ? ReferralTime::toDatabase(Carbon::instance($expiresAt))
            : null;
        $invite->save();

        return $invite;
    }

    /**
     * Atomically reserve a code for one registration request.
     *
     * A reservation is short-lived because Flarum creates the user after the
     * Saving event has validated the request. The follow-up Registered event
     * converts it to a permanent used marker.
     */
    public static function reserveForRegistration(string $code, string $token): ?self
    {
        $now = ReferralTime::now();
        $reservationCutoff = $now->subMinutes(self::RESERVATION_TTL_MINUTES)->utc();

        $query = static::query()
            ->where('code', strtoupper($code))
            ->where('uses', 0)
            ->whereNull('used_at')
            ->where(function ($query) use ($now) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', $now->utc());
            })
            ->where(function ($query) use ($reservationCutoff) {
                $query->whereNull('reserved_at')
                    ->orWhere('reserved_at', '<', $reservationCutoff);
            });

        $updated = $query->update([
            'reserved_at' => $now->utc(),
            'reservation_token' => $token,
            'updated_at' => $now->utc(),
        ]);

        if ($updated !== 1) {
            return null;
        }

        return static::where('reservation_token', $token)->first();
    }

    public static function releaseReservation(?int $inviteId, ?string $token): void
    {
        if (! $inviteId || ! $token) {
            return;
        }

        static::query()
            ->whereKey($inviteId)
            ->where('reservation_token', $token)
            ->where('uses', 0)
            ->whereNull('used_at')
            ->update([
                'reserved_at' => null,
                'reservation_token' => null,
                'updated_at' => ReferralTime::now()->utc(),
            ]);
    }

    public function reservationIsValid(string $token): bool
    {
        $reservedAt = ReferralTime::fromDatabase($this->reserved_at);

        return $this->reservation_token === $token
            && ! $this->isUsed()
            && ! $this->isExpired()
            && $reservedAt !== null
            && $reservedAt->addMinutes(self::RESERVATION_TTL_MINUTES)->isFuture();
    }

    /**
     * Shape used by the admin campaign-code endpoints.
     */
    public function toAdminArray(): array
    {
        return [
            'id'        => (int) $this->id,
            'code'      => $this->code,
            'label'     => $this->label,
            'uses'      => (int) $this->uses,
            'expiresAt' => ReferralTime::fromDatabase($this->expires_at)?->toIso8601String(),
            'expired'   => $this->isExpired(),
            'used'      => $this->isUsed(),
            'channel'   => $this->channel ?: self::CHANNEL_LEGACY,
        ];
    }

    public function toOwnerArray(): array
    {
        return [
            'id' => (int) $this->id,
            'code' => $this->code,
            'channel' => $this->channel ?: self::CHANNEL_LEGACY,
            'sourceGroupId' => $this->source_group_id ? (int) $this->source_group_id : null,
            'uses' => (int) $this->uses,
            'createdAt' => ReferralTime::fromDatabase($this->created_at)?->toIso8601String(),
            'expiresAt' => ReferralTime::fromDatabase($this->expires_at)?->toIso8601String(),
            'usedAt' => ReferralTime::fromDatabase($this->used_at)?->toIso8601String(),
            'expired' => $this->isExpired(),
            'used' => $this->isUsed(),
            'available' => $this->isAvailable(),
        ];
    }
}
