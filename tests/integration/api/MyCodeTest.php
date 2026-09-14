<?php

/*
 * This file is part of linkrobins/referral.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace LinkRobins\Referral\Tests\integration\api;

use LinkRobins\Referral\ReferralTime;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use PHPUnit\Framework\Attributes\Test;

class MyCodeTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    public function setUp(): void
    {
        parent::setUp();

        $this->extension('linkrobins-referral');

        $this->prepareDatabase([
            'users' => [
                $this->normalUser(), // id 2
            ],
        ]);
    }

    #[Test]
    public function guests_cannot_generate_a_code(): void
    {
        $response = $this->send(
            $this->request('POST', '/api/referral/my-code')
        );

        // Rejected before anything is written (CSRF 400 or auth 401 -- the
        // guarantee that matters is that no code row appears).
        $this->assertGreaterThanOrEqual(400, $response->getStatusCode());
        $this->assertEquals(0, $this->database()->table('referral_invite_codes')->count());
    }

    #[Test]
    public function a_user_with_a_matching_group_rule_can_generate_until_quota_is_reached(): void
    {
        $this->grantGroupQuota(2, 10, 1);

        $first = $this->send(
            $this->request('POST', '/api/referral/my-code', ['authenticatedAs' => 2])
        );

        $this->assertEquals(201, $first->getStatusCode());
        $code = json_decode($first->getBody()->getContents(), true)['data']['code'];
        $this->assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{8}$/', $code);

        $second = $this->send(
            $this->request('POST', '/api/referral/my-code', ['authenticatedAs' => 2])
        );

        $this->assertEquals(422, $second->getStatusCode());
        $this->assertEquals(1, $this->database()->table('referral_invite_codes')->count());
    }

    #[Test]
    public function users_can_list_their_codes_and_remaining_quota(): void
    {
        $this->grantGroupQuota(2, 10, 1);

        $this->prepareDatabase([
            'referral_invite_codes' => [
                ['id' => 1, 'user_id' => 2, 'code' => 'TESTCODE', 'uses' => 0, 'channel' => 'group'],
            ],
        ]);

        $response = $this->send(
            $this->request('GET', '/api/referral/my-codes', ['authenticatedAs' => 2])
        );

        $this->assertEquals(200, $response->getStatusCode());
        $body = json_decode($response->getBody()->getContents(), true);

        $this->assertCount(1, $body['data']);
        $this->assertEquals('TESTCODE', $body['data'][0]['code']);
        $this->assertEquals(1, $body['meta']['entitlement']['maxQuantity']);
        $this->assertEquals(0, $body['meta']['entitlement']['remaining']);
    }

    #[Test]
    public function expired_group_codes_no_longer_consume_generation_quota(): void
    {
        $this->grantGroupQuota(2, 10, 1);

        $this->prepareDatabase([
            'referral_invite_codes' => [
                [
                    'id' => 1,
                    'user_id' => 2,
                    'code' => 'OLDGROUP',
                    'uses' => 0,
                    'channel' => 'group',
                    'expires_at' => ReferralTime::now()->subHour()->utc(),
                ],
            ],
        ]);

        $response = $this->send(
            $this->request('POST', '/api/referral/my-codes/generate', ['authenticatedAs' => 2])
        );

        $this->assertEquals(201, $response->getStatusCode(), (string) $response->getBody());
        $this->assertEquals(2, $this->database()->table('referral_invite_codes')->count());
    }

    #[Test]
    public function the_highest_matching_group_quota_wins(): void
    {
        $this->setting('linkrobins-referral.group_rules', json_encode([
            ['groupId' => 10, 'quantity' => 2, 'expiryHours' => 24],
            ['groupId' => 11, 'quantity' => 4, 'expiryHours' => 12],
        ]));

        $this->prepareDatabase([
            'groups' => [
                ['id' => 10, 'name_singular' => 'Small', 'name_plural' => 'Small', 'color' => null, 'icon' => null],
                ['id' => 11, 'name_singular' => 'Large', 'name_plural' => 'Large', 'color' => null, 'icon' => null],
            ],
            'group_user' => [
                ['user_id' => 2, 'group_id' => 10],
                ['user_id' => 2, 'group_id' => 11],
            ],
        ]);

        for ($i = 0; $i < 4; $i++) {
            $response = $this->send(
                $this->request('POST', '/api/referral/my-codes/generate', ['authenticatedAs' => 2])
            );
            $this->assertEquals(201, $response->getStatusCode());
        }

        $blocked = $this->send(
            $this->request('POST', '/api/referral/my-codes/generate', ['authenticatedAs' => 2])
        );

        $this->assertEquals(422, $blocked->getStatusCode());
        $this->assertEquals(4, $this->database()->table('referral_invite_codes')->where('user_id', 2)->count());
        $this->assertEquals(
            4,
            $this->database()->table('referral_invite_codes')->where('source_group_id', 11)->count()
        );
    }

    #[Test]
    public function a_user_without_a_matching_group_rule_is_denied(): void
    {
        $response = $this->send(
            $this->request('POST', '/api/referral/my-code', ['authenticatedAs' => 2])
        );

        $this->assertEquals(403, $response->getStatusCode());
        $this->assertEquals(0, $this->database()->table('referral_invite_codes')->count());
    }

    private function grantGroupQuota(int $userId, int $groupId, int $quantity, int $expiryHours = 0): void
    {
        $this->setting('linkrobins-referral.group_rules', json_encode([
            ['groupId' => $groupId, 'quantity' => $quantity, 'expiryHours' => $expiryHours],
        ]));

        $this->prepareDatabase([
            'groups' => [
                ['id' => $groupId, 'name_singular' => 'Inviter', 'name_plural' => 'Inviters', 'color' => null, 'icon' => null],
            ],
            'group_user' => [
                ['user_id' => $userId, 'group_id' => $groupId],
            ],
        ]);
    }
}
