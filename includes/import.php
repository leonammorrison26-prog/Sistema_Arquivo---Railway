<?php

declare(strict_types=1);

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

require_once __DIR__ . '/functions.php';

function import_planilhas_on_login(bool $force = false): array
{
    if (!is_dir(PLANILHAS_DIR)) {
        return ['enabled' => false, 'imported' => 0, 'files' => 0, 'reason' => 'Pasta de planilhas nao encontrada.'];
    }

    if (!class_exists(IOFactory::class)) {
        $autoload = BASE_DIR . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
        if (is_file($autoload)) {
            require_once $autoload;
        }
    }

    if (!class_exists(IOFactory::class)) {
        return ['enabled' => false, 'imported' => 0, 'files' => 0, 'reason' => 'PhpSpreadsheet nao instalado no ambiente.'];
    }

    $files = planilha_import_files();
    if (!$files) {
        return ['enabled' => true, 'imported' => 0, 'files' => 0, 'reason' => 'Nenhuma planilha .xlsx encontrada.'];
    }

    ensure_import_meta_table();
    $fingerprint = planilhas_fingerprint($files);
    $currentCount = (int) db()->query('SELECT COUNT(*) FROM acervo')->fetchColumn();
    if (!$force && $currentCount > 10 && import_meta_get('planilhas_fingerprint') === $fingerprint) {
        return ['enabled' => true, 'imported' => 0, 'files' => count($files), 'skipped' => true];
    }

    $imported = 0;
    foreach ($files as $file) {
        $imported += import_planilha_file($file);
    }

    import_meta_set('planilhas_fingerprint', $fingerprint);
    import_meta_set('planilhas_last_import', date('c'));

    return ['enabled' => true, 'imported' => $imported, 'files' => count($files), 'skipped' => false];
}

function planilha_import_files(): array
{
    $files = glob(PLANILHAS_DIR . DIRECTORY_SEPARATOR . '*.xlsx') ?: [];
    return array_values(array_filter($files, fn ($file) => is_file($file) && !str_starts_with(basename($file), '~$')));
}

function planilhas_fingerprint(array $files): string
{
    $parts = [];
    sort($files);
    foreach ($files as $file) {
        $parts[] = basename($file) . ':' . filesize($file) . ':' . filemtime($file);
    }

    return hash('sha256', implode('|', $parts));
}

function import_planilha_file(string $file): int
{
    $reader = IOFactory::createReaderForFile($file);
    $reader->setReadDataOnly(true);
    $spreadsheet = $reader->load($file);
    $imported = 0;

    foreach ($spreadsheet->getWorksheetIterator() as $sheet) {
        $imported += import_planilha_sheet($file, $sheet);
    }

    $spreadsheet->disconnectWorksheets();
    return $imported;
}

function import_planilha_sheet(string $file, Worksheet $sheet): int
{
    $headerRow = find_planilha_header_row($sheet);
    if ($headerRow === null) {
        return 0;
    }

    $highestColumn = $sheet->getHighestColumn();
    $highestRow = $sheet->getHighestRow();
    $headers = $sheet->rangeToArray("A{$headerRow}:{$highestColumn}{$headerRow}", null, true, true, false)[0] ?? [];
    $fieldMap = map_planilha_headers($headers);
    if (!$fieldMap) {
        return 0;
    }

    $imported = 0;
    for ($rowNumber = $headerRow + 1; $rowNumber <= $highestRow; $rowNumber++) {
        $values = $sheet->rangeToArray("A{$rowNumber}:{$highestColumn}{$rowNumber}", null, true, true, false)[0] ?? [];
        $row = local_acervo_row_from_planilha($file, $sheet->getTitle(), $rowNumber, $values, $fieldMap);
        if ($row === null) {
            continue;
        }

        upsert_imported_acervo_row($row);
        $imported++;
    }

    return $imported;
}

