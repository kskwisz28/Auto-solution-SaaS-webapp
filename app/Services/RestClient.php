<?php

namespace App\Services;

use App\Exceptions\RestClientException;

/**
 * PHP REST Client build with cURL
 *
 * @author Fabio Agostinho Boris <fabioboris@gmail.com>
 */
class RestClient
{
    public  $host;        // the url to the rest server
    public  $port;
    public  $scheme;
    public  $post_type          = 'json';
    public  $timeout            = 60;
    public  $connection_timeout = 10;
    public  $last_url           = '';       // Auth token
    public  $last_response      = null;
    private $token;
    private $ba_user;
    private $ba_password;

    public function __construct($host, $token = null, $ba_user = null, $ba_password = null)
    {
        $arr_h = parse_url($host);
        if (isset($arr_h['port'])) {
            $this->port = (int)$arr_h['port'];
            $this->host = str_replace(":" . $this->port, "", $host);
        } else {
            $this->port = null;
            $this->host = $host;
        }
        if (isset($arr_h['scheme'])) {
            $this->scheme = $arr_h['scheme'];
        }
        $this->token       = $token;
        $this->ba_user     = $ba_user;
        $this->ba_password = $ba_password;
    }

    /**
     * Make an HTTP GET request
     *
     * @param string $url
     * @param array  $params
     */
    public function get($url, $params = [])
    {
        return $this->request('GET', $url, $params);
    }

    /**
     * Make an HTTP request using cURL
     *
     * @param string $verb
     * @param string $url
     * @param array  $params
     */
    private function request($verb, $url, $params = [])
    {
        $ch              = curl_init();       // the cURL handler
        $url             = $this->url($url); // the absolute URL
        $request_headers = [];
        if (!empty($this->token)) {
            $request_headers[] = "Authorization: {$this->token}";
        }

        if ((!empty($this->ba_user)) and (!empty($this->ba_password))) {
            curl_setopt($ch, CURLOPT_USERPWD, $this->ba_user . ":" . $this->ba_password);
        }

        // encoded query string on GET
        switch (true) {
            case 'GET' == $verb:
                $url            = $this->urlQueryString($url, $params);
                $this->last_url = $url;
                break;
            case in_array($verb, ['POST', 'PUT', 'DELETE']):
                if ($this->post_type == 'json') {
                    $request_headers[] = 'Content-Type: application/json';
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
                } else {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
                }
        }

        // set the URL
        curl_setopt($ch, CURLOPT_URL, $url);

        // set the HTTP verb for the request
        switch ($verb) {
            case 'POST':
                curl_setopt($ch, CURLOPT_POST, true);
                break;
            case 'PUT':
            case 'DELETE':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $verb);
        }

        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $request_headers);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->connection_timeout);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_ENCODING, 'gzip');

        if (!empty($this->port)) {
            curl_setopt($ch, CURLOPT_PORT, $this->port);
        }
        if ((!empty($this->scheme)) and ($this->scheme == 'https')) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        } else {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        }

        $response = curl_exec($ch);

        $header_size   = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $headers       = http_parse_headers(substr($response, 0, $header_size));
        $response      = substr($response, $header_size);
        $http_code     = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $content_type  = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $content_error = curl_error($ch);
        //var_dump($content_error);

        curl_close($ch);

        if (strpos($content_type, 'json'))
            $response = json_decode($response, true);

        $this->last_response = $response;

        switch (true) {
            case 'GET' == $verb:
                if ($http_code !== 200) {
                    if (is_array($response)) {
                        $this->throw_error($response, $http_code);
                    } else {
                        $this->throw_error(trim($content_error . "\n" . $response), $http_code);
                    }
                }
                return $response;
            case in_array($verb, ['POST', 'PUT', 'DELETE']):
                if (($http_code !== 303) and ($http_code !== 200)) {
                    if (is_array($response)) {
                        $this->throw_error($response, $http_code);
                    } else {
                        $this->throw_error(trim($content_error . "\n" . $response), $http_code);
                    }
                }
                if ($http_code === 200) {
                    return $response;
                }

                return str_replace(rtrim($this->host, '/') . '/', '', $headers['Location']);
        }
    }

    /**
     * Returns the absolute URL
     *
     * @param string $url
     */
    private function url($url = null)
    {
        $_host = rtrim($this->host, '/');
        $_url  = ltrim($url, '/');

        return "{$_host}:{$this->port}/{$_url}";
    }

    /**
     * Returns the URL with encoded query string params
     *
     * @param string $url
     * @param array  $params
     */
    private function urlQueryString($url, $params = null)
    {
        $qs = [];
        if ($params) {
            foreach ($params as $key => $value) {
                $qs[] = "{$key}=" . urlencode($value);
            }
        }

        $url = explode('?', $url);
        if (isset($url[1]))
            $url_qs = $url[1];
        $url = $url[0];
        if (isset($url_qs))
            $url = "{$url}?{$url_qs}";

        if (count($qs))
            return "{$url}?" . implode('&', $qs);
        else return $url;
    }

    private function throw_error($response, $http_code)
    {
        if (is_array($response) && array_key_exists('error', $response)) {
            if ((isset($response['error']['message'])) and (isset($response['error']['code']))) {
                if (is_array($response['error']['message'])) {
                    throw new RestClientException(implode("; ", $response['error']['message']), (int)$response['error']['code'], $http_code);
                } else {
                    throw new RestClientException($response['error']['message'], (int)$response['error']['code'], $http_code);
                }
            } else {
                throw new RestClientException(implode("; ", $response), 0, $http_code);
            }
        } else {
            if (is_string($response)) {
                throw new RestClientException($response, 0, $http_code);
            } else {
                throw new RestClientException(json_encode($response), 0, $http_code);
            }
        }
    }

    /**
     * Make an HTTP POST request
     *
     * @param string $url
     * @param array  $params
     */
    public function post($url, $params = [])
    {
        return $this->request('POST', $url, $params);
    }

    /**
     * Make an HTTP PUT request
     *
     * @param string $url
     * @param array  $params
     */
    public function put($url, $params = [])
    {
        return $this->request('PUT', $url, $params);
    }

    /**
     * Make an HTTP DELETE request
     *
     * @param string $url
     * @param array  $params
     */
    public function delete($url, $params = [])
    {
        return $this->request('DELETE', $url, $params);
    }
}
