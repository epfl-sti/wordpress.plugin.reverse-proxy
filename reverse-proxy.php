<?php
/*
 * Plugin Name: Reverse Proxy
 * Description: Make WordPress Just Work(TM) from behind a reverse proxy
 * Version:     0.1
 * Author:      STI-IT Web
 * Author URI:  mailto:stiitweb@epfl.ch
 */

namespace EPFL\ReverseProxy;
use function \site_url;

if (! defined('ABSPATH')) {
    die('Access denied.');
}

/**
 * Massage an external URL into making sense upstream from the reverse proxy.
 *
 * If we are behind a reverse proxy, examine the X-Forwarded-{Proto,Host}
 * headers if present over the information in the WordPress database, and
 * use them to replace or supplement $url.
 *
 * Note that the path part is not rewritten, i.e. we do not support a
 * reverse proxy "grafting" the site at some other URL.
 */
function massage_url ($url) {
    /* We don't believe in reverse proxies that rewrite the path: */
    $keep_this_part = parse_url($url, PHP_URL_PATH);
    if (parse_url($url, PHP_URL_QUERY)) {
        $keep_this_part .= "?" . parse_url($url, PHP_URL_QUERY);
    }

    if (! (parse_url($url, PHP_URL_SCHEME) ||
           parse_url($url, PHP_URL_HOST) ||
           parse_url($url, PHP_URL_PORT)) ) {
        return $keep_this_part;
    }

    if ($_SERVER['HTTP_X_FORWARDED_PROTO']) {
        $proto = strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']);
    } elseif ($_SERVER['HTTPS']) {
        $proto = "https";
    } else {
        $proto = "http";
    }
    if ($_SERVER["HTTP_X_FORWARDED_HOST"]) {
        $host = $_SERVER["HTTP_X_FORWARDED_HOST"];
    } else {
        $host = parse_url(get_option('siteurl'), PHP_URL_HOST);
        $port = parse_url(get_option('siteurl'), PHP_URL_PORT);
        if (! (($port === 80  && $proto === "http") ||
               ($port === 443 && $proto === "https"))) {
            $host = "$host:$port";
        }
    }

    return "$proto://$host$keep_this_part";
}

foreach (['login_url', 'login_redirect',
          'home_url', 'admin_url', 'site_url',
          'logout_url', 'logout_redirect'] as $filter) {
    add_filter($filter, 'EPFL\\ReverseProxy\\massage_url');
}

add_filter('redirect_canonical',
           'EPFL\\ReverseProxy\\filter_redirect_canonical', 10, 2);
/**
 * Prevent redirection if the headers indicate we are already in the right place.
 */
function filter_redirect_canonical ($redirect_url, $requested_url)
{
    if (massage_url($redirect_url) === $redirect_url) {
        // No point in sending a 302 that will bring us right back here
        return false;
    }
    return $redirect_url;
}