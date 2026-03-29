@php
  $src = (string) ($src ?? '');
  $alt = (string) ($alt ?? '');
  $classes = trim((string) ($classes ?? ''));
  $decorative = (bool) ($decorative ?? true);
  $resolvedSrc = '';
  $resolveInlineMimeType = static function (string $absolutePath): string {
      if (function_exists('mime_content_type')) {
          $detectedMimeType = @mime_content_type($absolutePath);

          if (is_string($detectedMimeType) && $detectedMimeType !== '') {
              return $detectedMimeType;
          }
      }

      return match (strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION))) {
          'gif' => 'image/gif',
          'jpg', 'jpeg' => 'image/jpeg',
          'svg' => 'image/svg+xml',
          'webp' => 'image/webp',
          default => 'image/png',
      };
  };

  if ($src !== '') {
      $normalizedSrc = str_replace('\\', '/', $src);

      if (str_starts_with($normalizedSrc, 'resources/')) {
          $absolutePath = resource_path(substr($normalizedSrc, strlen('resources/')));

          if (is_file($absolutePath) && is_readable($absolutePath)) {
              $mimeType = $resolveInlineMimeType($absolutePath);
              $resolvedSrc = 'data:' . $mimeType . ';base64,' . base64_encode(file_get_contents($absolutePath));
          }
      } else {
          $resolvedSrc = asset($src);
      }
  }
@endphp

@if ($resolvedSrc !== '')
  <img
    src="{{ $resolvedSrc }}"
    @if ($decorative)
      alt=""
      aria-hidden="true"
    @else
      alt="{{ $alt }}"
    @endif
    class="app-shell-icon {{ $classes }}"
  />
@endif
