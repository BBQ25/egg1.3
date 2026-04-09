<?php

namespace App\Providers;

use App\Contracts\DeployTriggerRunner;
use App\Support\AppTimezone;
use App\Support\FileDeployTriggerRunner;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use App\Support\UiFont;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Pagination\Paginator;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(DeployTriggerRunner::class, FileDeployTriggerRunner::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Paginator::useBootstrapFive();
        AppTimezone::clearCache();
        AppTimezone::activate();

        RateLimiter::for('device-ingest', static function (Request $request): array {
            $serial = strtoupper(trim((string) $request->header('X-Device-Serial', 'unknown')));

            return [
                Limit::perMinute(180)->by('device-ingest-ip:' . (string) $request->ip()),
                Limit::perMinute(120)->by('device-ingest-serial:' . $serial . '|' . (string) $request->ip()),
            ];
        });

        RateLimiter::for('device-runtime-config', static function (Request $request): array {
            $serial = strtoupper(trim((string) $request->header('X-Device-Serial', 'unknown')));

            return [
                Limit::perMinute(120)->by('device-runtime-config-ip:' . (string) $request->ip()),
                Limit::perMinute(90)->by('device-runtime-config-serial:' . $serial . '|' . (string) $request->ip()),
            ];
        });

        $appUrl = (string) config('app.url');
        $configuredBasePath = trim((string) config('app.base_path', ''), '/');

        if ($appUrl !== '') {
            $scheme = (string) parse_url($appUrl, PHP_URL_SCHEME);
            $pathPrefix = $configuredBasePath === '' ? '' : '/' . $configuredBasePath;
            if ($scheme !== '') {
                URL::forceScheme($scheme);
            }

            $configuredHost = strtolower((string) parse_url($appUrl, PHP_URL_HOST));
            $configuredPort = (int) (parse_url($appUrl, PHP_URL_PORT) ?: ($scheme === 'https' ? 443 : 80));
            $configuredRootUrl = $this->formatRootUrl($scheme, $configuredHost, $configuredPort, $pathPrefix);

            if ($this->app->runningInConsole()) {
                if ($configuredRootUrl !== null) {
                    URL::forceRootUrl($configuredRootUrl);
                }
            } else {
                $requestHost = strtolower((string) request()->getHost());
                $requestPort = (int) request()->getPort();

                // Preserve current request host/port (for emulator reverse ports, proxy ports, etc.)
                // unless it exactly matches the configured APP_URL endpoint.
                if ($requestHost !== '' && $requestHost === $configuredHost && $requestPort === $configuredPort) {
                    if ($configuredRootUrl !== null) {
                        URL::forceRootUrl($configuredRootUrl);
                    }
                } elseif ($requestHost !== '') {
                    $requestRootUrl = $this->formatRootUrl(
                        (string) request()->getScheme(),
                        $requestHost,
                        $requestPort,
                        $pathPrefix
                    );

                    if ($requestRootUrl !== null) {
                        URL::forceRootUrl($requestRootUrl);
                    }
                }
            }
        }

        View::share('uiFontStyle', UiFont::current());
        View::composer('*', static function ($view): void {
            $timezone = AppTimezone::activate();

            $view->with('appTimezoneCode', $timezone);
            $view->with('appTimezoneLabel', AppTimezone::label($timezone));
        });
    }

    private function formatRootUrl(string $scheme, string $host, int $port, string $pathPrefix): ?string
    {
        if ($scheme === '' || $host === '') {
            return null;
        }

        $defaultPort = $scheme === 'https' ? 443 : 80;
        $portSegment = $port > 0 && $port !== $defaultPort ? ':' . $port : '';

        return $scheme . '://' . $host . $portSegment . $pathPrefix;
    }
}
