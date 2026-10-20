<?php

namespace Ernestdefoe\Salvage\Tests\integration;

use Ernestdefoe\Salvage\Link;
use Ernestdefoe\Salvage\Settings;
use PHPUnit\Framework\Attributes\Test;

class TrackingTest extends SalvageTestCase
{
    #[Test]
    public function records_the_links_and_images_of_a_new_post(): void
    {
        $response = $this->reply('See [this](http://dead.example/a) and ![pic](https://img.example/p.png), plus http://alive.example/ and [home](http://localhost/d/1).');
        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $postId = (int) $this->json($response)['data']['id'];

        $this->assertSame('link', $this->link('http://dead.example/a')->kind);
        $this->assertSame('pending', $this->link('http://dead.example/a')->status);
        $this->assertSame('image', $this->link('https://img.example/p.png')->kind);
        $this->assertSame('alive.example', $this->link('http://alive.example/')->host);

        // A link to the forum itself is never fetched.
        $own = $this->link('http://localhost/d/1');
        $this->assertSame('skipped', $own->status);
        $this->assertSame('own_site', $own->error);
        $this->assertNull($own->next_check_at);

        $this->assertSame(4, $this->database()->table('salvage_link_post')->where('post_id', $postId)->count());
    }

    #[Test]
    public function an_edit_updates_which_links_a_post_holds(): void
    {
        $r = $this->reply('[a](http://dead.example/a) [b](http://alive.example/b)');
        $this->assertSame(201, $r->getStatusCode(), (string) $r->getBody());
        $postId = (int) $this->json($r)['data']['id'];
        $this->assertSame(2, $this->database()->table('salvage_link_post')->where('post_id', $postId)->count());

        $this->assertSame(200, $this->edit($postId, 'only [b](http://alive.example/b) now')->getStatusCode());

        $ids = $this->database()->table('salvage_link_post')->where('post_id', $postId)->pluck('link_id')->all();
        $this->assertEquals([$this->link('http://alive.example/b')->id], $ids);

        $this->assertSame(200, $this->edit($postId, 'no links at all')->getStatusCode());
        $this->assertSame(0, $this->database()->table('salvage_link_post')->where('post_id', $postId)->count());
    }

    #[Test]
    public function decisions_already_made_apply_to_new_and_edited_posts_without_touching_anything_else(): void
    {
        $this->app();
        $link = new Link();
        $link->url = 'http://dead.example/a';
        $link->url_hash = Link::hash($link->url);
        $link->host = 'dead.example';
        $link->kind = 'link';
        $link->status = 'broken';
        $link->action = 'replace';
        $link->setReplacement('https://web.archive.org/web/2020/http://dead.example/a');
        $link->save();

        $response = $this->reply('Hi @"normal"#2, **look** at [the page](http://dead.example/a).');
        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $post = $this->json($response)['data'];
        $xml = $this->content((int) $post['id']);

        $this->assertStringContainsString('url="https://web.archive.org/web/2020/http://dead.example/a"', $xml);
        $this->assertStringContainsString('<USERMENTION', $xml, 'Mentions are parsed and kept.');
        $this->assertStringContainsString('<STRONG>', $xml);
        $this->assertStringContainsString('href="https://web.archive.org/web/2020/http://dead.example/a"', $post['attributes']['contentHtml']);
        $this->assertStringContainsString('<a href="http://localhost/u/normal" class="UserMention"', $post['attributes']['contentHtml']);

        // The pivot points at the original link, found again from its replacement.
        $this->assertSame([$link->id], $this->database()->table('salvage_link_post')->where('post_id', $post['id'])->pluck('link_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame(1, Link::query()->count(), 'No row is made for the replacement itself.');

        // An edit that keeps the original text gets the decision again.
        $this->edit((int) $post['id'], 'Edited: [the page](http://dead.example/a)');
        $this->assertStringContainsString('url="https://web.archive.org/web/2020/http://dead.example/a"', $this->content((int) $post['id']));

        // Unlinked: the text stays, the link goes.
        $link->action = 'unlink';
        $link->save();
        $this->edit((int) $post['id'], 'Again: [the page](http://dead.example/a)');
        $this->assertStringNotContainsString('<URL', $this->content((int) $post['id']));
        $this->assertStringContainsString('Again: the page', $this->content((int) $post['id']));
    }

    #[Test]
    public function the_skip_list_and_unsafe_urls_are_never_queued(): void
    {
        $this->setting(Settings::SKIP_DOMAINS, "Skip.example\n*.cdn.example");

        $this->reply('[a](https://skip.example/x) [b](https://www.skip.example/y) [c](https://img.cdn.example/z) [d](http://10.0.0.1/) [e](http://example.com:2222/) [f](http://2130706433/)');

        $this->assertSame('skip_list', $this->link('https://skip.example/x')->error);
        $this->assertSame('skip_list', $this->link('https://www.skip.example/y')->error);
        $this->assertSame('skip_list', $this->link('https://img.cdn.example/z')->error);
        $this->assertSame('port', $this->link('http://example.com:2222/')->error);
        $this->assertSame('host', $this->link('http://2130706433/')->error);
        // A literal private address passes the shape check and is refused when fetched.
        $this->assertSame('pending', $this->link('http://10.0.0.1/')->status);
    }

    #[Test]
    public function a_post_with_hundreds_of_links_is_tracked_for_its_first_hundred(): void
    {
        $links = implode(' ', array_map(fn ($i) => "[l$i](https://alive.example/$i)", range(1, 150)));
        $this->assertSame(201, $this->reply($links)->getStatusCode());

        $this->assertSame(100, Link::query()->count());
    }
}
