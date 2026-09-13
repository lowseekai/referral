<?php

namespace LinkRobins\Referral;

use Flarum\Extension\ExtensionManager;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Contracts\Container\Container;

/**
 * Keeps all point-system coupling behind a small, stable integration surface.
 */
class PointSystemBridge
{
    private const EXTENSION_ID = 'ramon-point-system';
    private const REPOSITORY = 'Ramon\\PointSystem\\Repository\\PointsRepository';
    private const BALANCE_MODEL = 'Ramon\\PointSystem\\Model\\UserPoints';

    public function __construct(
        protected Container $container,
        protected SettingsRepositoryInterface $settings,
        protected ExtensionManager $extensions,
    ) {}

    public function isAvailable(): bool
    {
        return class_exists(self::REPOSITORY)
            && $this->extensions->isEnabled(self::EXTENSION_ID)
            && (bool) $this->settings->get('point-system.enabled', true);
    }

    public function balance(User $user): int
    {
        if (! $this->isAvailable() || ! class_exists(self::BALANCE_MODEL)) {
            return 0;
        }

        $model = self::BALANCE_MODEL;

        return (int) ($model::query()->where('user_id', $user->id)->value('balance') ?? 0);
    }

    public function deduct(User $user, int $amount, string $reason, int $referenceId): void
    {
        if (! $this->isAvailable()) {
            throw new \DomainException('Point system is unavailable');
        }

        $repository = $this->container->make(self::REPOSITORY);
        $repository->deduct($user, $amount, $reason, InviteCode::class, $referenceId);
    }

    public function award(User $user, int $amount, string $reason, int $referenceId): void
    {
        if ($amount <= 0 || ! $this->isAvailable()) {
            return;
        }

        $repository = $this->container->make(self::REPOSITORY);
        $repository->award($user, $amount, $reason, InviteCode::class, $referenceId);
    }

    public function currencyName(): string
    {
        return (string) $this->settings->get('point-system.currency_name', 'Points');
    }
}
