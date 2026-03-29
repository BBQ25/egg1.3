@php
    $resolvedUiFont = \App\Support\UiFont::resolve($uiFontStyle ?? null);
    $uiFontCssFile = \App\Support\UiFont::cssFile($resolvedUiFont);
@endphp

@if ($uiFontCssFile)
  <link rel="stylesheet" href="{{ $sneatFontsBase }}/{{ $uiFontCssFile }}" />
@endif
<style id="ui-font-style">
  {!! \App\Support\UiFont::inlineCss($resolvedUiFont) !!}
</style>

