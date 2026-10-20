<?php

namespace Ernestdefoe\Salvage\Work;

use Carbon\Carbon;
use Ernestdefoe\Salvage\Link;
use Ernestdefoe\Salvage\Net\Fetcher;
use Ernestdefoe\Salvage\Net\Refused;
use Ernestdefoe\Salvage\Settings;
use Flarum\Extension\ExtensionManager;
use Flarum\Post\Post;
use Flarum\User\User;
use FoF\Upload\Commands\Upload;
use FoF\Upload\Commands\UploadHandler;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Filesystem\Factory;
use Intervention\Image\Decoders\BinaryImageDecoder;
use Intervention\Image\ImageManager;
use Laminas\Diactoros\UploadedFile;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Copies a hotlinked image to the forum's own storage.
 *
 * The bytes are never stored as fetched: the type is sniffed from the
 * content (not the URL or Content-Type), only JPEG, PNG, GIF and WebP are
 * accepted, the pixel count is capped before decoding (decompression bombs),
 * and the image is decoded and encoded again, which drops anything that is
 * not pixels: EXIF and GPS data, comments, appended HTML or scripts. SVG is
 * never accepted, so nothing stored here can run in a browser.
 */
class Mirror
{
    public const DISK = 'ernestdefoe-salvage';
    /** Gives up mirroring after this many failed tries (the link is still checked). */
    public const MAX_TRIES = 3;
    /** Decoded pixels across all frames: ~64 MB of memory with GD. */
    public const MAX_PIXELS = 4096 * 4096;

