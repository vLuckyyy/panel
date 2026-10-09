<?php

namespace App\Services\Helpers;

use Closure;
use Exception;
use Illuminate\Support\Facades\Http;

class SoftwareVersionService
{
    public function latestPanelVersionChangelog(): ?string
    {
        return $this->rememberLatestRelease('panel:latest_version_changelog', 'pelican/panel', fn (array $release) => $release['body']);
    }

    public function latestPanelVersion(): ?string
    {
        return $this->rememberLatestRelease('panel:latest_version', 'pelican/panel', fn (array $release) => trim($release['tag_name'], 'v'));
    }

    public function latestWingsVersion(): ?string
    {
        return $this->rememberLatestRelease('wings:latest_version', 'pelican/wings', fn (array $release) => trim($release['tag_name'], 'v'));
    }

    public function isLatestPanel(): bool
    {
        $current = $this->currentComparableVersion();
        $latest = $this->latestPanelVersion();

        return $current === null || $latest === null || version_compare($current, $latest, '>=');
    }

    public function isLatestWings(string $version): bool
    {
        $latest = $this->latestWingsVersion();

        return $version === 'develop' || $latest === null || version_compare($version, $latest, '>=');
    }

    /**
     * The version for display purposes, e.g. "1.2.3" or "canary (0a1b2c3)". Never use this for comparisons.
     */
    public function currentPanelVersion(): string
    {
        return cache()->remember('panel:current_version', now()->addMinutes(5), function () {
            $version = config('app.version');

            if ($version !== 'canary') {
                return $version;
            }

            if (file_exists(base_path('.git/HEAD'))) {
                $head = explode(' ', file_get_contents(base_path('.git/HEAD')));

                if (array_key_exists(1, $head)) {
                    $path = base_path('.git/' . trim($head[1]));

                    if (file_exists($path)) {
                        return 'canary (' . substr(file_get_contents($path), 0, 7) . ')';
                    }
                }
            }

            return $version;
        });
    }

    /**
     * The comparable semver of this installation, or null when running canary.
     */
    public function currentComparableVersion(): ?string
    {
        $version = config('app.version');

        return $version === 'canary' ? null : $version;
    }

    /**
     * Failed lookups are cached as well, but only briefly, so an unreachable or rate limited
     * GitHub API is retried soon without being queried again on every single call.
     *
     * @param  Closure(array<string, mixed>): ?string  $extract
     */
    private function rememberLatestRelease(string $key, string $repository, Closure $extract): ?string
    {
        $value = cache()->get($key);

        if (is_null($value)) {
            try {
                $release = Http::timeout(5)->connectTimeout(1)->get("https://api.github.com/repos/$repository/releases/latest")->throw()->json();

                $value = $extract($release);

                cache()->put($key, $value, now()->addMinutes(config('panel.cdn.cache_time', 60)));
            } catch (Exception) {
                $value = 'error';

                cache()->put($key, $value, now()->addMinutes(5));
            }
        }

        return $value === 'error' ? null : $value;
    }
}
