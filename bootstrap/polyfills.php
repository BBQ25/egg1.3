<?php

if (! function_exists('mb_split')) {
    function mb_split(string $pattern, string $string, int $limit = -1): array|false
    {
        $delimiter = '/';
        $escapedPattern = str_replace($delimiter, '\\'.$delimiter, $pattern);
        $result = preg_split($delimiter.$escapedPattern.$delimiter.'u', $string, $limit < 0 ? -1 : $limit);

        return $result === false ? false : $result;
    }
}

if (! function_exists('mb_strimwidth')) {
    function mb_strimwidth(string $string, int $start, int $width, string $trimMarker = '', ?string $encoding = null): string
    {
        $encoding = $encoding ?: 'UTF-8';
        $segment = mb_substr($string, $start, null, $encoding);
        $trimWidth = $trimMarker !== '' ? mb_strwidth($trimMarker, $encoding) : 0;
        $targetWidth = max(0, $width - $trimWidth);

        if (mb_strwidth($segment, $encoding) <= $width) {
            return $segment;
        }

        $result = '';
        $segmentLength = mb_strlen($segment, $encoding);

        for ($index = 0; $index < $segmentLength; $index++) {
            $character = mb_substr($segment, $index, 1, $encoding);
            $next = $result.$character;

            if (mb_strwidth($next, $encoding) > $targetWidth) {
                break;
            }

            $result = $next;
        }

        return $result.$trimMarker;
    }
}
