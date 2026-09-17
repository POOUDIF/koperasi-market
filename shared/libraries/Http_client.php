<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Klien HTTP server-ke-server (curl) untuk komunikasi antar layanan:
 * BFF → IdP (token endpoint), market → API internal koperasi, webhook.
 *
 * Melempar RuntimeException HANYA untuk kegagalan transport (DNS, timeout,
 * TLS). Status 4xx/5xx dikembalikan apa adanya — pemanggil yang memutuskan.
 */
class Http_client {

    /**
     * @param array $opts headers[], form[], json[], raw, timeout, basic_auth[user, pass]
     * @return array ['status' => int, 'body' => string, 'json' => array|null, 'headers' => array]
     */
    public function request($method, $url, array $opts = array()) {
        $headers = isset($opts['headers']) ? $opts['headers'] : array();
        $body    = NULL;

        if (isset($opts['form'])) {
            $body = http_build_query($opts['form'], '', '&', PHP_QUERY_RFC3986);
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        } elseif (isset($opts['json'])) {
            $body = json_encode($opts['json'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $headers[] = 'Content-Type: application/json';
        } elseif (isset($opts['raw'])) {
            $body = (string) $opts['raw'];
        }
        $headers[] = 'Accept: application/json';
        $headers[] = 'User-Agent: jdc-' . (defined('JDC_SERVICE') ? JDC_SERVICE : 'service') . '/1.0';

        $ch = curl_init($url);
        $resp_headers = array();
        curl_setopt_array($ch, array(
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_RETURNTRANSFER => TRUE,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => isset($opts['timeout']) ? (int) $opts['timeout'] : 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => FALSE,
            CURLOPT_SSL_VERIFYPEER => TRUE,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$resp_headers) {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $resp_headers[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
        ));
        if ($body !== NULL) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        if (isset($opts['basic_auth'])) {
            // RFC 6749 §2.3.1: id & secret di-form-urlencode dulu sebelum Basic.
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            curl_setopt($ch, CURLOPT_USERPWD,
                rawurlencode($opts['basic_auth'][0]) . ':' . rawurlencode($opts['basic_auth'][1]));
        }

        $out = curl_exec($ch);
        if ($out === FALSE) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException("HTTP {$method} {$url} gagal: {$err}");
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $json = json_decode($out, TRUE);

        return array(
            'status'  => $status,
            'body'    => $out,
            'json'    => is_array($json) ? $json : NULL,
            'headers' => $resp_headers,
        );
    }
}
