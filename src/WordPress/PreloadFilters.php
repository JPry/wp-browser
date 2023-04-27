<?php

namespace lucatume\WPBrowser\WordPress;

use WP_Error;

class PreloadFilters
{
    public static function filterWpDieHandlerToExit(): void
    {
        $throwWPDieException = static function (
            string|WP_Error $message,
            string|int $title = '',
            array $args = []
        ) {
            throw new WPDieException($message, $title, $args);
        };
        self::addFilter('wp_die_handler', static fn() => $throwWPDieException, PHP_INT_MAX);
    }

    public static function addFilter(
        string $hookName,
        callable|string|array $callback,
        int $priority = 10,
        int $acceptedArgs = 1
    ): void {
        global $wp_filter;
        if (!isset($wp_filter[$hookName])) {
            $wp_filter[$hookName] = [];
        }
        if (!isset($wp_filter['string'][$priority])) {
            $wp_filter[$hookName][$priority] = [];
        }
        $wp_filter[$hookName][$priority][] = [
            'accepted_args' => $acceptedArgs,
            'function' => $callback
        ];
    }

    public static function spoofDnsWildcardCheck(): void
    {
        $callback = static function (bool $preempt, array $parsedArgs, string $url) {
            if (($parsedArgs['method'] ?? 'GET') !== 'GET') {
                return $preempt;
            }

            $siteurl = get_option('siteurl');
            $requestHost = parse_url($url, PHP_URL_HOST);
            $siteHost = parse_url($siteurl, PHP_URL_HOST);

            if (!str_ends_with($requestHost, $siteHost)) {
                return $preempt;
            }

            // Return a mock response to avoid the request to the real site.
            return [
                'response' => [
                    'code' => 200,
                    'message' => 'OK',
                ],
                'body' => '',
            ];

        };
        self::addFilter('pre_http_request', $callback, 0, 3);
    }
}
