<?php

/*
 * This file is part of linkrobins/referral.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace LinkRobins\Referral\Tests\integration\api;

use Carbon\CarbonImmutable;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use LinkRobins\Referral\InviteCode;
use PHPUnit\Framework\Attributes\Test;

class PurchaseMyCodeTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    public function setUp(): void
    {
        parent::setUp();

        $this->extension('ramon-point-system');
        $this->extension('linkrobins-referral');

        $this->setting('linkrobins-referral.purchase_enabled', '1');
        $this->setting('linkrobins-referral.purchase_price', '100');
        $this->setting('linkrobins-referral.purchase_daily_limit', '0');
        $this->setting('linkrobins-referral.purchase_expiry_hours', '24');

        $this->prepareDatabase([
            'users' => [
                $this->normalUser(), // id 2
            ],
        ]);
    }

    public function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    private function setBalance(int $balance, int $lifetime = 0): void
    {
        $this->database()->table('point_system_user_points')->insert([
            'user_id' => 2,
            'balance' => $balance,
            'lifetime' => $lifetime,
            'created_at' => CarbonImmutable::now()->utc(),
            'updated_at' => CarbonImmutable::now()->utc(),
        ]);
    }

    #[Test]
    public function a_user_with_enough_points_can_purchase_an_invite_code(): void
    {
        $this->setBalance(150, 150);

        $response = $this->send(
            $this->request('POST', '/api/referral/my-codes/purchase', ['authenticatedAs' => 2])
        );

        $this->assertEquals(201, $response->getStatusCode());
        $body = json_decode($response->getBody()->getContents(), true);

        $this->assertEquals(50, $body['meta']['purchase']['balance']);
        $this->assertEquals(1, $body['meta']['purchase']['purchasedToday']);
        $this->assertEquals(InviteCode::CHANNEL_PURCHASE, $body['data']['channel']);
        $this->assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{8}$/', $body['data']['code']);

        $invite = $this->database()->table('referral_invite_codes')->where('user_id', 2)->first();
        $this->assertNotNull($invite);
        $this->assertEquals(InviteCode::CHANNEL_PURCHASE, $invite->channel);
        $this->assertEquals(100, (int) $invite->price_paid);
        $this->assertNotNull($invite->purchased_at);

        $this->assertEquals(50, $this->database()->table('point_system_user_points')->where('user_id', 2)->value('balance'));
        $this->assertEquals(
            -100,
            $this->database()->table('point_system_transactions')
                ->where('user_id', 2)
                ->where('reason', 'referral.invite_code.purchase')
                ->where('reference_type', InviteCode::class)
                ->where('reference_id', $invite->id)
                ->value('amount')
        );
    }

    #[Test]
    public function insufficient_points_do_not_create_an_invite_code(): void
    {
        $this->setBalance(50, 50);

        $response = $this->send(
            $this->request('POST', '/api/referral/my-codes/purchase', ['authenticatedAs' => 2])
        );

        $this->assertEquals(422, $response->getStatusCode());
        $this->assertEquals(0, $this->database()->table('referral_invite_codes')->count());
        $this->assertEquals(50, $this->database()->table('point_system_user_points')->where('user_id', 2)->value('balance'));
        $this->assertEquals(0, $this->database()->table('point_system_transactions')->count());
    }

    #[Test]
    public function daily_purchase_limit_uses_the_beijing_calendar_day(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-14 00:30:00', 'Asia/Shanghai'));
        $this->setting('linkrobins-referral.purchase_daily_limit', '1');
        $this->setBalance(500, 500);

        $this->database()->table('referral_invite_codes')->insert([
            'user_id' => 2,
            'code' => 'EARLYDAY',
            'uses' => 0,
            'channel' => InviteCode::CHANNEL_PURCHASE,
            'price_paid' => 100,
            'purchased_at' => CarbonImmutable::parse('2026-09-14 00:10:00', 'Asia/Shanghai')->utc(),
            'created_at' => CarbonImmutable::parse('2026-09-14 00:10:00', 'Asia/Shanghai')->utc(),
            'updated_at' => CarbonImmutable::parse('2026-09-14 00:10:00', 'Asia/Shanghai')->utc(),
        ]);

        $response = $this->send(
            $this->request('POST', '/api/referral/my-codes/purchase', ['authenticatedAs' => 2])
        );

        $this->assertEquals(422, $response->getStatusCode());
        $this->assertEquals(1, $this->database()->table('referral_invite_codes')->where('user_id', 2)->count());
        $this->assertEquals(500, $this->database()->table('point_system_user_points')->where('user_id', 2)->value('balance'));
    }

    #[Test]
    public function purchased_invite_codes_expire_after_the_configured_beijing_time_window(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-14 09:15:00', 'Asia/Shanghai'));
        $this->setting('linkrobins-referral.purchase_expiry_hours', '6');
        $this->setBalance(150, 150);

        $response = $this->send(
            $this->request('POST', '/api/referral/my-codes/purchase', ['authenticatedAs' => 2])
        );

        $this->assertEquals(201, $response->getStatusCode());
        $body = json_decode($response->getBody()->getContents(), true);

        $this->assertEquals('2026-09-14T15:15:00+08:00', $body['data']['expiresAt']);
        $this->assertEquals(
            '2026-09-14 07:15:00',
            (string) $this->database()->table('referral_invite_codes')->where('user_id', 2)->value('expires_at')
        );
    }

    #[Test]
    public function disabled_point_system_setting_blocks_purchase(): void
    {
        $this->setting('point-system.enabled', '0');
        $this->setBalance(150, 150);

        $response = $this->send(
            $this->request('POST', '/api/referral/my-codes/purchase', ['authenticatedAs' => 2])
        );

        $this->assertEquals(422, $response->getStatusCode());
        $this->assertEquals(0, $this->database()->table('referral_invite_codes')->count());
        $this->assertEquals(150, $this->database()->table('point_system_user_points')->where('user_id', 2)->value('balance'));
    }
}
