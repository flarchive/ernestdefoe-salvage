<?php

namespace Ernestdefoe\Salvage;

use Flarum\Settings\SettingsRepositoryInterface;

final class Settings
{
    public const CHECK_LINKS = 'ernestdefoe-salvage.check_links';
    public const MIRROR_IMAGES = 'ernestdefoe-salvage.mirror_images';
    public const USE_FOF_UPLOAD = 'ernestdefoe-salvage.use_fof_upload';
    public const PER_RUN = 'ernestdefoe-salvage.per_run';
    public const RECHECK_DAYS = 'ernestdefoe-salvage.recheck_days';
    public const MAX_IMAGE_KB = 'ernestdefoe-salvage.max_image_kb';
    public const DAILY_MB = 'ernestdefoe-salvage.daily_mb';
    public const SKIP_DOMAINS = 'ernestdefoe-salvage.skip_domains';
    public const ARCHIVE = 'ernestdefoe-salvage.archive';
    public const AUTO_ARCHIVE = 'ernestdefoe-salvage.auto_archive';
    public const FLAG_POSTS = 'ernestdefoe-salvage.flag_posts';
    /** Internal: the last post id the backfill scan reached, empty when no scan runs. */
    public const SCAN_CURSOR = 'ernestdefoe-salvage.scan_cursor';

    public const DEFAULTS = [
        self::CHECK_LINKS => true,
        self::MIRROR_IMAGES => true,
        self::USE_FOF_UPLOAD => true,
        self::PER_RUN => 40,
        self::RECHECK_DAYS => 30,
        self::MAX_IMAGE_KB => 5120,
        self::DAILY_MB => 500,
        self::SKIP_DOMAINS => '',
        self::ARCHIVE => true,
        self::AUTO_ARCHIVE => false,
        self::FLAG_POSTS => false,
        self::SCAN_CURSOR => '',
    ];

    public function __construct(private readonly SettingsRepositoryInterface $settings)
    {
    }

    public function on(string $key): bool
    {
        return (bool) $this->settings->get($key);
    }

    public function int(string $key, int $min, int $max): int
    {
        return max($min, min($max, (int) $this->settings->get($key)));
    }

    /**
     * Hosts never fetched: exact names, and their subdomains.
     *
     * @return list<string>
     */
    public function skipDomains(): array
    {
        $lines = preg_split('/[\s,]+/', strtolower((string) $this->settings->get(self::SKIP_DOMAINS))) ?: [];

        return array_values(array_filter(array_map(fn ($d) => trim($d, " .\t*"), $lines)));
    }

    public function scanCursor(): ?int
    {
        $value = (string) $this->settings->get(self::SCAN_CURSOR);

        return $value === '' ? null : (int) $value;
    }

    public function setScanCursor(?int $cursor): void
    {
        $this->settings->set(self::SCAN_CURSOR, $cursor === null ? '' : (string) $cursor);
    }
}
