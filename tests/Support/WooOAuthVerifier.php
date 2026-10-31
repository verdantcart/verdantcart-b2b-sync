<?php

namespace Tests\Support;

/**
 * Server-side OAuth 1.0a check, ported line-for-line from WooCommerce's
 * WC_REST_Authentication::check_oauth_signature(), join_with_equals_sign(),
 * normalize_parameters() and wc_rest_urlencode_rfc3986() (woocommerce/trunk).
 *
 * Used to prove our client's signatures are accepted by WooCommerce itself.
 */
class WooOAuthVerifier
{
    public static function accepts(string $method, string $fullUrl, string $consumerSecret): bool
    {
        $parts = parse_url($fullUrl);
        parse_str($parts['query'] ?? '', $params); // what PHP puts in $_GET on the WordPress side

        $baseRequestUri = rawurlencode($parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '').$parts['path']);

        $consumerSignature = rawurldecode(str_replace(' ', '+', $params['oauth_signature']));
        unset($params['oauth_signature']);

        uksort($params, 'strcmp');

        $params = self::normalizeParameters($params);
        $queryString = implode('%26', self::joinWithEqualsSign($params));
        $stringToSign = strtoupper($method).'&'.$baseRequestUri.'&'.$queryString;

        if (! in_array($params['oauth_signature_method'], ['HMAC-SHA1', 'HMAC-SHA256'], true)) {
            return false;
        }

        $hashAlgorithm = strtolower(str_replace('HMAC-', '', $params['oauth_signature_method']));
        $signature = base64_encode(hash_hmac($hashAlgorithm, $stringToSign, $consumerSecret.'&', true));

        return hash_equals($signature, $consumerSignature);
    }

    private static function joinWithEqualsSign($params, $queryParams = [], $key = '')
    {
        foreach ($params as $paramKey => $paramValue) {
            if ($key) {
                $paramKey = $key.'%5B'.$paramKey.'%5D';
            }
            if (is_array($paramValue)) {
                $queryParams = self::joinWithEqualsSign($paramValue, $queryParams, $paramKey);
            } else {
                $string = $paramKey.'='.$paramValue;
                $queryParams[] = self::urlencodeRfc3986($string);
            }
        }

        return $queryParams;
    }

    private static function normalizeParameters($parameters)
    {
        $keys = self::urlencodeRfc3986(array_keys($parameters));
        $values = self::urlencodeRfc3986(array_values($parameters));

        return array_combine($keys, $values);
    }

    private static function urlencodeRfc3986($value)
    {
        if (is_array($value)) {
            return array_map([self::class, 'urlencodeRfc3986'], $value);
        }

        return str_replace(['+', '%7E'], [' ', '~'], rawurlencode($value));
    }
}
