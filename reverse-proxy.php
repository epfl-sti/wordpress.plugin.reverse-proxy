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

/* Mutate $_SERVER[] as soon as possible i.e. now (no hooks): */
if (array_key_exists("HTTP_X_FORWARDED_HOST", $_SERVER)) {
    $_SERVER["HTTP_HOST"] = $_SERVER["HTTP_X_FORWARDED_HOST"];
}
if (array_key_exists("HTTP_X_FORWARDED_PROTO", $_SERVER)) {
    if (strtolower($_SERVER["HTTP_X_FORWARDED_PROTO"]) === "https") {
        $_SERVER["HTTPS"] = 1;
    } else {
        unset($_SERVER["HTTPS"]);
    }
}

function root_url () {
    return sprintf("%s://%s",
                   ($_SERVER["HTTPS"] ? "https" : "http"),
                    $_SERVER["HTTP_HOST"]);
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
    if (parse_url($url, PHP_URL_SCHEME) ||
        parse_url($url, PHP_URL_HOST) ||
        parse_url($url, PHP_URL_PORT)) {
        $massaged = root_url() . relative_url_part ($url);
        return $massaged;
    } else {
        return $url;
    }
}

function relative_url_part ($url) {
    $retval = parse_url($url, PHP_URL_PATH);
    if (parse_url($url, PHP_URL_QUERY)) {
        $retval .= "?" . parse_url($url, PHP_URL_QUERY);
    }
    return $retval;
}

function relative_url_part_no_empty ($url) {
    $retval = relative_url_part($url);
    if (! $retval) {
        $retval = "/";
    }
    return $retval;
}

// Sometimes these URLs are sent outside e.g. to a third-party Web SSO system
// or a newsletter. In other cases, we have to keep absolute URLs in order
// to paper over various boneheaded behavior in the caller code. At least make
// them bear the correct protocol, host and port number.
foreach (['admin_url', 'home_url', 'site_url', 
          'network_home_url', 'network_site_url',
          'content_url', 'plugins_url', 'includes_url',
          'theme_root_uri'] as $filter) {
    add_filter($filter, 'EPFL\\ReverseProxy\\massage_url');
}

// In a lot of cases however, using absolute links is just asking for
// trouble so we don't:
foreach (['wp_get_attachment_url', 'pll_the_language_link'] as $filter) {
    add_filter($filter, 'EPFL\\ReverseProxy\\relative_url_part_no_empty');
}
foreach (['login_url', 'login_redirect',
          'logout_url', 'logout_redirect'] as $filter) {
    add_filter($filter, 'EPFL\\ReverseProxy\\relative_url_part');
}

// Filter urls as part of filterable data structures:
function filter_upload_dir ($uploads) {
    $uploads['baseurl'] = massage_url($uploads['baseurl']);
    return $uploads;
}

add_filter('upload_dir', 'EPFL\\ReverseProxy\\filter_upload_dir');

function filter_pll_rel_hreflang_attributes ($hreflangs) {
    $newhreflangs = array();
    foreach ($hreflangs as $lang => $url) {
        $newhreflangs[$lang] = relative_url_part($url);
    }
    return $newhreflangs;
}

add_filter('pll_rel_hreflang_attributes',
           'EPFL\\ReverseProxy\\filter_pll_rel_hreflang_attributes' );

/**
 * Redirects that only change the host part of the URL are not welcome.
 *
 * Assuming you have a reverse proxy (otherwise why are you using this
 * plug-in?), you should configure it to deal with these. Redirects
 * from one page to another (e.g. to manage "slugs", i18n etc) are
 * still honored.
 */
add_filter('redirect_canonical', function($to_url, $from_url) {
    $to_url_relative = relative_url_part($to_url);
    if ($to_url_relative === relative_url_part($from_url)) {
        return false;
    }
    return root_url() . $to_url_relative;
}, 10, 2);
