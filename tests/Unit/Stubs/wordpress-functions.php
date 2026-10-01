<?php

/**
 * Minimal stand-ins for the WordPress option and filter functions.
 *
 * Unit tests run without WordPress, so the code paths behind
 * `function_exists('get_option')` and `function_exists('apply_filters')` are
 * otherwise never taken. Defining these is global and permanent for the
 * process, so load this file only from tests that run in a separate process.
 *
 * Tests set `$GLOBALS['wp_test_options']` (option name => value) and
 * `$GLOBALS['wp_test_filters']` (hook name => callable).
 */

declare(strict_types=1);

if (!function_exists('get_option')) {
    /**
     * @param string $option
     * @param mixed  $default
     * @return mixed
     */
    function get_option(string $option, $default = false)
    {
        return $GLOBALS['wp_test_options'][$option] ?? $default;
    }
}

if (!function_exists('apply_filters')) {
    /**
     * @param string $hookName
     * @param mixed  $value
     * @param mixed  ...$args
     * @return mixed
     */
    function apply_filters(string $hookName, $value, ...$args)
    {
        if (!isset($GLOBALS['wp_test_filters'][$hookName])) {
            return $value;
        }

        return $GLOBALS['wp_test_filters'][$hookName]($value, ...$args);
    }
}
