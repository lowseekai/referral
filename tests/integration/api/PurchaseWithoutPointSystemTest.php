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
use PHPUnit\Framework\Attributes\Test;

class PurchaseWithoutPointSystemTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    public function setUp(): void
    {
        parent::setUp();

        $this->extension('linkrobins-referral');

        $this->setting('linkrobins-referral.purchase_enabled', '1');
        $this->setting('linkrobins-referral.purchase_price', '100');

        $this->prepareDatabase([
            'users' => [
                $this->normalUser(), // id 2
            ],
        ]);
    }

    #[Test]
    public function point_system_extension_must_be_enabled_to_purchase_codes(): void
    {
        $response = $this->send(
            $this->request('POST', '/api/referral/my-codes/purchase', ['authenticatedAs' => 2])
        );

        $this->assertEquals(422, $response->getStatusCode());
        $this->assertEquals(0, $this->database()->table('referral_invite_codes')->count());
    }
}

