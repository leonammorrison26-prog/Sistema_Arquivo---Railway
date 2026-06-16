<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/assistant_context.php';

header('Content-Type: application/json; charset=utf-8');

$input = json_decode((string) file_get_contents('php://input'), true) ?: [];
$message = trim((string) ($input['message'] ?? ''));
$apiKey = getenv('OPENAI_API_KEY') ?: '';
$model = getenv('OPENAI_MODEL') ?: 'gpt-4.1-mini';

if ($message === '') {
    echo json_encode(['reply' => 'Digite uma mensagem.']);
    exit;
}

$context = assistant_context($message);
$contextPrompt = assistant_context_prompt($context);

if ($apiKey === '') {
    echo json_encode(['reply' => assistant_local_reply($message, $context)], JSON_UNESCAPED_UNICODE);
    exit;
}

$payload = json_encode([
    'model' => $model,
    'instructions' => 'Você é o Assistente Virtual do sistema Gestão de Acervos - DIARQ/MDS. Responda em português do Brasil, com tom profissional, claro e objetivo. Use primeiro o CONTEXTO DOS MANUAIS e o CONTEXTO DO ACERVO fornecidos. Para perguntas históricas, cite o arquivo e a página quando houver. Para perguntas de quantidade, use os totais do acervo. Se o contexto não sustentar a resposta, diga que não encontrou com segurança e sugira termos de pesquisa. Não invente fatos.',
    'input' => "PERGUNTA DO USUARIO:\n{$message}\n\n{$contextPrompt}",
], JSON_UNESCAPED_UNICODE);

$ch = curl_init('https://api.openai.com/v1/responses');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey,
    ],
    CURLOPT_TIMEOUT => 45,
]);

$raw = curl_exec($ch);
$error = curl_error($ch);
$status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);

if ($raw === false || $error) {
    echo json_encode(['reply' => 'Erro ao consultar o assistente: ' . $error]);
    exit;
}

$data = json_decode($raw, true);
if ($status >= 400) {
    $detail = $data['error']['message'] ?? 'Nao foi possivel consultar a API.';
    echo json_encode(['reply' => 'Assistente indisponivel no momento: ' . $detail], JSON_UNESCAPED_UNICODE);
    exit;
}

$reply = $data['output_text'] ?? null;

if (!$reply && isset($data['output'][0]['content'][0]['text'])) {
    $reply = $data['output'][0]['content'][0]['text'];
}

echo json_encode(['reply' => $reply ?: 'Nao foi possivel interpretar a resposta da API.'], JSON_UNESCAPED_UNICODE);