function find_planilha_header_row(Worksheet $sheet): ?int
{
    $highestColumn = $sheet->getHighestColumn();
    $maxRow = min(30, $sheet->getHighestRow());
    $bestRow = null;
    $bestScore = 0;

    for ($row = 1; $row <= $maxRow; $row++) {
        $values = $sheet->rangeToArray("A{$row}:{$highestColumn}{$row}", null, true, true, false)[0] ?? [];
        $score = 0;
        foreach ($values as $value) {
            $header = normalize_header((string) $value);
            if ($header === '') {
                continue;
            }
            foreach (['caixa', 'processo', 'servidor', 'interessado', 'assunto', 'localizacao', 'unidade', 'temporalidade', 'volume'] as $keyword) {
                if (str_contains($header, $keyword)) {
                    $score++;
                    break;
                }
            }
        }

        if ($score > $bestScore) {
            $bestScore = $score;
            $bestRow = $row;
        }
    }

    return $bestScore >= 2 ? $bestRow : null;
}

function map_planilha_headers(array $headers): array
{
    $map = [];
    foreach ($headers as $index => $header) {
        $normalized = normalize_header((string) $header);
        if ($normalized === '') {
            continue;
        }

        $field = planilha_field_for_header($normalized);
        if ($field !== '' && !isset($map[$field])) {
            $map[$field] = $index;
        }
    }

    return $map;
}

function planilha_field_for_header(string $header): string
{
    return match (true) {
        str_contains($header, 'caixa') || preg_match('/\bcx\b/', $header) === 1 => 'CAIXA',
        str_contains($header, 'processo') || str_contains($header, 'nup') => 'PROCESSO',
        str_contains($header, 'interessado') || str_contains($header, 'servidor') || str_contains($header, 'nome') => 'INTERESSADO',
        str_contains($header, 'assunto') || str_contains($header, 'descricao') || str_contains($header, 'conteudo') => 'ASSUNTO',
        str_contains($header, 'unidade') || str_contains($header, 'orgao') || str_contains($header, 'setor') => 'UNIDADE',
        str_contains($header, 'localizacao') || str_contains($header, 'endereco') || str_contains($header, 'bloco') || str_contains($header, 'estante') => 'LOCALIZACAO',
        str_contains($header, 'temporalidade') || str_contains($header, 'cod temp') || str_contains($header, 'codigo classificacao') => 'TEMPORALIDADE',
        str_contains($header, 'volume') => 'VOLUMES',
        $header === 'data' || str_contains($header, 'data cadastro') => 'DATA',
        str_contains($header, 'data limite') || str_contains($header, 'data-limite') => 'DATA_LIMITE',
        str_contains($header, 'observacao') || str_contains($header, 'tipo doc') => 'OBSERVACAO',
        default => '',
    };
}

function local_acervo_row_from_planilha(string $file, string $sheet, int $rowNumber, array $values, array $fieldMap): ?array
{
    $source = basename($file) . ' / ' . $sheet;
    $row = [
        'ID_UNICO' => 'xlsx_' . hash('sha256', basename($file) . '|' . $sheet . '|' . $rowNumber),
        'UNIDADE' => planilha_value($values, $fieldMap, 'UNIDADE', 'DIARQ / MDS'),
        'ASSUNTO' => planilha_value($values, $fieldMap, 'ASSUNTO'),
        'INTERESSADO' => planilha_value($values, $fieldMap, 'INTERESSADO'),
        'DATA' => planilha_value($values, $fieldMap, 'DATA'),
        'TEMPORALIDADE' => planilha_value($values, $fieldMap, 'TEMPORALIDADE'),
        'CAIXA' => planilha_value($values, $fieldMap, 'CAIXA'),
        'PROCESSO' => planilha_value($values, $fieldMap, 'PROCESSO'),
        'LOCALIZACAO' => planilha_value($values, $fieldMap, 'LOCALIZACAO'),
        'OBSERVACAO' => planilha_value($values, $fieldMap, 'OBSERVACAO', 'Importado de planilha'),
        'VOLUMES' => planilha_value($values, $fieldMap, 'VOLUMES'),
        'RESPONSAVEL' => 'Importacao automatica',
        'DATA_LIMITE' => planilha_value($values, $fieldMap, 'DATA_LIMITE'),
        'ALTERADO_POR' => 'Importacao planilhas',
        'ULTIMA_ALTERACAO' => date('d/m/Y H:i:s'),
        'STATUS_EMPRESTIMO' => '---',
        'QUEM_RETIROU' => '---',
        'FONTE_ARQUIVO' => $source,
    ];

    if (trim($row['CAIXA'] . $row['PROCESSO'] . $row['INTERESSADO'] . $row['ASSUNTO']) === '------------') {
        return null;
    }

    $row['TEXTO_GERAL'] = build_texto_geral($row);
    return $row;
}

