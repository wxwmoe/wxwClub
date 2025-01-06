<?php

/* Modified from PHP-Curl-Class/9.4.0
https://github.com/php-curl-class/php-curl-class */

class Curl {
    public $curl = null;
    public $id = null;

    public $error = false;
    public $errorCode = 0;
    public $errorMessage = null;

    public $curlError = false;
    public $curlErrorCode = 0;
    public $curlErrorMessage = null;

    public $httpError = false;
    public $httpStatusCode = 0;
    public $httpErrorMessage = null;

    public $url = null;
    public $requestHeaders = null;
    public $responseHeaders = null;
    public $rawResponseHeaders = '';
    public $responseCookies = [];
    public $response = null;
    public $rawResponse = null;

    public $beforeSendCallback = null;
    public $successCallback = null;
    public $errorCallback = null;
    public $completeCallback = null;

    public $attempts = 0;
    public $retries = 0;
    public $retryDecider = null;
    public $remainingRetries = 0;

    private $cookies = [];
    private $headers = [];
    private $options = [];
    
    public $headerCallbackData;

    public function __construct($base_url = null) {
        if (!extension_loaded('curl'))
            throw new \ErrorException('cURL library is not loaded');
        $this->curl = curl_init();
        $this->initialize($base_url);
    }
    
    public function call() {
        $args = func_get_args();
        $function = array_shift($args);
        if (is_callable($function)) {
            array_unshift($args, $this);
            call_user_func_array($function, $args);
        }
    }
    
    public function get($url) {
        $this->setUrl($url);
        $this->setOpt(CURLOPT_CUSTOMREQUEST, 'GET');
        $this->setOpt(CURLOPT_HTTPGET, true);
        return $this->exec();
    }
    
    public function post($url, $data = '') {
        $this->setUrl($url);
        $this->setOpt(CURLOPT_POST, true);
        $this->setOpt(CURLOPT_POSTFIELDS, $data);
        $this->setOpt(CURLOPT_CUSTOMREQUEST, 'POST');
        return $this->exec();
    }
    
    public function exec($ch = null) {
        $this->attempts += 1;

        if ($ch === null) {
            $this->responseCookies = [];
            $this->call($this->beforeSendCallback);
            $this->rawResponse = curl_exec($this->curl);
            $this->curlErrorCode = curl_errno($this->curl);
            $this->curlErrorMessage = curl_error($this->curl);
        } else {
            $this->rawResponse = curl_multi_getcontent($ch);
            $this->curlErrorMessage = curl_error($ch);
        } $this->curlError = $this->curlErrorCode !== 0;

        $this->rawResponseHeaders = $this->headerCallbackData->rawResponseHeaders;
        $this->responseCookies = $this->headerCallbackData->responseCookies;
        $this->headerCallbackData->rawResponseHeaders = '';
        $this->headerCallbackData->responseCookies = [];

        if ($this->curlError && function_exists('curl_strerror')) {
            $this->curlErrorMessage =
                curl_strerror($this->curlErrorCode) . (
                    empty($this->curlErrorMessage) ? '' : ': ' . $this->curlErrorMessage
                );
        }

        $this->httpStatusCode = $this->getInfo(CURLINFO_HTTP_CODE);
        $this->httpError = in_array((int) floor($this->httpStatusCode / 100), [4, 5], true);
        $this->error = $this->curlError || $this->httpError;
        $this->errorCode = $this->error ? ($this->curlError ? $this->curlErrorCode : $this->httpStatusCode) : 0;

        if ($this->getOpt(CURLINFO_HEADER_OUT) === true)
            $this->requestHeaders = $this->parseRequestHeaders($this->getInfo(CURLINFO_HEADER_OUT));
        $this->responseHeaders = $this->parseResponseHeaders($this->rawResponseHeaders);
        $this->response = $this->rawResponse;

        if ($this->error) {
            if (isset($this->responseHeaders['Status-Line'])) {
                $this->httpErrorMessage = $this->responseHeaders['Status-Line'];
            }
        } $this->errorMessage = $this->curlError ? $this->curlErrorMessage : $this->httpErrorMessage;
        
        unset($this->effectiveUrl);
        unset($this->totalTime);

        if ($this->attemptRetry()) return $this->exec($ch);

        $this->execDone();
        return $this->response;
    }
    
    public function execDone() {
        if ($this->error)
            $this->call($this->errorCallback);
        else $this->call($this->successCallback);
        $this->call($this->completeCallback);
    }
    
    public function close() {
        if (is_resource($this->curl) || $this->curl instanceof \CurlHandle)
            curl_close($this->curl);
        $this->curl = null;
        $this->options = null;
    }
    
    public function getOpt($option) { return isset($this->options[$option]) ? $this->options[$option] : null; }
    
    public function getInfo($opt = null) {
        $args[] = $this->curl;
        if (func_num_args()) $args[] = $opt;
        return call_user_func_array('curl_getinfo', $args);
    }
    
