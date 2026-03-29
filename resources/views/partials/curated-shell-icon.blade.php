@php
  $src = (string) ($src ?? '');
  $alt = (string) ($alt ?? '');
  $classes = trim((string) ($classes ?? ''));
  $decorative = (bool) ($decorative ?? true);
  $resolvedSrc = '';

  if ($src !== '') {
      $normalizedSrc = str_replace('\\', '/', $src);

      if (str_starts_with($normalizedSrc, 'resources/')) {
          $absolutePath = resource_path(substr($normalizedSrc, strlen('resources/')));

          if (is_file($absolutePath) && is_readable($absolutePath)) {
              $mimeType = mime_content_type($absolutePath) ?: 'image/png';
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
