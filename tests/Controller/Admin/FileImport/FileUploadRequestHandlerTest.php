<?php

namespace App\Tests\Controller\Admin\FileImport;

use App\Tests\Controller\Admin\AdminWebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class FileUploadRequestHandlerTest extends AdminWebTestCase
{
    #[DataProvider('provideAdminPaths')]
    public function testDoesNotRenderTheGatedPanelInFileImportMode(string $path): void
    {
        $this->client->loginUser($this->adminUser());

        $crawler = $this->client->request('GET', $path);

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('Upload activity files', $crawler->filter('body')->text());
        $this->assertCount(0, $crawler->filter('[role="alert"][type="gated-panel"]'));
        $this->assertStringNotContainsString(
            'File upload is only available in file import mode',
            $crawler->filter('body')->text()
        );
    }

    public static function provideAdminPaths(): iterable
    {
        yield 'root admin path' => ['/admin'];
        yield 'upload path' => ['/admin/upload'];
    }
}
