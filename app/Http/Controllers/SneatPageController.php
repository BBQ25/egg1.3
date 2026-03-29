<?php

namespace App\Http\Controllers;

use App\Support\MenuVisibility;
use App\Support\RolePageAccess;
use App\Support\UiFont;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;

class SneatPageController extends Controller
{
    public function show(string $page): Response|RedirectResponse
    {
        $page = strtolower(trim($page));

        if (!preg_match('/^[a-z0-9-]+$/', $page)) {
            abort(404);
        }

        if (MenuVisibility::isDisabled($page)) {
            return redirect()->route('dashboard');
        }

        if (!RolePageAccess::canAccessPage(request()->user(), $page)) {
            return redirect()->route('dashboard');
        }

        $filePath = $this->resolvePageFilePath($page);

        if ($filePath === null) {
            abort(404);
        }

        $content = file_get_contents($filePath);

        if ($content === false) {
            abort(500);
        }

        $appBasePath = trim((string) config('app.base_path', ''), '/');
        $appBaseUrlPath = $appBasePath === '' ? '' : '/' . $appBasePath;
        $sneatBase = $appBaseUrlPath . '/sneat';
        $sneatAssetsBase = $sneatBase . '/assets';
        $templateBase = $appBaseUrlPath . '/sneat/html/vertical-menu-template/';

        if (str_contains($content, '<head>')) {
            $content = str_replace('<head>', "<head>\n    <base href=\"{$templateBase}\" />", $content);
        }

        $content = $this->injectSharedSidebar($content, $sneatAssetsBase);

        $content = preg_replace_callback(
            '/\b(href|action)=("|\')([a-z0-9-]+)\.(?:html|php)([^"\']*)\2/i',
            static function (array $matches) use ($appBaseUrlPath): string {
                $target = strtolower($matches[3]);
                $suffix = $matches[4] ?? '';
                $cleanUrl = ($appBaseUrlPath === '' ? '' : $appBaseUrlPath) . '/' . $target . $suffix;

                return $matches[1] . '=' . $matches[2] . $cleanUrl . $matches[2];
            },
            $content
        ) ?? $content;

        $content = preg_replace('/<link[^>]+href=("|\')[^"\']*\/fonts\/(figtree|poppins|public-sans)\.css[^"\']*\1[^>]*>\s*/i', '', $content) ?? $content;

        $resolvedFont = UiFont::current();
        $fontCssFile = UiFont::cssFile($resolvedFont);
        $fontHref = $fontCssFile ? ($appBaseUrlPath . '/sneat/fonts/' . $fontCssFile) : null;
        $fontInlineCss = UiFont::inlineCss($resolvedFont);
        $fontAwesomeHref = ($appBaseUrlPath === '' ? '' : $appBaseUrlPath) . '/vendor/fontawesome/css/all.min.css';

        $fontAwesomeMarkup = '';
        if (!str_contains($content, 'fontawesome.css') && !str_contains($content, '/vendor/fontawesome/css/all.min.css')) {
            $fontAwesomeMarkup = "<link rel=\"stylesheet\" href=\"{$fontAwesomeHref}\" />\n";
        }

        $fontMarkup = $fontHref
            ? $fontAwesomeMarkup . "<link rel=\"stylesheet\" href=\"{$fontHref}\" />\n<style id=\"ui-font-style\">{$fontInlineCss}</style>"
            : $fontAwesomeMarkup . "<style id=\"ui-font-style\">{$fontInlineCss}</style>";

        if (str_contains($content, '</head>')) {
            $content = str_replace('</head>', $fontMarkup . "\n</head>", $content);
        }

        $logoutAction = route('logout');
        $csrfToken = csrf_token();
        $logoutMarkup = <<<HTML
<form id="global-sneat-logout-form" method="POST" action="{$logoutAction}" style="display:none;">
  <input type="hidden" name="_token" value="{$csrfToken}" />
</form>
<script>
document.addEventListener('click', function (event) {
  var link = event.target.closest('a[href]');
  if (!link) return;

  var href = (link.getAttribute('href') || '').toLowerCase();
  var normalized = href.split('?')[0].split('#')[0];

  var isLogoutLink =
    normalized === 'auth-login-cover.html' ||
    normalized === 'auth-login-cover.php' ||
    normalized.endsWith('/auth-login-cover') ||
    normalized.endsWith('/auth-login-cover.html') ||
    normalized.endsWith('/auth-login-cover.php');

  if (!isLogoutLink) return;

  event.preventDefault();
  var form = document.getElementById('global-sneat-logout-form');
  if (form) form.submit();
});
</script>
HTML;

        $menuScript = MenuVisibility::inlineScript();
        $roleScript = RolePageAccess::inlineScript(request()->user());

        if (str_contains($content, '</body>')) {
            $content = str_replace('</body>', $logoutMarkup . "\n" . $menuScript . "\n" . $roleScript . "\n</body>", $content);
        }

        return response($content, 200)->header('Content-Type', 'text/html; charset=UTF-8');
    }

    private function resolvePageFilePath(string $page): ?string
    {
        $formsPath = public_path("forms/{$page}.php");
        $sneatPath = public_path("sneat/html/vertical-menu-template/{$page}.php");

        if ((str_starts_with($page, 'form-') || str_starts_with($page, 'forms-')) && is_file($formsPath)) {
            return $formsPath;
        }

        if (is_file($sneatPath)) {
            return $sneatPath;
        }

        if (is_file($formsPath)) {
            return $formsPath;
        }

        return null;
    }

    private function injectSharedSidebar(string $content, string $sneatAssetsBase): string
    {
        $sharedSidebar = trim((string) view('partials.sidebar', [
            'sneatAssetsBase' => $sneatAssetsBase,
        ])->render());

        if ($sharedSidebar === '') {
            return $content;
        }

        $replaced = preg_replace('/<aside\s+id="layout-menu"[^>]*>.*?<\/aside>/is', $sharedSidebar, $content, 1);

        return $replaced ?? $content;
    }

    public function legacy(string $page): RedirectResponse
    {
        $page = strtolower(trim($page));

        if (!preg_match('/^[a-z0-9-]+$/', $page)) {
            abort(404);
        }

        if (MenuVisibility::isDisabled($page)) {
            return redirect()->route('dashboard');
        }

        if (!RolePageAccess::canAccessPage(request()->user(), $page)) {
            return redirect()->route('dashboard');
        }

        $appBasePath = trim((string) config('app.base_path', ''), '/');
        $prefix = $appBasePath === '' ? '' : '/' . $appBasePath;

        return redirect()->to($prefix . '/' . ltrim($page, '/'), 301);
    }
}
