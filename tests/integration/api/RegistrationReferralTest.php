<?php

/*
 * This file is part of linkrobins/referral.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace LinkRobins\Referral\Tests\integration\api;

use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use LinkRobins\Referral\ReferralTime;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;

class RegistrationReferralTest extends TestCase
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

    /**
     * Register through the real signup flow: CSRF token first, then the POST,
     * with the referral cookie attached the same way the browser would send it.
     */
    private function register(?string $cookieCode = null, string $username = 'newmember'): ResponseInterface
    {
        $request = $this->requestWithCsrfToken(
            $this->request('POST', '/api/users', [
                'json' => [
                    'data' => [
                        'attributes' => [
                            'username' => $username,
                            'email' => $username.'@machine.local',
                            'password' => 'a-strong-password',
                        ],
                    ],
                ],
            ])
        );

        if ($cookieCode !== null) {
            $request = $request->withCookieParams(
                array_merge($request->getCookieParams(), ['referral_code' => $cookieCode])
            );
        }

        return $this->send($request);
    }

    #[Test]
    public function a_valid_personal_code_records_the_referral(): void
    {
        $this->prepareDatabase([
            'referral_invite_codes' => [
                ['id' => 1, 'user_id' => 2, 'code' => 'TESTCODE', 'uses' => 0],
            ],
        ]);

        $response = $this->register('TESTCODE');

        $this->assertEquals(201, $response->getStatusCode());

        $newUser = $this->database()->table('users')->where('username', 'newmember')->first();
        $relation = $this->database()->table('referral_invited_user')->where('user_id', $newUser->id)->first();
        $this->assertNotNull($relation, 'Expected a referral relation to be recorded.');
        $this->assertEquals(2, $relation->referred_by_user_id);

        // The denormalised counters must stay in sync.
        $this->assertEquals(1, $this->database()->table('users')->where('id', 2)->value('referral_count'));
        $this->assertEquals(1, $this->database()->table('referral_invite_codes')->where('id', 1)->value('uses'));
        $this->assertNotNull($this->database()->table('referral_invite_codes')->where('id', 1)->value('used_at'));
        $this->assertEquals($newUser->id, $this->database()->table('referral_invite_codes')->where('id', 1)->value('used_by_user_id'));
    }

    #[Test]
    public function a_recorded_referral_notifies_the_referrer(): void
    {
        $this->prepareDatabase([
            'referral_invite_codes' => [
                ['id' => 1, 'user_id' => 2, 'code' => 'TESTCODE', 'uses' => 0],
            ],
        ]);

        $response = $this->register('TESTCODE');

        $this->assertEquals(201, $response->getStatusCode());

        $newUser = $this->database()->table('users')->where('username', 'newmember')->first();
        $notification = $this->database()->table('notifications')
            ->where('user_id', 2)
            ->where('type', 'linkrobinsReferralRegistered')
            ->first();

        $this->assertNotNull($notification, 'Expected the referrer to receive a notification.');
        $this->assertEquals($newUser->id, $notification->subject_id);
        $this->assertEquals($newUser->id, $notification->from_user_id);
    }

    /**
     * The forum-side NotificationList.content override (js/forum.js) fully
     * reimplements core's grouping so referral notifications land in their own
     * group instead of the neutral "forum title" bucket. That reproduced logic
     * keys on two things in the serialized notification: contentType ===
     * 'linkrobinsReferralRegistered', and a subject that is a user (never a
     * discussion). This test locks that contract at the API layer so a change
     * that would silently break the frontend grouping fails loudly here.
     */
    #[Test]
    public function the_referral_notification_serialises_with_the_shape_the_grouping_relies_on(): void
    {
        $this->prepareDatabase([
            'referral_invite_codes' => [
                ['id' => 1, 'user_id' => 2, 'code' => 'TESTCODE', 'uses' => 0],
            ],
        ]);

        $this->assertEquals(201, $this->register('TESTCODE')->getStatusCode());

        // Explicit include of just the subject: this endpoint's default include
        // pulls in subject.discussion, which core only makes valid once some
        // registered notification subject type is discussion-bearing (a Post),
        // so a plain GET here in a minimal core+referral forum is a separate
        // concern. This test only cares about the serialized shape the frontend
        // grouping reads, so it requests the subject relationship directly. The
        // query param must go through withQueryParams(): the test request helper
        // doesn't parse the query string off the URI.
        $response = $this->send(
            $this->request('GET', '/api/notifications', ['authenticatedAs' => 2])
                ->withQueryParams(['include' => 'fromUser,subject'])
        );

        $this->assertEquals(200, $response->getStatusCode());
        $body = json_decode($response->getBody()->getContents(), true);

        $referral = null;
        foreach ($body['data'] as $notification) {
            if (($notification['attributes']['contentType'] ?? null) === 'linkrobinsReferralRegistered') {
                $referral = $notification;
                break;
            }
        }

        $this->assertNotNull($referral, 'Expected a notification with contentType linkrobinsReferralRegistered.');
        // The subject the grouping inspects must be a user, not a discussion.
        $this->assertEquals('users', $referral['relationships']['subject']['data']['type'] ?? null);
    }

    #[Test]
    public function campaign_codes_do_not_notify_anyone(): void
    {
        $this->prepareDatabase([
            'referral_invite_codes' => [
                ['id' => 1, 'user_id' => null, 'code' => 'CAMPAIGN', 'uses' => 0, 'label' => 'Launch'],
            ],
        ]);

        $response = $this->register('CAMPAIGN');

        $this->assertEquals(201, $response->getStatusCode());
        $this->assertEquals(
            0,
            $this->database()->table('notifications')->where('type', 'linkrobinsReferralRegistered')->count()
        );
    }

    #[Test]
    public function an_unknown_code_blocks_registration(): void
    {
        $response = $this->register('WRONGCODE');

        $this->assertEquals(422, $response->getStatusCode());
        $this->assertEquals(0, $this->database()->table('users')->where('username', 'newmember')->count());
    }

    #[Test]
    public function an_expired_code_blocks_registration(): void
    {
        $this->prepareDatabase([
            'referral_invite_codes' => [
                ['id' => 1, 'user_id' => 2, 'code' => 'TESTCODE', 'uses' => 0, 'expires_at' => ReferralTime::now()->subDay()->utc()],
            ],
        ]);

        $response = $this->register('TESTCODE');

        $this->assertEquals(422, $response->getStatusCode());
        $this->assertEquals(0, $this->database()->table('users')->where('username', 'newmember')->count());
    }

    #[Test]
    public function an_already_used_code_blocks_registration(): void
    {
        $this->prepareDatabase([
            'referral_invite_codes' => [
                ['id' => 1, 'user_id' => 2, 'code' => 'TESTCODE', 'uses' => 1, 'used_at' => ReferralTime::now()->subHour()->utc()],
            ],
        ]);

        $response = $this->register('TESTCODE');

        $this->assertEquals(422, $response->getStatusCode());
        $this->assertEquals(0, $this->database()->table('users')->where('username', 'newmember')->count());
    }

    #[Test]
    public function a_successfully_redeemed_code_cannot_be_used_again(): void
    {
        $this->prepareDatabase([
            'referral_invite_codes' => [
                ['id' => 1, 'user_id' => 2, 'code' => 'ONCEONLY', 'uses' => 0],
            ],
        ]);

        $first = $this->register('ONCEONLY', 'firstmember');
        $second = $this->register('ONCEONLY', 'secondmember');

        $this->assertEquals(201, $first->getStatusCode());
        $this->assertEquals(422, $second->getStatusCode());
        $this->assertEquals(1, $this->database()->table('referral_invite_codes')->where('id', 1)->value('uses'));
        $this->assertEquals(1, $this->database()->table('users')->whereIn('username', ['firstmember', 'secondmember'])->count());
    }

    #[Test]
    public function an_active_registration_reservation_blocks_a_second_reservation(): void
    {
        $this->prepareDatabase([
            'referral_invite_codes' => [
                [
                    'id' => 1,
                    'user_id' => 2,
                    'code' => 'RESERVED1',
                    'uses' => 0,
                    'reserved_at' => ReferralTime::now()->utc(),
                    'reservation_token' => 'another-request',
                ],
            ],
        ]);

        $response = $this->register('RESERVED1');

        $this->assertEquals(422, $response->getStatusCode());
        $this->assertEquals(0, $this->database()->table('users')->where('username', 'newmember')->count());
    }

    #[Test]
    public function an_expired_registration_reservation_can_be_reclaimed(): void
    {
        $this->prepareDatabase([
            'referral_invite_codes' => [
                [
                    'id' => 1,
                    'user_id' => 2,
                    'code' => 'RECLAIM1',
                    'uses' => 0,
                    'reserved_at' => ReferralTime::now()->subMinutes(16)->utc(),
                    'reservation_token' => 'stale-request',
                ],
            ],
        ]);

        $response = $this->register('RECLAIM1');

        $this->assertEquals(201, $response->getStatusCode());
        $this->assertEquals(1, $this->database()->table('referral_invite_codes')->where('id', 1)->value('uses'));
    }

    #[Test]
    public function a_failed_registration_releases_its_invite_code_reservation(): void
    {
        $this->prepareDatabase([
            'referral_invite_codes' => [
                ['id' => 1, 'user_id' => 2, 'code' => 'RELEASE1', 'uses' => 0],
            ],
        ]);

        $response = $this->register('RELEASE1', 'normal');

        $this->assertEquals(422, $response->getStatusCode());
        $invite = $this->database()->table('referral_invite_codes')->where('id', 1)->first();
        $this->assertEquals(0, (int) $invite->uses);
        $this->assertNull($invite->used_at);
        $this->assertNull($invite->reserved_at);
        $this->assertNull($invite->reservation_token);
    }

    #[Test]
    public function registration_without_a_code_works_when_referrals_are_optional(): void
    {
        $response = $this->register();

        $this->assertEquals(201, $response->getStatusCode());
        $this->assertEquals(0, $this->database()->table('referral_invited_user')->count());
    }

    #[Test]
    public function require_referral_blocks_codeless_registration(): void
    {
        $this->setting('linkrobins-referral.require_referral', '1');

        $response = $this->register();

        $this->assertEquals(422, $response->getStatusCode());
        $this->assertEquals(0, $this->database()->table('users')->where('username', 'newmember')->count());
    }

    #[Test]
    public function campaign_codes_count_uses_but_record_no_referrer(): void
    {
        $this->prepareDatabase([
            'referral_invite_codes' => [
                ['id' => 1, 'user_id' => null, 'code' => 'CAMPAIGN', 'uses' => 0, 'label' => 'Launch'],
            ],
        ]);

        $response = $this->register('CAMPAIGN');

        $this->assertEquals(201, $response->getStatusCode());
        $this->assertEquals(1, $this->database()->table('referral_invite_codes')->where('id', 1)->value('uses'));
        $this->assertNotNull($this->database()->table('referral_invite_codes')->where('id', 1)->value('used_at'));
        $this->assertEquals(0, $this->database()->table('referral_invited_user')->count());
    }
}
