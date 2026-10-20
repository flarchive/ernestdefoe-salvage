<?php

namespace Ernestdefoe\Salvage\Work;

use Carbon\Carbon;
use Ernestdefoe\Salvage\Link;
use Ernestdefoe\Salvage\Net\Fetcher;
use Ernestdefoe\Salvage\Net\Refused;
use Ernestdefoe\Salvage\Net\Response;
use Ernestdefoe\Salvage\Settings;

/**
 * Checks one link and records what it found.
 *
 * A link is only called broken when it keeps failing: a 404/410 or a domain
 * with no DNS records twice, at least a day apart; anything that might be
 * the network's fault (timeouts, refused connections, TLS, 5xx) four times
 * over about four days. Bot walls (401/403/429, LinkedIn's 999) count as
 * alive for links, because a person clicking the link gets through; not for
 * images, which readers' browsers are refused just the same.
 */
class Checker
{
    public const DEFINITE_LIMIT = 2;
    public const TRANSIENT_LIMIT = 4;
    /** Hours to wait after the 1st, 2nd and 3rd transient failure. */
    private const BACKOFF = [1, 6, 24, 72];

    public function __construct(private readonly Fetcher $fetcher, private readonly Settings $settings)
    {
    }

    /**
     * @return int requests spent
     */
    public function check(Link $link): int
    {
        $requests = 1;

        try {
            $response = $this->fetcher->fetch('HEAD', $link->url, 0);

            // Plenty of servers answer HEAD wrongly (405, 404, 403...): only a
            // GET may say a link is not fine.
            if ($response->status >= 300) {
                $requests++;
                $response = $this->fetcher->fetch('GET', $link->url, 0);
            }
        } catch (Refused $e) {
            $this->recordFailure($link, $e);

            return $requests;
        }

        $this->recordResponse($link, $response);

        return $requests;
    }

    public function recordResponse(Link $link, Response $response): void
    {
        $status = $response->status;
        $link->http_status = $status;

        if ($status < 400) {
            $this->recordAlive($link, 'ok');
        } elseif ($status === 404 || $status === 410 || ($link->kind === 'image' && $status !== 429 && $status < 500)) {
            // An image the source refuses to serve (hotlink protection, 403,
            // 400) is just as missing for readers, whose browsers are refused too.
            $this->recordDead($link, "http_$status", false);
        } elseif ($status >= 500 && $status <= 599) {
            $this->recordDead($link, "http_$status", true);
        } else {
            $this->recordAlive($link, 'blocked');
        }
    }

    public function recordFailure(Link $link, Refused $e): void
    {
        $link->http_status = null;

        if ($e->isPolicy()) {
            $link->status = 'skipped';
            $link->error = $e->reason;
            $link->checked_at = Carbon::now();
            $link->next_check_at = null;
            $link->save();

            return;
        }

        match ($e->reason) {
            'dns_none' => $this->recordDead($link, 'dns_none', false),
            'redirects' => $this->recordAlive($link, 'blocked', 'redirects'),
            default => $this->recordDead($link, $e->reason, true),
        };
    }

    private function recordAlive(Link $link, string $status, ?string $error = null): void
    {
        $days = $this->settings->int(Settings::RECHECK_DAYS, 1, 365);

        $link->status = $status;
        $link->error = $error;
        $link->fail_count = 0;
        $link->broken_at = null;
        $link->checked_at = Carbon::now();
        // A little spread, so a big backfill does not all come due on one day.
        $link->next_check_at = Carbon::now()->addDays($days)->addMinutes(random_int(0, 24 * 60));
        $link->save();
    }

    private function recordDead(Link $link, string $error, bool $transient): void
    {
        $link->error = $error;
        $link->fail_count = min(255, $link->fail_count + 1);
        $link->checked_at = Carbon::now();

        $limit = $transient ? self::TRANSIENT_LIMIT : self::DEFINITE_LIMIT;

        if ($link->fail_count >= $limit) {
            $link->status = 'broken';
            $link->broken_at ??= Carbon::now();
            // Dead links come back sometimes (an expired domain renewed).
            $link->next_check_at = Carbon::now()->addDays(7);
        } else {
            $hours = $transient ? self::BACKOFF[min($link->fail_count, count(self::BACKOFF)) - 1] : 24;
            $link->next_check_at = Carbon::now()->addHours($hours);
        }

        $link->save();
    }
}