function planilha_value(array $values, array $fieldMap, string $field, string $default = ''): string
{
    if (!isset($fieldMap[$field])) {
        return normalize_text($default);
    }

    $value = $values[$fieldMap[$field]] ?? '';
    if ($value instanceof DateTimeInterface) {
        $value = $value->format('d/m/Y');
    }

    return normalize_text(preg_replace('/\s+/', ' ', trim((string) $value)) ?? '');
}

function normalize_header(string $value): string
{
    $value = normalize_search_text($value);
    return str_replace([' no ', ' numero '], ' n ', ' ' . $value . ' ');
}

function upsert_imported_acervo_row(array $row): void
{
    $exists = db()->prepare('SELECT COUNT(*) FROM acervo WHERE ID_UNICO = :id');
    $exists->execute([':id' => $row['ID_UNICO']]);

    if ((int) $exists->fetchColumn() > 0) {
        db()->prepare("
            UPDATE acervo SET
                UNIDADE = :UNIDADE,
                ASSUNTO = :ASSUNTO,
                INTERESSADO = :INTERESSADO,
                DATA = :DATA,
                TEMPORALIDADE = :TEMPORALIDADE,
                CAIXA = :CAIXA,
                PROCESSO = :PROCESSO,
                LOCALIZACAO = :LOCALIZACAO,
                OBSERVACAO = :OBSERVACAO,
                VOLUMES = :VOLUMES,
                RESPONSAVEL = :RESPONSAVEL,
                DATA_LIMITE = :DATA_LIMITE,
                ALTERADO_POR = :ALTERADO_POR,
                ULTIMA_ALTERACAO = :ULTIMA_ALTERACAO,
                STATUS_EMPRESTIMO = :STATUS_EMPRESTIMO,
                QUEM_RETIROU = :QUEM_RETIROU,
                FONTE_ARQUIVO = :FONTE_ARQUIVO,
                TEXTO_GERAL = :TEXTO_GERAL
            WHERE ID_UNICO = :ID_UNICO
        ")->execute($row);
        return;
    }

    db()->prepare("
        INSERT INTO acervo
            (ID_UNICO, UNIDADE, ASSUNTO, INTERESSADO, DATA, TEMPORALIDADE, CAIXA, PROCESSO, LOCALIZACAO, OBSERVACAO, VOLUMES, RESPONSAVEL, DATA_LIMITE, ALTERADO_POR, ULTIMA_ALTERACAO, STATUS_EMPRESTIMO, QUEM_RETIROU, FONTE_ARQUIVO, TEXTO_GERAL)
        VALUES
            (:ID_UNICO, :UNIDADE, :ASSUNTO, :INTERESSADO, :DATA, :TEMPORALIDADE, :CAIXA, :PROCESSO, :LOCALIZACAO, :OBSERVACAO, :VOLUMES, :RESPONSAVEL, :DATA_LIMITE, :ALTERADO_POR, :ULTIMA_ALTERACAO, :STATUS_EMPRESTIMO, :QUEM_RETIROU, :FONTE_ARQUIVO, :TEXTO_GERAL)
    ")->execute($row);
}

function ensure_import_meta_table(): void
{
    db()->exec("
        CREATE TABLE IF NOT EXISTS sync_meta (
            chave TEXT PRIMARY KEY,
            valor TEXT NOT NULL DEFAULT ''
        )
    ");
}

function import_meta_get(string $key): string
{
    $stmt = db()->prepare('SELECT valor FROM sync_meta WHERE chave = :chave');
    $stmt->execute([':chave' => $key]);
    return (string) ($stmt->fetchColumn() ?: '');
}

function import_meta_set(string $key, string $value): void
{
    db()->prepare("
        INSERT INTO sync_meta (chave, valor)
        VALUES (:chave, :valor)
        ON CONFLICT(chave) DO UPDATE SET valor = excluded.valor
    ")->execute([':chave' => $key, ':valor' => $value]);
}
