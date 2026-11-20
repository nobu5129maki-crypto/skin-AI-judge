<?php
// エックスサーバー向けの解析 API。入出力は api/analyze.js に合わせる。
// API キーはブラウザへ出さない。公開ディレクトリの外の config.php から読む。

ini_set('display_errors', '0');
@set_time_limit(120);

require dirname(__DIR__) . '/auth.php';

function skin_respond($status, $body, $extraHeaders)
{
    if (!is_array($extraHeaders)) {
        $extraHeaders = array();
    }
    http_response_code((int) $status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    foreach ($extraHeaders as $name => $value) {
        if ($value === null || $value === '') {
            continue;
        }
        $value = str_replace(array("\r", "\n"), '', (string) $value);
        header($name . ': ' . $value);
    }
    $json = json_encode($body, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        $json = '{"error":{"message":"Internal Server Error","status":"INTERNAL"}}';
    }
    echo $json;
    exit;
}

function skin_redact($text)
{
    $text = (string) $text;
    $text = preg_replace('/AIza[0-9A-Za-z_\-]{10,}/', '[redacted]', $text);
    $text = preg_replace('/([?&]key=)[^&\s]+/i', '$1[redacted]', $text);
    return $text;
}

function skin_key_from_config($path)
{
    if (!is_file($path)) {
        return '';
    }
    $real = realpath($path);
    if (!is_string($real) || $real === '') {
        return '';
    }
    $realNorm = str_replace('\\', '/', $real);
    if (preg_match('#/public_html(/|$)#', $realNorm)) {
        return '';
    }
    if (basename($realNorm) !== 'config.php') {
        return '';
    }
    $level = ob_get_level();
    ob_start();
    try {
        $config = include $real;
    } catch (Exception $e) {
        $config = null;
    }
    while (ob_get_level() > $level) {
        ob_end_clean();
    }
    if (!is_array($config) || !isset($config['GEMINI_API_KEY']) || !is_string($config['GEMINI_API_KEY'])) {
        return '';
    }
    return trim($config['GEMINI_API_KEY']);
}

function skin_api_key()
{
    $explicit = skin_key_from_config('/home/xs519923/xs519923.xsrv.jp/config.php');
    if ($explicit !== '') {
        return $explicit;
    }

    $candidates = array();
    $fromEnv = getenv('GEMINI_API_KEY');
    if (is_string($fromEnv)) {
        $candidates[] = $fromEnv;
    }
    if (isset($_ENV['GEMINI_API_KEY']) && is_string($_ENV['GEMINI_API_KEY'])) {
        $candidates[] = $_ENV['GEMINI_API_KEY'];
    }
    if (isset($_SERVER['GEMINI_API_KEY']) && is_string($_SERVER['GEMINI_API_KEY'])) {
        $candidates[] = $_SERVER['GEMINI_API_KEY'];
    }
    foreach ($candidates as $value) {
        if (trim($value) !== '') {
            return trim($value);
        }
    }

    $docRoot = '';
    if (!empty($_SERVER['DOCUMENT_ROOT'])) {
        $resolved = realpath($_SERVER['DOCUMENT_ROOT']);
        if (is_string($resolved) && $resolved !== '') {
            $docRoot = $resolved;
        }
    }
    if ($docRoot === '') {
        return '';
    }
    $paths = array();
    $above = dirname($docRoot);
    $paths[] = $above . '/config.php';
    $paths[] = $above . '/private/config.php';
    $dir = $docRoot;
    for ($i = 0; $i < 6; $i++) {
        if (basename($dir) === 'public_html') {
            $paths[] = dirname($dir) . '/config.php';
            $paths[] = dirname($dir) . '/private/config.php';
            break;
        }
        $parent = dirname($dir);
        if ($parent === $dir) {
            break;
        }
        $dir = $parent;
    }
    foreach ($paths as $path) {
        $key = skin_key_from_config($path);
        if ($key !== '') {
            return $key;
        }
    }
    return '';
}

function skin_build_payload($body, $useThinking)
{
    $clientConfig = (isset($body['generationConfig']) && is_array($body['generationConfig']))
        ? $body['generationConfig']
        : array();
    $maxTokens = 0;
    if (isset($clientConfig['maxOutputTokens'])) {
        $maxTokens = (int) $clientConfig['maxOutputTokens'];
    }
    if ($maxTokens < 4096) {
        $maxTokens = 4096;
    }
    $generationConfig = $clientConfig;
    $generationConfig['responseMimeType'] = 'application/json';
    $generationConfig['maxOutputTokens'] = $maxTokens;
    if ($useThinking) {
        $generationConfig['thinkingConfig'] = array('thinkingLevel' => 'low');
    } else {
        unset($generationConfig['thinkingConfig']);
    }
    $payload = array(
        'contents' => isset($body['contents']) ? $body['contents'] : null,
        'generationConfig' => $generationConfig,
    );
    if (isset($body['systemInstruction'])) {
        $payload['systemInstruction'] = $body['systemInstruction'];
    }
    return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function skin_is_thinking_config_error($status, $data)
{
    if ((int) $status !== 400) {
        return false;
    }
    $message = '';
    if (is_array($data) && isset($data['error']['message'])) {
        $message = (string) $data['error']['message'];
    } elseif (is_array($data) && isset($data['message'])) {
        $message = (string) $data['message'];
    }
    if (!preg_match('/thinking/i', $message)) {
        return false;
    }
    if (preg_match('/image/i', $message)) {
        return false;
    }
    return true;
}

function skin_gemini_call($apiKey, $model, $payload, $timeout)
{
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent?key=' . rawurlencode($apiKey);
    $headers = array();
    $ch = curl_init($url);
    $opts = array(
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => array('Content-Type: application/json'),
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HEADERFUNCTION => function ($ch, $header) use (&$headers) {
            $len = strlen($header);
            $parts = explode(':', $header, 2);
            if (count($parts) === 2) {
                $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
            return $len;
        },
    );
    if (defined('CURLOPT_IPRESOLVE') && defined('CURL_IPRESOLVE_V4')) {
        $opts[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V4;
    }
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = array();
    if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $data = $decoded;
        } else {
            $data = array('error' => array('message' => skin_redact(substr($raw, 0, 200))));
        }
    }
    return array(
        'status' => $httpStatus,
        'errno' => $errno,
        'data' => $data,
        'raw' => is_string($raw) ? $raw : '',
        'retryAfter' => isset($headers['retry-after']) ? $headers['retry-after'] : '',
        'headers' => $headers,
    );
}

if (!skin_logged_in()) {
    skin_respond(401, array(
        'error' => array(
            'message' => 'ログインしてください。',
            'status' => 'UNAUTHENTICATED',
        ),
    ), array());
}

$method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '';
if ($method !== 'POST') {
    skin_respond(405, array('error' => 'Method Not Allowed'), array());
}

$apiKey = skin_api_key();
if ($apiKey === '') {
    skin_respond(500, array(
        'error' => array(
            'message' => 'APIキーが未設定です。サーバー側の GEMINI_API_KEY を設定してください。',
            'status' => 'CONFIGURATION_ERROR',
        ),
    ), array());
}

if (!function_exists('curl_init')) {
    skin_respond(500, array(
        'error' => array(
            'message' => 'Internal Server Error',
            'status' => 'INTERNAL',
        ),
    ), array());
}

$rawBody = file_get_contents('php://input');
if (!is_string($rawBody)) {
    $rawBody = '';
}
if (strlen($rawBody) > 8000000) {
    skin_respond(413, array(
        'error' => array(
            'message' => '画像データが大きすぎます。',
            'status' => 'PAYLOAD_TOO_LARGE',
        ),
    ), array());
}

$body = json_decode($rawBody, true);
if (!is_array($body)) {
    $body = array();
}
if (!isset($body['contents'])) {
    skin_respond(400, array(
        'error' => array(
            'message' => 'contents is required',
            'status' => 'INVALID_ARGUMENT',
        ),
    ), array());
}

$models = array(
    'gemini-3.8-flash',
    'gemini-3.6-flash',
    'gemini-3.5-flash-lite',
    'gemini-2.5-flash',
);

$modelUsed = $models[0];
$geminiStatus = 0;
$data = array();
$rawOk = '';
$retryAfter = '';
$rateHeaders = array();
$succeeded = false;

for ($i = 0; $i < count($models); $i++) {
    $model = $models[$i];
    $modelUsed = $model;
    $attempts = array(true, false);
    $tryNextModel = false;
    foreach ($attempts as $useThinking) {
        $payload = skin_build_payload($body, $useThinking);
        if ($payload === false) {
            skin_respond(400, array(
                'error' => array(
                    'message' => 'INVALID_ARGUMENT',
                    'status' => 'INVALID_ARGUMENT',
                ),
            ), array());
        }
        $result = skin_gemini_call($apiKey, $model, $payload, $useThinking ? 8 : 16);
        $geminiStatus = (int) $result['status'];
        $data = $result['data'];
        $retryAfter = $result['retryAfter'];
        $rateHeaders = array();
        if (isset($result['headers']) && is_array($result['headers'])) {
            foreach ($result['headers'] as $name => $value) {
                if (strpos($name, 'x-ratelimit-') === 0) {
                    $rateHeaders[$name] = $value;
                }
            }
        }
        if ($result['errno'] === 28 || $geminiStatus === 0) {
            $geminiStatus = 504;
            $data = array('error' => array('message' => 'API_ERROR', 'status' => 'TIMEOUT'));
            break;
        }
        if ($geminiStatus >= 200 && $geminiStatus < 300) {
            $rawOk = $result['raw'];
            $succeeded = true;
            break;
        }
        if ($useThinking && skin_is_thinking_config_error($geminiStatus, $data)) {
            continue;
        }
        break;
    }
    if ($succeeded) {
        break;
    }
    $retryable = in_array($geminiStatus, array(429, 404, 503, 504), true);
    if (!$retryable || $i === count($models) - 1) {
        break;
    }
}

if ($succeeded) {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('x-skin-model: ' . $modelUsed);
    http_response_code(200);
    echo skin_redact($rawOk);
    exit;
}

if (isset($data['error']) && is_array($data['error'])) {
    $originalError = $data['error'];
} else {
    $message = 'API_ERROR';
    if (isset($data['error']['message'])) {
        $message = (string) $data['error']['message'];
    } elseif (isset($data['message'])) {
        $message = (string) $data['message'];
    }
    $originalError = array('message' => $message);
}
if (isset($originalError['message'])) {
    $originalError['message'] = skin_redact($originalError['message']);
}
$originalError['model'] = $modelUsed;
$originalError['rateLimit'] = array(
    'retryAfter' => $retryAfter,
    'headers' => $rateHeaders,
);
$data['error'] = $originalError;

$extra = array();
if ($retryAfter !== '') {
    $extra['retry-after'] = $retryAfter;
}
$statusOut = $geminiStatus >= 400 ? $geminiStatus : 502;
skin_respond($statusOut, $data, $extra);
