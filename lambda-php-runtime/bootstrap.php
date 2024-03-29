<?php
/* bootstrap -- This script is the main bootstrap script that
communicates with the Lambda Runtime API to initialize the
execution environment and handle invocation requests. This
has been tested only on PHP 7.4.* and PHP 8.0.* and requires
the following PHP extensions to function properly, PHP Core, 
PHP cURL and PHP OpenSSL. Other than that you can add more
extensions as necessary for your function and dependencies,
but try to keep the PHP runtime as lean as possible for
optimal performance. Author: Saud Albazei. */

/* Be verbose and display all errors and warnings. */
ini_set('display_errors', 1);
error_reporting(E_ALL);

/* Define constants. */
define('LAMBDA_TASK_ROOT', $_ENV['LAMBDA_TASK_ROOT']);
define('LAMBDA_RUNTIME_API_ENDPOINT', 'http://' . $_ENV['AWS_LAMBDA_RUNTIME_API'] . '/2018-06-01/runtime/invocation/');
define('LAMBDA_HANDLER_FILE', explode('.', $_ENV['_HANDLER'])[0] . '.php');
define('LAMBDA_HANDLER_FUNCTION', explode('.', $_ENV['_HANDLER'])[1]);

/* Import the function handler file. */
try {
    require LAMBDA_TASK_ROOT . '/' . LAMBDA_HANDLER_FILE;
} catch (Throwable $e) {
    $init_error = error_response($e->getMessage());
}

/* Simple function to handle HTTP requests using cURL. */
function http_request($type, $url, $payload = null)
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    if ($type == 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    }
    $response = curl_exec($ch);
    $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return [
        'headers' => get_headers_from_curl_response($response),
        'body' => substr($response, $header_size)
    ];
}

/* Simple function to parse headers from the cURL response. */
function get_headers_from_curl_response($response)
{
    $headers = array();
    $header_text = substr($response, false, strpos($response, "\r\n\r\n"));
    foreach (explode("\r\n", $header_text) as $i => $line)
        if ($i === 0)
            $headers['http_code'] = $line;
        else {
            list($key, $value) = explode(': ', $line);
            $headers[$key] = $value;
        }
    return $headers;
}

/* Build the error response object. */
function error_response($error_message)
{
    return [
        'statusCode' => 500,
        'body' => $error_message
    ];
}

/* Encode the response into a JSON object and send 
that response to the Lambda Runtime API. */
function send_response($invocation_id, $response)
{
    $response_type = ($response['statusCode'] == 200) ? '/response' : '/error';
    return http_request('POST', LAMBDA_RUNTIME_API_ENDPOINT . $invocation_id . $response_type, json_encode($response));
}

/*  Trigger the Lambda function. */
function trigger_handler($request)
{
    if(!function_exists(LAMBDA_HANDLER_FUNCTION)){
        return error_response("Handler function " . LAMBDA_HANDLER_FUNCTION . "() is not defined.");
    }
    try {
        return call_user_func(LAMBDA_HANDLER_FUNCTION, json_decode($request['body']));
    } catch (Throwable $e) {
        return error_response($e->getMessage());
    }
}

/* The main function that processes each event. */
function process_request()
{
    $request = http_request('GET', LAMBDA_RUNTIME_API_ENDPOINT . 'next');
    $response = $GLOBALS['init_error'] ?? trigger_handler($request);
    return send_response($request['headers']['Lambda-Runtime-Aws-Request-Id'], $response);
}

/* Infinite loop to get new requests and processes them and repeats. 
The Lambda Runtime API will keep the HTTP request on hold until
it receives a new request then it returns a response to the waiting
request immediately for processing */
while (true)
{
    process_request();
/*  It is highly recommended to carefully manage this infinite loop against memory leaks.
    The first option is to exit when current memory consumption is over 80%. The second
    one is set a finite number to the loop.
    if (memory_get_peak_usage(true) > $_ENV['AWS_LAMBDA_FUNCTION_MEMORY_SIZE']*1024*1024*0.8)
        exit("Execution environment ran out of memory");
    if($current_loop >= $max_loops)
        exit("Number of loops exceeded the maximum allowed"); */   
}