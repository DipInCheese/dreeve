<?php

namespace App\Tests\Controller\Admin;

class LoginHandoffRequestHandlerTest extends AdminWebTestCase
{
    public function testItDoesNotExistOnASingleUserInstallation(): void
    {
        $this->client->request('GET', '/admin/login/handoff?token=anything');

        $this->assertResponseStatusCodeSame(404);
    }
}
