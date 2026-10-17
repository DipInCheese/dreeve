<?php

namespace App\Tests\Controller\Page;

use App\Tests\Controller\Admin\AdminWebTestCase;

class InsightsRequestHandlerTest extends AdminWebTestCase
{
    public function testItSaysWhatIsComingEvenWithoutActivities(): void
    {
        $crawler = $this->client->request('GET', '/insights');

        $this->assertResponseIsSuccessful();
        $this->assertCount(1, $crawler->filter('[data-insights-coming]'));
        $this->assertStringContainsString('only if you switch it on', $crawler->filter('[data-insights-coming]')->text());
        $this->assertCount(1, $crawler->filter('a[href="/insights"][aria-selected="true"]'));
    }

    public function testTheSidebarGroupsHealthTrainingAndSettings(): void
    {
        $crawler = $this->client->request('GET', '/insights');

        $sidebar = $crawler->filter('aside')->first()->text();
        foreach (['Today', 'Training', 'Insights', 'Data & settings'] as $label) {
            $this->assertStringContainsString($label, $sidebar);
        }
        $this->assertLessThan(strpos($sidebar, 'Training'), strpos($sidebar, 'Today'));
        $this->assertLessThan(strpos($sidebar, 'Data & settings'), strpos($sidebar, 'Training'));
    }
}
