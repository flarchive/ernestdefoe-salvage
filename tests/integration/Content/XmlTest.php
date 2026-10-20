<?php

namespace Ernestdefoe\Salvage\Tests\integration\Content;

use Ernestdefoe\Salvage\Content\Xml;
use Flarum\Formatter\Formatter;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

class XmlTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    private const DEAD = 'http://dead.example/page?a=1&b=2';
    private const NEW = 'https://web.archive.org/web/2020/http://dead.example/page?a=1&b=2';

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('flarum-markdown', 'flarum-bbcode', 'flarum-mentions', 'ernestdefoe-salvage');
        $this->prepareDatabase([User::class => [$this->normalUser()]]);
    }

    private function formatter(): Formatter
    {
        return $this->app()->getContainer()->make(Formatter::class);
    }

    private function parse(string $text): string
    {
        return $this->formatter()->parse($text, null, User::find(1));
    }

    private function sample(): string
    {
        return $this->parse(implode("\n\n", [
            'Hello @"normal"#2, **bold** text.',
            'A [markdown link]('.self::DEAD.') and an autolink '.self::DEAD.' and <'.self::DEAD.'>.',
            '[url='.self::DEAD.']bbcode link[/url] and [url=https://alive.example/]alive[/url]',
            '![alt text](https://img.example/p.png) [img]https://img.example/q.jpg[/img]',
            '[![linked image](https://img.example/p.png)]('.self::DEAD.')',
        ]));
    }

    #[Test]
    public function finds_links_and_images(): void
    {
        $urls = Xml::urls($this->sample());

        $this->assertSame([self::DEAD, 'https://alive.example/'], $urls['links']);
        $this->assertSame(['https://img.example/p.png', 'https://img.example/q.jpg'], $urls['images']);
        $this->assertSame(['links' => [], 'images' => []], Xml::urls($this->parse('plain text only')));
        $this->assertSame(['links' => [], 'images' => []], Xml::urls(null));
    }

    #[Test]
    public function replacing_changes_only_the_attributes(): void
    {
        $xml = $this->sample();
        $new = Xml::rewrite($xml, [self::DEAD => self::NEW, 'https://img.example/p.png' => 'https://forum.example/assets/salvage/ab/cd.png']);

        $expected = str_replace(
            ['url="'.htmlspecialchars(self::DEAD).'"', 'src="https://img.example/p.png"'],
            ['url="'.htmlspecialchars(self::NEW).'"', 'src="https://forum.example/assets/salvage/ab/cd.png"'],
            $xml
        );

        $this->assertSame($expected, $new, 'Every other byte is untouched.');

        $html = $this->formatter()->render($new);
        $this->assertStringContainsString('href="'.htmlspecialchars(self::NEW).'"', $html);
        $this->assertStringNotContainsString('href="http://dead.example', $html);
        $this->assertStringContainsString('src="https://forum.example/assets/salvage/ab/cd.png"', $html);
        $this->assertStringContainsString('markdown link', $html);
        $this->assertStringContainsString('src="https://img.example/q.jpg"', $html);
    }

    #[Test]
    public function unlinking_keeps_the_text_and_drops_the_link(): void
    {
        $xml = $this->sample();
        $new = Xml::rewrite($xml, [], [self::DEAD => true]);
        $html = $this->formatter()->render($new);

        // No link goes there any more; an autolink keeps its address as plain text.
        $this->assertStringNotContainsString('href="http://dead.example', $html);
        $this->assertStringContainsString('an autolink http://dead.example/page?a=1&amp;b=2 and', $html);
        $this->assertStringContainsString('markdown link', $html);
        $this->assertStringContainsString('bbcode link', $html);
        $this->assertStringContainsString('href="https://alive.example/"', $html);
        // The image that was inside the dead link stays, unlinked.
        $this->assertStringContainsString('alt="linked image"', $html);

        $text = $this->formatter()->unparse($new);
        $this->assertStringContainsString('A markdown link and an autolink', $text);
        $this->assertStringContainsString('bbcode link and [url=https://alive.example/]alive[/url]', $text);
        $this->assertStringContainsString('@"normal"#2', $text);
    }

    #[Test]
    public function unlinking_an_image_keeps_its_alt_text(): void
    {
        $new = Xml::rewrite($this->parse('see ![a cat](https://img.example/cat.png) here'), [], ['https://img.example/cat.png' => true]);

        $this->assertSame('<p>see a cat here</p>', $this->formatter()->render($new));
    }

    #[Test]
    public function leaves_unrelated_posts_alone(): void
    {
        $xml = $this->sample();

        $this->assertSame($xml, Xml::rewrite($xml, ['https://other.example/' => 'https://x.example/'], ['https://other.example/' => true]));
    }
}
