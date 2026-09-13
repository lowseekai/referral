<?php

/*
 * This file is part of linkrobins/referral.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace LinkRobins\Referral\Tests\unit;

use Carbon\Carbon;
use Flarum\Group\Group;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use LinkRobins\Referral\EligibilityChecker;
use Mockery as m;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use PHPUnit\Framework\Attributes\Test;

class EligibilityCheckerTest extends MockeryTestCase
{
    private function checker(array $settings = []): EligibilityChecker
    {
        $repository = m::mock(SettingsRepositoryInterface::class);
        $repository->shouldReceive('get')->andReturnUsing(
            fn (string $key, $default = null) => $settings[$key] ?? $default
        );

        return new EligibilityChecker($repository);
    }

    /**
     * Real User model, no DB: attributes set directly and the groups relation
     * pre-loaded, so isAdmin() and the group rule read in-memory state.
     *
     * @param list<int> $groupIds
     */
    private function user(string $username = 'someone', array $groupIds = [Group::MEMBER_ID], int $commentCount = 0, ?Carbon $joinedAt = null): User
    {
        $user = new User();
        $user->username = $username;
        $user->comment_count = $commentCount;
        // Bypass the datetime cast on write: serialising a date needs a DB
        // connection for its format, but reading a raw Carbon back does not.
        $user->setRawAttributes(array_merge($user->getAttributes(), [
            'joined_at' => $joinedAt ?? Carbon::now()->subYears(1),
        ]));
        $user->setRelation('groups', (new Group())->newCollection(array_map(
            function (int $id) {
                $group = new Group();
                $group->id = $id;

                return $group;
            },
            $groupIds
        )));

        return $user;
    }

    #[Test]
    public function everyone_is_eligible_when_no_rules_are_configured(): void
    {
        $this->assertTrue($this->checker()->isEligible($this->user()));
    }

    #[Test]
    public function admins_are_always_eligible(): void
    {
        $checker = $this->checker([
            'linkrobins-referral.eligibility_min_posts' => '100',
        ]);

        $this->assertTrue($checker->isEligible($this->user(groupIds: [Group::ADMINISTRATOR_ID])));
    }

    #[Test]
    public function whitelisted_usernames_bypass_every_rule(): void
    {
        $checker = $this->checker([
            'linkrobins-referral.eligibility_whitelist' => 'Trusty, OtherPerson',
            'linkrobins-referral.eligibility_min_posts' => '100',
        ]);

        // Whitelist matching is case-insensitive.
        $this->assertTrue($checker->isEligible($this->user(username: 'trusty')));
        $this->assertFalse($checker->isEligible($this->user(username: 'stranger')));
    }

    #[Test]
    public function the_group_rule_requires_membership_in_an_allowed_group(): void
    {
        $checker = $this->checker([
            'linkrobins-referral.eligibility_groups' => '[4]',
        ]);

        $this->assertFalse($checker->isEligible($this->user(groupIds: [Group::MEMBER_ID])));
        $this->assertTrue($checker->isEligible($this->user(groupIds: [Group::MEMBER_ID, 4])));
    }

    #[Test]
    public function structured_group_rules_replace_the_legacy_group_list(): void
    {
        $checker = $this->checker([
            'linkrobins-referral.eligibility_groups' => '[4]',
            'linkrobins-referral.group_rules' => json_encode([
                ['groupId' => 5, 'quantity' => 3, 'expiryHours' => 24],
            ]),
        ]);

        $this->assertFalse($checker->isEligible($this->user(groupIds: [Group::MEMBER_ID, 4])));
        $this->assertTrue($checker->isEligible($this->user(groupIds: [Group::MEMBER_ID, 5])));
    }

    #[Test]
    public function the_highest_matching_group_quantity_wins(): void
    {
        $checker = $this->checker([
            'linkrobins-referral.group_rules' => json_encode([
                ['groupId' => 5, 'quantity' => 2, 'expiryHours' => 72],
                ['groupId' => 6, 'quantity' => 8, 'expiryHours' => 24],
            ]),
        ]);

        $rule = $checker->matchingGroupRule($this->user(groupIds: [Group::MEMBER_ID, 5, 6]));

        $this->assertSame(6, $rule['groupId']);
        $this->assertSame(8, $rule['quantity']);
        $this->assertSame(24, $rule['expiryHours']);
    }

    #[Test]
    public function the_minimum_post_rule_counts_comments(): void
    {
        $checker = $this->checker([
            'linkrobins-referral.eligibility_min_posts' => '5',
        ]);

        $this->assertFalse($checker->isEligible($this->user(commentCount: 4)));
        $this->assertTrue($checker->isEligible($this->user(commentCount: 5)));
    }

    #[Test]
    public function the_minimum_age_rule_requires_an_old_enough_account(): void
    {
        $checker = $this->checker([
            'linkrobins-referral.eligibility_min_age_days' => '7',
        ]);

        $this->assertFalse($checker->isEligible($this->user(joinedAt: Carbon::now()->subDays(2))));
        $this->assertTrue($checker->isEligible($this->user(joinedAt: Carbon::now()->subDays(8))));
    }
}
