<?php

namespace App\Tests\Shared\Functional\Infrastructure\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class EmailsTest extends WebTestCase
{
    public function testEmailIsSentAndContentIsCorrect(): void
    {
        $client = static::createClient();

        $client->request('GET', '/send-email');

        $this->assertResponseIsSuccessful();

        $this->assertEmailCount(1);

        $email = $this->getMailerMessage();

        $this->assertEmailHeaderSame($email, 'subject', 'Time for Symfony Mailer! test');
        $this->assertEmailAddressContains($email, 'from', 'hello@example.com');
        $this->assertEmailAddressContains($email, 'to', 'you@example.com');
        $this->assertEmailTextBodyContains($email, 'Sending emails is fun again!');
    }
}
