<?php

namespace One234ru\HTTP;

class Request
{
    private const DEFAULT_CURL_OPTIONS = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false, // для разработки
        CURLOPT_HEADERFUNCTION => __CLASS__ . '::registerHeader',
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLINFO_HEADER_OUT => true, // curl_info()['request_header']
    ];

    /** @var = [
     * 'status_code' => int,
     * 'headers' => string[],
     * 'body' => string,
     * 'sent_at' => \DateTime,
     * 'received_at' => \DateTime,
     * 'curl_error_code' => int,
     * 'curl_error_text' => string,
     * 'curl_info' => [],
     * 'json_error_code' => int,
     * 'json_error_text' => string,
     * ]
     */
    public array $response;

    public string $error;

    public $URL;

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
     *     'headers' => [], // key => value,
     *     'is_response_json' => bool,
     *     'pass_post_params_as_json' => bool,
     *     'oauth' => self::$oauthDeclaration
     * ] */
    private $params;

    /** @var = [
     *  'client_id' => '',
     *  'token' => '',
     * ] */
    private $oauthDeclaration;

    private $curlOptions = [];

    private $isResponseJSON;

    private $responseHTTPheaders = [];

    /**
     * @param $url = ''
     * @param $params = self::$params
     * @param array|void $direct_curl_options
     */
    public function __construct(
        $url,
        $params = [],
        $direct_curl_options = []
    ) {
        $this->URL = $url
            . self::makeQueryString
            ($params['GET'] ?? '', true);;

        $this->params = $params;

        $this->isResponseJSON = $params['is_response_json'] ?? false;

        $this->curlOptions = $direct_curl_options
            + self::DEFAULT_CURL_OPTIONS;

        if (isset($params['POST'])) {
            $this->curlOptions += [
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS =>
                    ($this->params['pass_post_params_as_json'] ?? false)
                    ? json_encode($params['POST'])
                    : self::makeQueryString($params['POST'])
            ];
        }

        self::appendHTTPheaders(
            $params['headers'] ?? [],
            $this->curlOptions
        );

        self::appendOauthHeaderIfNecessary(
            $params['oauth'] ?? [],
            $this->curlOptions
        );
    }

    /** @throws \Exception */
    public function send() :string|array|null
    {
        $ch = curl_init($this->URL);
        curl_setopt_array($ch, $this->curlOptions);
        $sent_at = new \DateTime();
        $body = curl_exec($ch);
        $received_at = new \DateTime();
        $curl_info = curl_getinfo($ch);
        $status_code = $curl_info['http_code'];
        $headers = $this->HTTPheaders;
        $curl_error_code = curl_errno($ch);
        $curl_error_text = curl_error($ch);

        $this->response = compact(
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
            AND $this->isStatusCodeOK($status_code)
            AND $this->isResponseJSON
        ) {
            $result = json_decode($body, true);
            $this->response += [
                'json_error_code' => json_last_error(),
                'json_error_text' => json_last_error_msg(),
            ];
        }
        
        $this->error = $this->makeErrorText();

        if ($this->error) {
            throw new \Exception($this->error);
        }

        return $result;
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

    private static function makeQueryString(
        $query_params,
        $prepend_with_question_mark = false
    ) :string {
        $query_string = (is_array($query_params))
            ? http_build_query($query_params)
            : $query_params;
        if ($prepend_with_question_mark and $query_string) {
            $query_string = "?" . $query_string;
        }
        return $query_string;
    }

    private static function appendHTTPheaders(
        array $headers,
        &$curl_settings
    ) {
        foreach ($headers as $key => $value) {
            $header_string = (is_numeric($key))
                ? $value
                : "$key: $value";
            $curl_settings[CURLOPT_HTTPHEADER][] = $header_string;
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

    private function makeErrorText() :string
    {
        if ($this->response['curl_error_code']) {
            $msg = $this->response['curl_error_text'] . "\n\n"
                . "CURL error code: {$this->response['curl_error_code']}\n\n"
                . "curl_getinfo(): "
                . self::printAsJSON($this->response['curl_info'])
                . "\n\n";
        } elseif (!$this->isStatusCodeOK($this->response['status_code'])) {
            // echo self::printAsJSON($this->response['curl_info']); exit;
            $msg = "HTTP code not 200 ({$this->response['status_code']})";

        } elseif ($this->response['json_error_code'] ?? false) {
            $msg = "JSON decoding error: "
                . $this->response['json_error_text']
                . " (code = " . $this->response['json_error_code'] . ")";
            // $length = 1000;
        }

        if (!($msg ?? '')) {
            return '';
        }

        $msg .= "\n\n=== REQUEST ===\n\n"
            . $this->printRequest()
            . "\n\n=== RESPONSE ===\n\n"
            . $this->printResponse()
            . "\n\n"
            . "=========\n\n" ;

        return $msg;
    }

    private static function printAsJSON($data) :string
    {
        $flags = JSON_PRETTY_PRINT
            | JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES;
        return json_encode($data , $flags);
    }

    public function printRequest()
    {
        $output = $this->response['curl_info']['request_header'];
        if ($query = $this->params['GET'] ?? $this->params['POST']) {
            $output .= self::printAsJSON($query);
        }
        return $output;
    }

    private function printResponse()
    {
        $msg = '';
        foreach ($this->response['headers'] as $key => $value) {
            if (!is_numeric($key)) {
                $msg .= "$key: ";
            }
            $msg .= "$value\n";
        }
        $msg .= "\n" . $this->response['body'];
        return $msg;
    }

    private function isStatusCodeOK($code)
    {
        $first_digit = floor($code/100);
        return (
            $first_digit == 2
            OR (
                $first_digit == 3
                AND in_array( CURLOPT_FOLLOWLOCATION, $this->curlOptions)
            )
        );
    }

}