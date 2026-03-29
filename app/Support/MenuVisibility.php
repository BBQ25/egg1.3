<?php

namespace App\Support;

use App\Models\AppSetting;
use Throwable;

class MenuVisibility
{
    public const SETTING_KEY = 'disabled_pages';

    private static ?array $cachedDisabled = null;

    /**
     * @return array<string>
     */
    public static function getDisabled(): array
    {
        if (self::$cachedDisabled !== null) {
            return self::$cachedDisabled;
        }

        try {
            $value = AppSetting::query()
                ->where('setting_key', self::SETTING_KEY)
                ->value('setting_value');

            $decoded = json_decode($value ?? '[]', true);
            self::$cachedDisabled = is_array($decoded) ? $decoded : [];
        } catch (Throwable) {
            self::$cachedDisabled = [];
        }

        return self::$cachedDisabled;
    }

    /**
     * @param array<string> $pages
     */
    public static function setDisabled(array $pages): void
    {
        // Sanitize and ensure unique page names
        $cleanPages = array_values(array_unique(array_filter(array_map('trim', $pages))));

        AppSetting::query()->updateOrCreate(
            ['setting_key' => self::SETTING_KEY],
            ['setting_value' => json_encode($cleanPages)]
        );

        self::$cachedDisabled = $cleanPages;
    }

    public static function isDisabled(string $page): bool
    {
        $normalized = strtolower(trim($page));
        $disabled = self::getDisabled();

        if (in_array($normalized, $disabled, true)) {
            return true;
        }

        // Allow virtual Front Pages keys (e.g. front-pages-landing-page) to protect matching clean slugs.
        return in_array('front-pages-' . $normalized, $disabled, true);
    }

    public static function inlineScript(): string
    {
        $disabledPages = self::getDisabled();

        if (empty($disabledPages)) {
            return '';
        }

        $normalizedPages = array_values(array_unique(array_filter(array_map(
            static fn ($page): string => strtolower(trim((string) $page)),
            $disabledPages
        ))));
        $jsonDisabled = json_encode($normalizedPages, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        return <<<HTML
<script>
  (function () {
    var disabledPages = {$jsonDisabled};

    if (!Array.isArray(disabledPages) || disabledPages.length === 0) {
      return;
    }

    disabledPages = disabledPages
      .map(function (page) {
        return String(page || '').toLowerCase().trim();
      })
      .filter(function (page) {
        return page.length > 0;
      });

    if (disabledPages.length === 0) {
      return;
    }

    function hideNode(node) {
      if (!node) return;
      node.style.setProperty('display', 'none', 'important');
      node.classList.add('d-none', 'visibility-hidden-node');
    }

    function slugify(value) {
      return String(value || '')
        .toLowerCase()
        .trim()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/(^-|-$)/g, '');
    }

    function normalizePage(href) {
      if (!href) return '';

      var raw = String(href).trim();
      if (raw === '' || raw === '#' || raw.toLowerCase() === 'javascript:void(0);') {
        return '';
      }

      var pathname = raw;
      try {
        pathname = new URL(raw, window.location.origin).pathname;
      } catch (err) {
        pathname = raw.split('?')[0].split('#')[0];
      }

      var lastSegment = pathname.split('/').filter(function (part) {
        return part !== '';
      }).pop() || '';

      return lastSegment.replace(/\.(html|php)$/i, '').toLowerCase();
    }

    function candidateKeys(link) {
      if (!link) return [];

      var href = link.getAttribute('href') || '';
      var pageName = normalizePage(href);
      var keys = [];

      if (pageName !== '') {
        keys.push(pageName);
      }

      var hrefLower = String(href || '').toLowerCase();
      if (pageName !== '' && hrefLower.indexOf('/front-pages/') !== -1) {
        keys.push('front-pages-' + pageName);
      }

      var i18nNode = link.querySelector('[data-i18n]');
      var i18nText = i18nNode ? (i18nNode.getAttribute('data-i18n') || i18nNode.textContent || '') : '';
      var linkText = i18nText !== '' ? i18nText : (link.textContent || '');
      var textSlug = slugify(linkText);
      if (textSlug !== '') {
        keys.push(textSlug);
      }

      return Array.from(new Set(keys));
    }

    function directMenuLink(menuItem) {
      if (!menuItem) return null;

      for (var i = 0; i < menuItem.children.length; i++) {
        var child = menuItem.children[i];
        if (child.tagName && child.tagName.toLowerCase() === 'a' && child.classList.contains('menu-link')) {
          return child;
        }
      }

      return null;
    }

    function subMenuElement(menuItem) {
      if (!menuItem) return null;

      for (var i = 0; i < menuItem.children.length; i++) {
        var child = menuItem.children[i];
        if (child.tagName && child.tagName.toLowerCase() === 'ul' && child.classList.contains('menu-sub')) {
          return child;
        }
      }

      return null;
    }

    function applyVisibility() {
      var menuRoot = document.querySelector('.menu-inner');
      if (!menuRoot) return;

      var allItems = Array.prototype.slice.call(menuRoot.querySelectorAll('li.menu-item'));

      // First pass: hide pages directly mapped to disabled page keys.
      allItems.forEach(function (item) {
        var link = directMenuLink(item);
        if (!link) return;

        var keys = candidateKeys(link);
        var isHidden = keys.some(function (key) {
          return disabledPages.indexOf(key) !== -1;
        });

        if (isHidden) {
          hideNode(item);
        }
      });

      // Second pass: recursively hide empty parent folders.
      var changed = true;
      while (changed) {
        changed = false;
        var parentItems = Array.prototype.slice.call(menuRoot.querySelectorAll('li.menu-item:not(.visibility-hidden-node)'));

        parentItems.forEach(function (parentItem) {
          var link = directMenuLink(parentItem);
          if (!link || !link.classList.contains('menu-toggle')) return;

          var subMenu = subMenuElement(parentItem);
          if (!subMenu) return;

          var hasVisibleChildren = Array.prototype.slice.call(subMenu.children).some(function (child) {
            return child.tagName && child.tagName.toLowerCase() === 'li' && !child.classList.contains('visibility-hidden-node');
          });

          if (!hasVisibleChildren) {
            hideNode(parentItem);
            changed = true;
          }
        });
      }

      // Third pass: hide headers that no longer have visible items beneath them.
      var headers = Array.prototype.slice.call(menuRoot.querySelectorAll('li.menu-header:not(.visibility-hidden-node)'));
      headers.forEach(function (header) {
        var nextNode = header.nextElementSibling;
        var hasVisibleSibling = false;

        while (nextNode && !nextNode.classList.contains('menu-header')) {
          if (nextNode.tagName && nextNode.tagName.toLowerCase() === 'li' && !nextNode.classList.contains('visibility-hidden-node')) {
            hasVisibleSibling = true;
            break;
          }
          nextNode = nextNode.nextElementSibling;
        }

        if (!hasVisibleSibling) {
          hideNode(header);
        }
      });
    }

    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', applyVisibility, { once: true });
    } else {
      applyVisibility();
    }

    window.addEventListener('load', applyVisibility);
    setTimeout(applyVisibility, 250);
  })();
</script>
HTML;
    }
}
