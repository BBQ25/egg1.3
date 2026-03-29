<?php
declare(strict_types=1);

$deployHookEnabled = trim((string) getenv('DOC_EASE_ENABLE_DEPLOY_HOOK'));
if ($deployHookEnabled !== '1') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Not found\n";
    exit;
}

$webhookSecret = trim((string) getenv('DOC_EASE_WEBHOOK_SECRET'));
$deployCommand = trim((string) getenv('DOC_EASE_DEPLOY_COMMAND'));
if ($deployCommand === '') {
    $deployCommand = 'sudo /usr/local/bin/deploy-doc-ease.sh';
}
$webhookLogFile = trim((string) getenv('DOC_EASE_WEBHOOK_LOG_FILE'));
if ($webhookLogFile === '') {
    $webhookLogFile = '/tmp/doc-ease-webhook.log';
}

function webhook_log(string $message): void
{
    global $webhookLogFile;
    $line = sprintf("[%s] %s\n", gmdate('c'), $message);
    file_put_contents($webhookLogFile, $line, FILE_APPEND);
}

function webhook_respond(int $statusCode, string $body): void
{
    http_response_code($statusCode);
    header('Content-Type: text/plain; charset=utf-8');
    echo $body;
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? '';
if ($method !== 'POST') {
    webhook_log('405 method=' . $method);
    webhook_respond(405, "POST only\n");
}

$event = $_SERVER['HTTP_X_GITHUB_EVENT'] ?? '';
$signature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
$payload = file_get_contents('php://input');
if ($payload === false) {
    webhook_log('400 unable to read payload');
    webhook_respond(400, "Invalid payload\n");
}

if ($webhookSecret === '') {
    webhook_log('500 webhook secret not configured');
    webhook_respond(500, "Webhook secret not configured\n");
}

if ($signature === '') {
    webhook_log('401 missing signature event=' . $event);
    webhook_respond(401, "Missing signature\n");
}

$expectedSignature = 'sha256=' . hash_hmac('sha256', $payload, $webhookSecret);
if (!hash_equals($expectedSignature, $signature)) {
    webhook_log('401 invalid signature event=' . $event);
    webhook_respond(401, "Invalid signature\n");
}

if ($event === 'ping') {
    webhook_log('200 ping -> pong');
    webhook_respond(200, "pong\n");
}

if ($event !== 'push') {
    webhook_log('202 ignored event=' . $event);
    webhook_respond(202, "ignored\n");
}

$payloadData = json_decode($payload, true);
if (!is_array($payloadData)) {
    webhook_log('400 invalid json payload for push event');
    webhook_respond(400, "Invalid JSON payload\n");
}

$ref = $payloadData['ref'] ?? '';
if ($ref !== 'refs/heads/main') {
    webhook_log('202 ignored push ref=' . $ref);
    webhook_respond(202, "ignored branch\n");
}

$output = [];
$exitCode = 0;
exec($deployCommand . ' 2>&1', $output, $exitCode);

$outputText = trim(implode("\n", $output));
if (strlen($outputText) > 3000) {
    $outputText = substr($outputText, 0, 3000) . '...';
}
$safeOutput = str_replace(["\r", "\n"], ['\\r', '\\n'], $outputText);
webhook_log('deploy exit=' . $exitCode . ' ref=' . $ref . ' output=' . $safeOutput);

if ($exitCode === 0) {
    webhook_respond(200, "deploy ok\n");
}

webhook_respond(500, "deploy failed\n");