    public function setUrl($url) {
        $this->url = $url; 
        $this->setOpt(CURLOPT_URL, $this->url);
    }
    
    public function setOpt($option, $value) {
        $required_options = [CURLOPT_RETURNTRANSFER => 'CURLOPT_RETURNTRANSFER'];
        if (in_array($option, array_keys($required_options), true) && $value !== true)
            trigger_error($required_options[$option] . ' is a required option', E_USER_WARNING);
        if ($success = curl_setopt($this->curl, $option, $value))
            $this->options[$option] = $value;
        return $success;
    }
    
    public function setHeader($key, $value) {
        $this->headers[$key] = $value;
        foreach ($this->headers as $key => $value)
            $headers[] = $key . ': ' . $value;
        $this->setOpt(CURLOPT_HTTPHEADER, $headers);
    }
    
    public function setTimeout($seconds) { $this->setOpt(CURLOPT_TIMEOUT, $seconds); }
    public function setUserAgent($user_agent) { $this->setOpt(CURLOPT_USERAGENT, $user_agent); }
    public function setConnectTimeout($seconds) { $this->setOpt(CURLOPT_CONNECTTIMEOUT, $seconds); }
    public function setMaximumRedirects($maximum_redirects) { $this->setOpt(CURLOPT_MAXREDIRS, $maximum_redirects); }
    
    public function attemptRetry() {
        $attempt_retry = false;
        if ($this->error) {
            $attempt_retry = ($this->retryDecider === null) ?
                $this->remainingRetries >= 1 : call_user_func($this->retryDecider, $this);
            if ($attempt_retry) {
                $this->retries += 1;
                if ($this->remainingRetries)
                    $this->remainingRetries -= 1;
            }
        } return $attempt_retry;
    }
    
    public function unsetHeader($key) {
        unset($this->headers[$key]); $headers = [];
        foreach ($this->headers as $key => $value) {
            $headers[] = $key . ': ' . $value;
        } $this->setOpt(CURLOPT_HTTPHEADER, $headers);
    }
    
    private function initialize() {
        $this->headers = [];
        $this->id = uniqid('', true);
        $this->headerCallbackData = new \stdClass();
        $this->headerCallbackData->rawResponseHeaders = '';
        $this->headerCallbackData->responseCookies = [];
        $this->setOpt(CURLINFO_HEADER_OUT, true);
        $this->setOpt(CURLOPT_RETURNTRANSFER, true);
        $this->setOpt(CURLOPT_HEADERFUNCTION, $this->createHeaderCallback($this->headerCallbackData));
    }
    
    private function parseHeaders($raw_headers) {
        $http_headers = [];
        $raw_headers = preg_split('/\r\n/', (string)$raw_headers, -1, PREG_SPLIT_NO_EMPTY);
        $raw_headers_count = count($raw_headers);
        for ($i = 1; $i < $raw_headers_count; $i++) {
            if (strpos($raw_headers[$i], ':') !== false) {
                list($key, $value) = explode(':', $raw_headers[$i], 2);
                $key = trim($key); $value = trim($value);
                // Use isset() as array_key_exists() and ArrayAccess are not compatible.
                if (isset($http_headers[$key]))
                    $http_headers[$key] .= ',' . $value;
                else $http_headers[$key] = $value;
            }
        } return [isset($raw_headers['0']) ? $raw_headers['0'] : '', $http_headers];
    }
    
    private function parseRequestHeaders($raw_headers) {
        $first_line = $headers = $request_headers = [];
        list($first_line, $headers) = $this->parseHeaders($raw_headers);
        $request_headers['Request-Line'] = $first_line;
        foreach ($headers as $key => $value)
            $request_headers[$key] = $value;
        return $request_headers;
    }
    
    private function parseResponseHeaders($raw_response_headers) {
        $response_header = '';
        $first_line = $headers = $response_headers = [];
        $response_header_array = explode("\r\n\r\n", $raw_response_headers);
        for ($i = count($response_header_array) - 1; $i >= 0; $i--) {
            if (stripos($response_header_array[$i], 'HTTP/') === 0) {
                $response_header = $response_header_array[$i];
                break;
            }
        }
        list($first_line, $headers) = $this->parseHeaders($response_header);
        $response_headers['Status-Line'] = $first_line;
        foreach ($headers as $key => $value)
            $response_headers[$key] = $value;
        return $response_headers;
    }
    
    private function createHeaderCallback($header_callback_data) {
        return function ($ch, $header) use ($header_callback_data) {
            if (preg_match('/^Set-Cookie:\s*([^=]+)=([^;]+)/mi', $header, $cookie) === 1)
                $header_callback_data->responseCookies[$cookie[1]] = trim($cookie[2], " \n\r\t\0\x0B");
            $header_callback_data->rawResponseHeaders .= $header;
            return strlen($header);
        };
    }
}