    private const TYPES = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG => 'png',
        IMAGETYPE_GIF => 'gif',
        IMAGETYPE_WEBP => 'webp',
    ];

    public function __construct(
        private readonly Fetcher $fetcher,
        private readonly Checker $checker,
        private readonly Settings $settings,
        private readonly Factory $filesystem,
        private readonly ImageManager $images,
        private readonly ExtensionManager $extensions,
        private readonly Container $container,
        private readonly LoggerInterface $log,
        private readonly Cache $cache,
    ) {
    }

    /**
     * Anyone who may post can make the forum download pictures, so what is
     * stored in a day is capped: posting a flood of images cannot fill the disk.
     */
    public function overDailyCap(): bool
    {
        return (int) $this->cache->get(self::dayKey(), 0) >= $this->settings->int(Settings::DAILY_MB, 1, 1024 * 1024) * 1024 * 1024;
    }

    private static function dayKey(): string
    {
        return 'ernestdefoe-salvage.mirrored.'.gmdate('Ymd');
    }

    /**
     * @return int requests spent
     */
    public function mirror(Link $link): int
    {
        $maxBytes = $this->settings->int(Settings::MAX_IMAGE_KB, 50, 51200) * 1024;

        try {
            $response = $this->fetcher->fetch('GET', $link->url, $maxBytes);
        } catch (Refused $e) {
            if ($e->reason === 'too_large') {
                $this->giveUp($link, 'too_large');
            } else {
                $link->mirror_tries++;
                $this->checker->recordFailure($link, $e);
            }

            return 1;
        }

        if ($response->status !== 200) {
            $link->mirror_tries++;
            $this->checker->recordResponse($link, $response);

            return 1;
        }

        // Counted before decoding: if the decoder takes the process down, the
        // image is not tried again forever.
        $link->mirror_tries++;
        $link->save();

        $encoded = $this->reencode($response->body);

        if (is_string($encoded['error'] ?? null)) {
            $this->giveUp($link, $encoded['error']);

            return 1;
        }

        try {
            [$url, $path] = $this->store($link, $encoded['bytes'], $encoded['ext']);
        } catch (Throwable $e) {
            $this->log->warning('[salvage] storing a mirrored image failed: '.$e->getMessage());
            $link->error = 'storage';
            $link->next_check_at = Carbon::now()->addHours(6);
            $link->save();

            return 1;
        }

        $this->cache->put(self::dayKey(), (int) $this->cache->get(self::dayKey(), 0) + strlen($encoded['bytes']), 2 * 86400);

        $link->status = 'ok';
        $link->http_status = 200;
        $link->error = null;
        $link->fail_count = 0;
        $link->checked_at = Carbon::now();
        $link->next_check_at = null;
        $link->action = 'mirror';
        $link->setReplacement($url);
        $link->mirror_path = $path;
        $link->apply_pending = true;
        $link->save();

        return 1;
    }

    /**
     * @return array{bytes: string, ext: string}|array{error: string}
     */
    public function reencode(string $bytes): array
    {
        $info = @getimagesizefromstring($bytes);

        if ($info === false || ! isset(self::TYPES[$info[2]])) {
            return ['error' => 'not_image'];
        }

        $ext = self::TYPES[$info[2]];
        $frames = $ext === 'gif' ? max(1, substr_count($bytes, "\x21\xF9\x04")) : 1;

        $pixels = $info[0] * $info[1] * $frames;

        if ($info[0] < 1 || $info[1] < 1 || $pixels > self::MAX_PIXELS || ! self::fitsInMemory($pixels, strlen($bytes))) {
            return ['error' => 'too_large'];
        }

        try {
            // Bytes only: never let the decoder chain read the string as a path or URL.
            $image = $this->images->read($bytes, BinaryImageDecoder::class);

            $out = match ($ext) {
                'jpg' => $image->toJpeg(85),
                'png' => $image->toPng(),
                'gif' => $image->toGif(),
                'webp' => $image->toWebp(85),
            };

            return ['bytes' => $out->toString(), 'ext' => $ext];
        } catch (Throwable) {
            return ['error' => 'not_image'];
        }
    }

    /**
     * @return array{0: string, 1: string|null} public URL, and the path on our disk (null: FoF Upload has it)
     */
    private function store(Link $link, string $bytes, string $ext): array
    {
        if ($url = $this->storeWithFofUpload($link, $bytes, $ext)) {
            return [$url, null];
        }

        // Named by content: the same picture hotlinked from two URLs is stored once.
        $hash = hash('sha256', $bytes);
        $path = substr($hash, 0, 2).'/'.substr($hash, 2, 38).'.'.$ext;
        $disk = $this->filesystem->disk(self::DISK);

        if (! $disk->exists($path)) {
            $disk->put($path, $bytes, 'public');
        }

        return [$disk->url($path), $path];
    }

    /**
     * Through FoF Upload when it is on and the image's poster may upload:
     * then the copy lives wherever the forum keeps its uploads (local, S3...)
     * and shows in the poster's media manager.
     */
    private function storeWithFofUpload(Link $link, string $bytes, string $ext): ?string
    {
        if (! $this->settings->on(Settings::USE_FOF_UPLOAD)
            || ! class_exists(UploadHandler::class)
            || ! $this->extensions->isEnabled('fof-upload')) {
            return null;
        }

        $postId = $link->posts()->getQuery()->toBase()->min('posts.id');
        $author = $postId ? Post::query()->whereKey($postId)->value('user_id') : null;
        $actor = $author ? User::query()->find($author) : null;

        if (! $actor || ! $actor->can('fof-upload.upload')) {
            return null;
        }

        $temp = tempnam(sys_get_temp_dir(), 'salvage');

        if ($temp === false) {
            return null;
        }

        try {
            file_put_contents($temp, $bytes);
            $mime = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp'][$ext];
            $name = pathinfo((string) parse_url($link->url, PHP_URL_PATH), PATHINFO_FILENAME);
            $name = (preg_replace('/[^A-Za-z0-9._-]+/', '-', $name) ?: 'image').'.'.$ext;

            $files = $this->container->make(UploadHandler::class)->handle(
                new Upload(collect([new UploadedFile($temp, strlen($bytes), UPLOAD_ERR_OK, substr($name, 0, 100), $mime)]), $actor)
            );

            $url = $files->first()?->url;

            return is_string($url) && preg_match('~^https?://~i', $url) ? $url : null;
        } catch (Throwable $e) {
            // Their mime rules, quota or storage said no: our own disk instead.
            $this->log->info('[salvage] FoF Upload declined a mirrored image: '.$e->getMessage());

            return null;
        } finally {
            @unlink($temp);
        }
    }

    /**
     * GD holds about five bytes a pixel and encodes from that same bitmap;
     * the output and the source bytes, plus a margin, come on top.
     */
    private static function fitsInMemory(int $pixels, int $size): bool
    {
        $limit = (string) ini_get('memory_limit');

        if ($limit === '' || $limit === '-1') {
            return true;
        }

        $bytes = (int) $limit * match (strtolower(substr($limit, -1))) {
            'g' => 1024 ** 3,
            'm' => 1024 ** 2,
            'k' => 1024,
            default => 1,
        };

        return $pixels * 5 + $size * 2 + 8 * 1024 * 1024 < $bytes - memory_get_usage();
    }

    private function giveUp(Link $link, string $error): void
    {
        $link->mirror_tries = 255;
        $link->error = $error;
        // The source still answered, so it is alive; it is checked as a link from now on.
        $link->status = 'ok';
        $link->checked_at = Carbon::now();
        $link->next_check_at = Carbon::now()->addDays($this->settings->int(Settings::RECHECK_DAYS, 1, 365));
        $link->save();
    }
}
