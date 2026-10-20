<?php

namespace Ernestdefoe\Salvage\Tests\integration;

use PHPUnit\Framework\Attributes\Test;

class FofUploadTest extends SalvageTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('fof-upload');
        $this->prepareDatabase([
            'group_user' => [['user_id' => 2, 'group_id' => 3]],
            'group_permission' => [['group_id' => 3, 'permission' => 'discussion.reply']],
        ]);
    }

    #[Test]
    public function stores_copies_through_fof_upload_as_the_poster(): void
    {
        $this->prepareDatabase(['group_permission' => [['group_id' => 3, 'permission' => 'discussion.reply'], ['group_id' => 3, 'permission' => 'fof-upload.upload']]]);
        $this->boot();
        $this->http->respond('https://img.example/dog.jpg', 200, [], self::png());

        $response = $this->reply('![dog](https://img.example/dog.jpg)', 2);
        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $this->run_();

        $link = $this->link('https://img.example/dog.jpg');
        $this->assertSame('mirror', $link->action);
        $this->assertNull($link->mirror_path, 'FoF Upload holds the file, not our disk.');

        $file = $this->database()->table('fof_upload_files')->first();
        $this->assertSame(2, (int) $file->actor_id);
        $this->assertSame($file->url, $link->replacement);
        $this->assertSame('image/png', $file->type, 'Sniffed from the bytes, not the .jpg in the URL.');
        $this->assertStringContainsString('src="'.$file->url.'"', $this->content((int) $this->json($response)['data']['id']));
    }

    #[Test]
    public function falls_back_to_its_own_disk_when_the_poster_may_not_upload(): void
    {
        $this->boot();
        $this->http->respond('https://img.example/dog.png', 200, [], self::png());

        $this->assertSame(201, $this->reply('![dog](https://img.example/dog.png)', 2)->getStatusCode());
        $this->run_();

        $link = $this->link('https://img.example/dog.png');
        $this->assertSame('mirror', $link->action);
        $this->assertNotNull($link->mirror_path);
        $this->assertSame(0, $this->database()->table('fof_upload_files')->count());
    }
}
