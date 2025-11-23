<?php

if (!function_exists('str_contains')) {
    /**
     * Polyfill for PHP 8 str_contains() for PHP 7.4.
     */
    function str_contains(string $haystack, string $needle): bool
    {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}
