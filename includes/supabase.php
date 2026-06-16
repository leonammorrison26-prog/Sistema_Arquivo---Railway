<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

function supabase_url(): string
{
    return rtrim((string) (getenv('SUPABASE_URL') ?: ''), '/');
}

function supabase_key(): string
{
    return (string) (getenv('SUPABASE_KEY') ?: getenv('SUPABASE_ANON_KEY') ?: getenv('SUPABASE_SERVICE_ROLE_KEY') ?: getenv('SUPABASE_SERVICE_KEY') ?: '');
}

function supabase_enabled(): bool
{
    return supabase_url() !== '' && supabase_key() !== '';
}

function supabase_status(): string
{
    return supabase_enabled() ? 'conectado/configurado' : 'SUPABASE_URL e SUPABASE_KEY nao configurados';
}

function supabase_request(string $method, string $table, array $payload = [], array $query = [], bool $mandatory = true): array
{
    if (!supabase_enabled()) {
        if ($mandatory) {
            throw new RuntimeException('Supabase obrigatorio nao configurado. Configure SUPABASE_URL e SUPABASE_KEY no Railway.');
        }
        return [];
    }

    $base = supabase_url();
    if (str_ends_with($base, '/rest/v1')) {
        $endpoint = $base . '/' . rawurlencode($table);
    } else {
        $endpoint = $base . '/rest/v1/' . rawurlencode($table);
    }

    if ($query) {
        $endpoint .= '?' . http_build_query($query);
    }

    $headers = [
        'apikey: ' . supabase_key(),
        'Authorization: Bearer ' . supabase_key(),
        'Content-Type: application/json',
        'Accept: application/json',
        'Prefer: return=representation,resolution=merge-duplicates',
    ];

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 45,
    ]);

    if (in_array(strtoupper($method), ['POST', 'PATCH', 'PUT'], true)) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    $raw = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($raw === false || $error !== '' || $status >= 400) {
        $message = $error !== '' ? $error : (string) $raw;
        if ($mandatory) {
            throw new RuntimeException('Falha Supabase (' . $status . '): ' . $message);
        }
        return [];
    }

    $decoded = json_decode((string) $raw, true);
    return is_array($decoded) ? $decoded : [];
}

function supabase_fetch_user(string $login, string $senha): ?array
{
    if (!supabase_enabled()) {
        return null;
    }

    foreach (['login', 'utilizador', 'usuario'] as $column) {
        $rows = supabase_request('GET', 'usuarios', [], [
            'select' => '*',
            $column => 'ilike.' . $login,
            'senha' => 'eq.' . $senha,
            'limit' => '1',
        ], false);

        if ($rows) {
            return $rows[0];
        }
    }

    return null;
}

function supabase_upsert(string $table, array $row, string $onConflict = ''): array
{
    $query = $onConflict !== '' ? ['on_conflict' => $onConflict] : [];
    return supabase_request('POST', $table, $row, $query, true);
}

