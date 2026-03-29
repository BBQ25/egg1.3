<?php

namespace App\Support;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Str;

class RolePageAccess
{
    /**
     * @var array<string, array<int, string>>
     */
    private const ALLOWED_PATTERNS = [
        'ADMIN' => ['*'],
        'OWNER' => [
            'guides',
            'my-farms',
            'inventory',
            'batch-monitoring',
            'egg-records',
            'production-reports',
            'validation',
            'notifications',
            'machine-blueprint',
            'app-email',
            'app-chat',
            'app-calendar',
            'app-kanban',
            'dashboards-*',
            'app-ecommerce-*',
            'app-invoice-*',
            'app-logistics-*',
            'app-user-view-*',
            'pages-account-settings-*',
            'pages-profile-*',
            'front-pages-*',
        ],
        'WORKER' => [
            'guides',
            'inventory',
            'batch-monitoring',
            'egg-records',
            'production-reports',
            'validation',
            'notifications',
            'machine-blueprint',
            'app-email',
            'app-chat',
            'app-calendar',
            'app-kanban',
            'dashboards-*',
            'app-ecommerce-dashboard',
            'app-ecommerce-order-*',
            'app-ecommerce-product-list',
            'app-ecommerce-category-list',
            'app-logistics-*',
            'app-invoice-*',
        ],
        'CUSTOMER' => [
            'guides',
            'price-monitoring',
            'app-ecommerce-dashboard',
            'app-ecommerce-product-list',
            'app-ecommerce-category-list',
            'app-ecommerce-customer-details-*',
            'front-pages-*',
        ],
    ];

    public static function canAccessPage(?Authenticatable $user, string $page): bool
    {
        $role = self::resolveRole($user);
        if ($role === null) {
            return false;
        }

        $page = strtolower(trim($page));
        if ($page === '') {
            return false;
        }

        foreach (self::candidateKeys($page) as $candidate) {
            if (self::matchesRolePatterns($role, $candidate)) {
                return true;
            }
        }

        return false;
    }

    public static function inlineScript(?Authenticatable $user = null): string
    {
        $role = self::resolveRole($user);
        if ($role === null) {
            return '';
        }

        $patterns = self::allowedPatterns($role);
        if ($patterns === [] || self::hasGlobalAccess($patterns)) {
            return '';
        }

        $patternsJson = json_encode(array_values(array_unique($patterns)), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $script = <<<'HTML'
<script>
  (function () {
    var allowedPatterns = __ALLOWED_PATTERNS__;
    if (!Array.isArray(allowedPatterns) || allowedPatterns.length === 0) return;

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
      if (raw === '' || raw === '#' || raw.toLowerCase() === 'javascript:void(0);') return '';

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

      if (pageName !== '') keys.push(pageName);

      var hrefLower = String(href || '').toLowerCase();
      if (pageName !== '' && hrefLower.indexOf('/front-pages/') !== -1) {
        keys.push('front-pages-' + pageName);
      }

      var i18nNode = link.querySelector('[data-i18n]');
      var text = i18nNode ? (i18nNode.getAttribute('data-i18n') || i18nNode.textContent || '') : (link.textContent || '');
      var textSlug = slugify(text);
      if (textSlug !== '') keys.push(textSlug);

      return Array.from(new Set(keys));
    }

    function matches(key, pattern) {
      if (pattern === '*') return true;
      var safe = String(pattern).replace(/[.+?^${}()|[\]\\]/g, '\\$&').replace(/\*/g, '.*');
      return new RegExp('^' + safe + '$').test(String(key));
    }

    function isAllowed(keys) {
      if (!Array.isArray(keys) || keys.length === 0) return true;
      return keys.some(function (key) {
        return allowedPatterns.some(function (pattern) {
          return matches(key, pattern);
        });
      });
    }

    function hideNode(node) {
      if (!node) return;
      node.style.setProperty('display', 'none', 'important');
      node.classList.add('d-none', 'role-hidden-node');
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

    function applyRoleVisibility() {
      var menuRoot = document.querySelector('.menu-inner');
      if (!menuRoot) return;

      var allItems = Array.prototype.slice.call(menuRoot.querySelectorAll('li.menu-item'));
      allItems.forEach(function (item) {
        var link = directMenuLink(item);
        if (!link) return;

        var href = String(link.getAttribute('href') || '').trim().toLowerCase();
        if (href === '' || href === '#' || href === 'javascript:void(0);') return;

        if (!isAllowed(candidateKeys(link))) {
          hideNode(item);
        }
      });

      var changed = true;
      while (changed) {
        changed = false;
        var parents = Array.prototype.slice.call(menuRoot.querySelectorAll('li.menu-item:not(.role-hidden-node)'));

        parents.forEach(function (parentItem) {
          var link = directMenuLink(parentItem);
          if (!link || !link.classList.contains('menu-toggle')) return;

          var subMenu = subMenuElement(parentItem);
          if (!subMenu) return;

          var hasVisibleChildren = Array.prototype.slice.call(subMenu.children).some(function (child) {
            return child.tagName && child.tagName.toLowerCase() === 'li' && !child.classList.contains('role-hidden-node') && child.style.display !== 'none';
          });

          if (!hasVisibleChildren) {
            hideNode(parentItem);
            changed = true;
          }
        });
      }
    }

    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', applyRoleVisibility, { once: true });
    } else {
      applyRoleVisibility();
    }

    window.addEventListener('load', applyRoleVisibility);
    setTimeout(applyRoleVisibility, 250);
  })();
</script>
HTML;
        return str_replace('__ALLOWED_PATTERNS__', $patternsJson ?: '[]', $script);
    }

    /**
     * @return array<int, string>
     */
    private static function allowedPatterns(UserRole $role): array
    {
        return self::ALLOWED_PATTERNS[$role->value] ?? [];
    }

    /**
     * @param array<int, string> $patterns
     */
    private static function hasGlobalAccess(array $patterns): bool
    {
        return in_array('*', $patterns, true);
    }

    private static function matchesRolePatterns(UserRole $role, string $key): bool
    {
        foreach (self::allowedPatterns($role) as $pattern) {
            if (Str::is($pattern, $key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    private static function candidateKeys(string $page): array
    {
        $page = strtolower(trim($page));
        if ($page === '') {
            return [];
        }

        return [$page];
    }

    private static function resolveRole(?Authenticatable $user): ?UserRole
    {
        if (!($user instanceof User)) {
            return null;
        }

        return $user->role instanceof UserRole ? $user->role : null;
    }
}
