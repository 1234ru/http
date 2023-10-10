<?php

namespace One234ru\HTTP;

class Query
{
    private const DEFAULT_CURL_OPTIONS = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false, // для разработки
        CURLOPT_HEADERFUNCTION => __CLASS__ . '::registerHeader',
        CURLOPT_CONNECTTIMEOUT => 10,
    ];

    /** @var = [
     *  'request' => self::$requestDataStructure,
     *  'result' => string|array|void,
     *  'error' => string,
     *  'response' => [
     *      'status_code' => int,
     *      'headers' => string[],
     *      'body' => string,
     *      'sent_at' => \DateTime,
     *      'received_at' => \DateTime,
     *      'curl_error_code' => int,
     *      'curl_error_text' => string,
     *      'curl_info' => [],
     *      'json_error_code' => int,
     *      'json_error_text' => string,
     *  ]
     * ]
     */
    private $queryDataStructure;

    /** @var = [
     *  'url' => string,
     *  'params' => self::$paramsDeclaration,
     *  'curl_options' => [],
     * ]
     */
    private $requestDataStructure;

    /** @var = [
     *     'GET' => array|string,
     *     'POST' => array|string,
     *     'is_response_json' => bool,
     *     'headers' => [], // key => value,
     *     'oauth' => self::$oauthDeclaration
     * ] */
    private $paramsDeclaration;

    /** @var = [
     *  'client_id' => '',
     *  'token' => '',
     * ] */
    private $oauthDeclaration;

    private $HTTPheaders = [];

    /**
     * @param $url = ''
     * @param $params = self::$paramsDeclaration
     * @param array|void $curl_options
     * @return = self::$queryDataStructure
     */
    function send(
        $url,
        $params = [],
        $curl_options = []
    ) {
        $request = array_filter(
            compact('url', 'params' , 'curl_options')
        );

        if ($G = $params['GET'] ?? false) {
            $url .= '?' . ((is_array($G)) ? http_build_query($G) : $G);
        }

        $curl_settings = $curl_options + self::DEFAULT_CURL_OPTIONS;
        if ($P = $params['POST'] ?? false) {
            $curl_settings += [
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => (is_array($P)) ? http_build_query($P) : $P
            ];
        }

        self::appendHTTPheaders(
            $params['headers'] ?? [],
            $curl_settings
        );

        self::appendOauthHeaderIfNecessary(
            $params['oauth'] ?? [],
            $curl_settings
        );

        $ch = curl_init($url);
        curl_setopt_array($ch, $curl_settings);
        $sent_at = new \DateTime();
        $body = curl_exec($ch);
        $received_at = new \DateTime();
        $curl_info = curl_getinfo($ch);
        $status_code = $curl_info['http_code'];
        $headers = $this->HTTPheaders;
        $curl_error_code = curl_errno($ch);
        $curl_error_text = curl_error($ch);

        $response = compact(
            'status_code',
            'headers',
            'body',
            'sent_at',
            'received_at',
            'curl_error_code',
            'curl_error_text',
            'curl_info',
        );

        $result = $body;

        if (
            !$curl_error_code
            AND ($status_code == 200)
            AND ($params['is_response_json'] ?? false)
        ) {
            $result = json_decode($body, true);
            $response += [
                'json_error_code' => json_last_error(),
                'json_error_text' => json_last_error_msg(),
            ];
        }

        $error = self::makeErrorText($response, $url, $params, $curl_options);

        return compact('result', 'error', 'request', 'response');

    }

    private function registerHeader($curl_handler, string $header_string) :int
    {
        $work = trim($header_string);
        if ($work) {
            $parts = explode(':', trim($header_string), 2);
            if (count($parts) > 1) {
                $this->HTTPheaders[$parts[0]] = trim($parts[1]);
            } else {
                $this->HTTPheaders[] = trim($header_string);
            }
        }
        return strlen($header_string);
    }

    /**
     * @param $response = self::$queryDataStructure
     * @param string $url
     * @param $params = self::$paramsDeclaration
     * @param array $curl_options
     */
    private static function makeErrorText($response, $url, $params, $curl_options) :string
    {
        if ($response['curl_error_code']) {
            $error = $response['curl_error_text'] . "\n\n"
                . "CURL error code: $response[curl_error_code]\n\n"
                . "curl_getinfo(): " . var_export($response['curl_info'], 1)
                . "\n\n";
        } elseif ($response['status_code'] != 200) {
            $error = "HTTP response status code: $response[status_code]";
            $h = array_filter(
                $response['headers'],
                function($key) {
                    return is_numeric($key);
                },
                ARRAY_FILTER_USE_KEY
            );
            if ($h) {
                $error .= " (" . implode(' -> ', $h) . ")";
            }
            $error .= "\n\n";
            // $error = "HTTP response status code: $response[status_code].\n\n"
            //     . "Response headers: " . var_export($response['headers'], 1)
            //     . "\n\n" ;
        } elseif ($response['json_error_code'] ?? false) {
            $length = 1000;
            $error = "JSON decoding error: " . $response['json_error_text']
                . " (code = " . $response['json_error_code'] . ")\n\n"
                . "Raw response (first $length bytes):\n\n"
                . substr($response['body'], 0, $length)
                . "\n\n";
        }

        if ($error ?? false) {
            $error = "Error occured on HTTP query.\n\n"
                . "URL: $url\n\n"
                . $error
                . "PARAMS: " . var_export($params, 1) . "\n\n"
                . "CURL OPTIONS: " . var_export($curl_options, 1) . "\n\n"
            ;
        }

        return $error ?? '';
    }

    private static function appendHTTPheaders(
        array $headers,
              &$curl_settings
    ) {
        foreach ($headers as $key => $value) {
            $header_string = (is_numeric($key))
                ? $value
                : "$key: $value";
            $curl_settings[CURLOPT_HTTPHEADER][] =
                $header_string;
        }
    }

    /**
     * @param $params = self::$oauthDeclaration
     */
    private static function appendOauthHeaderIfNecessary(
        $oauth,
        &$curl_settings
    ) {
        if ($oauth) {
            $name = 'Authorization';
            $value = 'OAuth ';
            if (is_array($oauth)) {
                $value .= 'oauth_token="' . $oauth['token'] . '", '
                    . 'oauth_client_id="' . $oauth['client_id'] . '"';
            } else {
                // Для Яндекс.Доставки, например, нужно посылать заголовок
                // вида Authorization: Oauth <токен>
                $value .= $oauth;
            }
            self::appendHTTPheaders(
                [ $name => $value ],
                $curl_settings
            );
        }
    }

}