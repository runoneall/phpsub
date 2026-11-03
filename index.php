<?php
header('Content-Type: text/plain; charset=UTF-8');

$method = $_SERVER['REQUEST_METHOD'] ?? '';
if ($method !== 'GET' && $method !== 'POST') {
    http_response_code(405);
    header('Allow: GET, POST');
    echo 'Method Not Allowed';
    exit;
}

$token = $_GET['token'] ?? $_POST['token'] ?? '';
if ($token === '' || ! hash_equals("none", $token)) {
    http_response_code(401);
    echo 'Unauthorized';
    exit;
}

$root_dir = $_SERVER['DOCUMENT_ROOT'];
$data_dir = $root_dir . "/data";

if (file_exists($data_dir) && ! is_dir($data_dir)) {
    if (! @unlink($data_dir)) {
        error_log("无法删除文件: " . $data_dir);
        http_response_code(500);
        exit("无法删除冲突文件");
    }
}

if (! is_dir($data_dir)) {
    if (! @mkdir($data_dir, 0755, true)) {
        error_log("无法创建目录: " . $data_dir);
        http_response_code(500);
        exit("无法创建数据目录");
    }
}

$files = glob($data_dir . '/*.php');
if ($files === false) {
    error_log("无法扫描目录: " . $data_dir);
    http_response_code(500);
    exit("无法读取数据目录");
}

function load_config(string $file_path): array
{
    $vars = (static function (string $__file): array {
        require $__file;
        return get_defined_vars();
    })($file_path);

    unset($vars['__file']);
    return $vars;
}

$context = stream_context_create([
    'http' => [
        'method'  => 'GET',
        'header'  => "User-Agent: v2rayN/7.25.6\r\n",
        'timeout' => 10,
    ],
]);

$output_nodes = [];

foreach ($files as $file) {
    if (! is_file($file)) {
        continue;
    }

    $item = (array) load_config($file);

    $sub     = (string) ($item["sub"] ?? "");
    $nodes   = (array) ($item["nodes"] ?? []);
    $exclude = (array) ($item["exclude"] ?? []);
    $prefix  = (string) ($item["prefix"] ?? "");

    if ($sub !== "") {
        $response = @file_get_contents($sub, false, $context);
        if ($response === false) {
            $response = "vless://none@localhost:65535#请求失败";
        }

        if (! str_contains($response, '://')) {
            $decoded  = base64_decode(trim($response), true);
            $response = $decoded !== false ? $decoded : "vless://none@localhost:65535#解码失败";
        }

        $response = str_replace(["\r\n", "\r"], "\n", $response);
        foreach (explode("\n", $response) as $line) {
            $line = trim($line);
            if ($line !== '') {
                $nodes[] = $line;
            }
        }
    }

    foreach ($nodes as $node) {
        $node = trim($node);
        if ($node === '') {
            continue;
        }

        $hash_pos = strrpos($node, '#');
        if ($hash_pos !== false) {
            $base_url     = substr($node, 0, $hash_pos);
            $encoded_name = substr($node, $hash_pos + 1);
            $name         = urldecode($encoded_name);
        } else {
            $base_url = $node;
            $name     = '无名称';
        }

        $is_excluded = false;
        foreach ($exclude as $exclude_item) {
            if ($exclude_item !== '' && str_contains($name, (string) $exclude_item)) {
                $is_excluded = true;
                break;
            }
        }

        if ($is_excluded) {
            continue;
        }

        $final_name     = ($prefix === "" ? "" : $prefix . ' - ') . $name;
        $output_nodes[] = $base_url . '#' . $final_name;
    }
}

echo implode("\n", $output_nodes) . "\n";
