<?php

declare(strict_types=1);

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

require_once __DIR__ . '/functions.php';

$autoload = BASE_DIR . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
}

if (interface_exists(IReadFilter::class)) {
    final class PlanilhaRowReadFilter implements IReadFilter
    {
        /**
         * @param array<int, array{0:int, 1:int}> $rowRanges
         */
        public function __construct(
            private readonly array $rowRanges,
            private readonly int $maxColumn
        ) {
        }

        public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
        {
            if (Coordinate::columnIndexFromString($columnAddress) > $this->maxColumn) {
                return false;
            }

            foreach ($this->rowRanges as [$start, $end]) {
                if ($row >= $start && $row <= $end) {
                    return true;
                }
            }

            return false;
        }
    }
}

function import_planilhas_on_login(bool $force = false): array
{
    allow_long_import_runtime($force ? 900 : 300);

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

    if ($force) {
        clear_imported_planilha_rows();
    }

    $imported = 0;
    $completed = true;
    $deadline = $force ? null : microtime(true) + 240;
    foreach ($files as $file) {
        if ($deadline !== null && microtime(true) >= $deadline) {
            $completed = false;
            break;
        }

        $result = import_planilha_file($file, $deadline);
        $imported += (int) ($result['imported'] ?? 0);
        if (($result['completed'] ?? false) !== true) {
            $completed = false;
            break;
        }
    }

    if ($completed) {
        import_meta_set('planilhas_fingerprint', $fingerprint);
    }
    import_meta_set('planilhas_last_import', date('c'));

    return [
        'enabled' => true,
        'imported' => $imported,
        'files' => count($files),
        'skipped' => false,
        'completed' => $completed,
        'reason' => $completed ? '' : 'Importacao parcial para evitar tempo limite; sera retomada no proximo login.',
    ];
}

function planilha_import_files(): array
{
    $files = xlsx_files_recursive(PLANILHAS_DIR);
    return array_values(array_filter($files, function ($file) {
        $name = basename($file);
        if (!is_file($file) || str_starts_with($name, '~$')) {
            return false;
        }

        $relative = normalize_search_text(str_replace(PLANILHAS_DIR . DIRECTORY_SEPARATOR, '', $file));
        return !str_contains($relative, 'indicadores')
            && !str_starts_with(normalize_search_text($name), 'indicadores');
    }));
}

function clear_imported_planilha_rows(): void
{
    db()->exec("
        DELETE FROM acervo
        WHERE ID_UNICO LIKE 'xlsx_%'
           OR LOWER(COALESCE(FONTE_ARQUIVO, '')) LIKE '%.xlsx / %'
    ");

    try {
        db()->exec('DELETE FROM acervo_fts');
    } catch (Throwable) {
        // FTS pode nao existir em alguns ambientes.
    }
}

function indicador_planilha_files(): array
{
    if (!defined('INDICADORES_PLANILHAS_DIR') || !is_dir(INDICADORES_PLANILHAS_DIR)) {
        return [];
    }

    $files = xlsx_files_recursive(INDICADORES_PLANILHAS_DIR);
    return array_values(array_filter($files, fn ($file) => is_file($file) && !str_starts_with(basename($file), '~$')));
}

function xlsx_files_recursive(string $dir): array
{
    if (!is_dir($dir)) {
        return [];
    }

    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $item) {
        if ($item->isFile() && strtolower($item->getExtension()) === 'xlsx') {
            $files[] = $item->getPathname();
        }
    }

    natcasesort($files);
    return array_values($files);
}

function allow_long_import_runtime(int $seconds): void
{
    if ($seconds <= 0) {
        return;
    }

    @ini_set('max_execution_time', (string) $seconds);
    if (function_exists('set_time_limit')) {
        @set_time_limit($seconds);
    }
}

function import_indicadores_planilhas(bool $force = false): array
{
    allow_long_import_runtime($force ? 180 : 120);

    $files = indicador_planilha_files();
    if (!$files) {
        return ['enabled' => true, 'imported' => 0, 'files' => 0, 'reason' => 'Nenhuma planilha de indicadores encontrada.'];
    }

    ensure_import_meta_table();
    $fingerprint = planilhas_fingerprint($files);
    if (!$force && import_meta_get('indicadores_planilhas_fingerprint') === $fingerprint) {
        return ['enabled' => true, 'imported' => 0, 'files' => count($files), 'skipped' => true];
    }

    $imported = 0;
    foreach ($files as $file) {
        $imported += import_indicadores_file($file);
    }

    import_meta_set('indicadores_planilhas_fingerprint', $fingerprint);
    import_meta_set('indicadores_planilhas_last_import', date('c'));

    return ['enabled' => true, 'imported' => $imported, 'files' => count($files), 'skipped' => false, 'completed' => true, 'reason' => ''];
}

function import_indicadores_file(string $file): int
{
    $reader = IOFactory::createReaderForFile($file);
    $reader->setReadDataOnly(true);
    $imported = 0;

    foreach ($reader->listWorksheetInfo($file) as $sheetInfo) {
        $sheetName = (string) ($sheetInfo['worksheetName'] ?? '');
        if ($sheetName === '') {
            continue;
        }

        $reader = IOFactory::createReaderForFile($file);
        $reader->setReadDataOnly(true);
        $reader->setLoadSheetsOnly([$sheetName]);
        $spreadsheet = $reader->load($file);
        $sheet = $spreadsheet->getActiveSheet();
        $imported += import_indicadores_sheet($file, $sheet);
        $spreadsheet->disconnectWorksheets();
    }

    return $imported;
}

function import_indicadores_sheet(string $file, Worksheet $sheet): int
{
    $highestColumn = Coordinate::columnIndexFromString($sheet->getHighestColumn());
    $highestRow = $sheet->getHighestRow();
    $totalColumns = [];

    for ($column = 2; $column <= $highestColumn; $column++) {
        $header = normalize_search_text((string) $sheet->getCell([$column, 1])->getFormattedValue());
        if (str_contains($header, 'total semana')) {
            $totalColumns[] = $column;
        }
    }

    if (!$totalColumns) {
        return import_indicadores_summary_sheet($file, $sheet);
    }

    $imported = 0;
    $previousTotalColumn = 1;
    foreach ($totalColumns as $totalColumn) {
        $indicadores = [];
        $labels = [];
        $dias = [];
        $weekStartColumn = $previousTotalColumn + 1;
        for ($row = 2; $row <= $highestRow; $row++) {
            $label = trim((string) $sheet->getCell([1, $row])->getFormattedValue());
            if ($label === '') {
                continue;
            }

            $normalizedLabel = normalize_search_text($label);
            if (str_contains($normalizedLabel, 'outra atividade') || str_contains($normalizedLabel, 'observacao')) {
                for ($column = $weekStartColumn; $column < $totalColumn; $column++) {
                    $date = indicador_header_date($sheet->getCell([$column, 1])->getValue());
                    if ($date === '') {
                        continue;
                    }

                    $text = trim((string) $sheet->getCell([$column, $row])->getFormattedValue());
                    if ($text === '') {
                        continue;
                    }

                    $key = str_contains($normalizedLabel, 'observacao') ? 'observacao' : 'outra_atividade';
                    $dias[$date][$key][] = $text;
                }
                continue;
            }

            $value = indicador_numeric_value($sheet->getCell([$totalColumn, $row])->getCalculatedValue());
            if ($value === null || $value === 0) {
                continue;
            }

            $key = indicador_key($label);
            $indicadores[$key] = ($indicadores[$key] ?? 0) + $value;
            $labels[$key] = $label;

            for ($column = $weekStartColumn; $column < $totalColumn; $column++) {
                $date = indicador_header_date($sheet->getCell([$column, 1])->getValue());
                if ($date === '') {
                    continue;
                }

                $dailyValue = indicador_numeric_value($sheet->getCell([$column, $row])->getCalculatedValue());
                if ($dailyValue === null || $dailyValue === 0) {
                    continue;
                }

                $dias[$date]['indicadores'][$key] = ($dias[$date]['indicadores'][$key] ?? 0) + $dailyValue;
            }
        }

        $previousTotalColumn = $totalColumn;

        if (!$indicadores && !$dias) {
            continue;
        }

        $periodo = indicador_week_label($sheet, $totalColumn);
        $payload = [
            'origem' => 'planilha_indicadores',
            'arquivo' => basename($file),
            'aba' => $sheet->getTitle(),
            'periodo' => $periodo,
            'indicadores' => $indicadores,
            'labels' => $labels,
            'dias' => $dias,
        ];

        indicador_mirror_local([
            'colaborador' => $sheet->getTitle(),
            'data' => $periodo,
            'dados_json' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'criado_em' => date('c', (int) filemtime($file)),
        ]);
        $imported++;
    }

    return $imported;
}

function import_indicadores_summary_sheet(string $file, Worksheet $sheet): int
{
    $highestColumn = Coordinate::columnIndexFromString($sheet->getHighestColumn());
    $highestRow = $sheet->getHighestRow();
    $imported = 0;

    for ($column = 2; $column <= $highestColumn; $column++) {
        $periodo = trim((string) $sheet->getCell([$column, 1])->getFormattedValue());
        if ($periodo === '' || normalize_search_text($periodo) === 'fonte') {
            continue;
        }

        $indicadores = [];
        $labels = [];
        for ($row = 2; $row <= $highestRow; $row++) {
            $label = trim((string) $sheet->getCell([1, $row])->getFormattedValue());
            if ($label === '') {
                continue;
            }

            $value = indicador_numeric_value($sheet->getCell([$column, $row])->getCalculatedValue());
            if ($value === null || $value === 0) {
                continue;
            }

            $key = indicador_key($label);
            $indicadores[$key] = ($indicadores[$key] ?? 0) + $value;
            $labels[$key] = $label;
        }

        if (!$indicadores) {
            continue;
        }

        $payload = [
            'origem' => 'planilha_indicadores',
            'formato' => 'resumo_por_periodo',
            'arquivo' => basename($file),
            'aba' => $sheet->getTitle(),
            'periodo' => $periodo,
            'indicadores' => $indicadores,
            'labels' => $labels,
            'dias' => [],
        ];

        indicador_mirror_local([
            'colaborador' => $sheet->getTitle(),
            'data' => $periodo,
            'dados_json' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'criado_em' => date('c', (int) filemtime($file)),
        ]);
        $imported++;
    }

    return $imported;
}

function indicador_numeric_value(mixed $value): ?int
{
    if (is_string($value)) {
        $value = trim($value);
        if ($value === '' || str_starts_with($value, '#')) {
            return null;
        }
        $value = str_replace(',', '.', $value);
    }

    return is_numeric($value) ? (int) $value : null;
}

function indicador_key(string $label): string
{
    $key = normalize_search_text($label);
    $key = preg_replace('/[^a-z0-9]+/', '_', $key) ?? $key;
    return trim($key, '_') ?: 'indicador';
}

function indicador_week_label(Worksheet $sheet, int $totalColumn): string
{
    $dates = [];
    for ($column = max(2, $totalColumn - 5); $column < $totalColumn; $column++) {
        $value = $sheet->getCell([$column, 1])->getValue();
        $date = indicador_header_date($value);
        if ($date !== '') {
            $dates[] = $date;
        }
    }

    if (!$dates) {
        return 'Semana ' . Coordinate::stringFromColumnIndex($totalColumn);
    }

    return reset($dates) . ' a ' . end($dates);
}

function indicador_header_date(mixed $value): string
{
    if ($value instanceof DateTimeInterface) {
        return $value->format('d/m/Y');
    }

    if (is_numeric($value)) {
        try {
            return ExcelDate::excelToDateTimeObject((float) $value)->format('d/m/Y');
        } catch (Throwable) {
            return '';
        }
    }

    $value = trim((string) $value);
    return preg_match('/\d{2}\/\d{2}/', $value) === 1 ? $value : '';
}

function indicador_mirror_local(array $row): void
{
    $data = (string) ($row['data'] ?? '');
    $colaborador = (string) ($row['colaborador'] ?? '');
    $dados = (string) ($row['dados_json'] ?? '{}');
    $criadoEm = (string) ($row['criado_em'] ?? date('c'));
    $decoded = json_decode($dados, true);

    if (is_array($decoded) && ($decoded['origem'] ?? '') === 'planilha_indicadores') {
        db()->prepare("
            DELETE FROM indicadores
            WHERE data = :data
              AND colaborador = :colaborador
              AND dados_json LIKE :origem
        ")->execute([
            ':data' => $data,
            ':colaborador' => $colaborador,
            ':origem' => '%"origem":"planilha_indicadores"%',
        ]);
    }

    $exists = db()->prepare('SELECT id FROM indicadores WHERE data = :data AND colaborador = :colaborador AND dados_json = :dados LIMIT 1');
    $exists->execute([':data' => $data, ':colaborador' => $colaborador, ':dados' => $dados]);
    if ($exists->fetchColumn()) {
        return;
    }

    db()->prepare('INSERT INTO indicadores (colaborador, data, dados_json, criado_em) VALUES (:colaborador, :data, :dados, :criado_em)')
        ->execute([':colaborador' => $colaborador, ':data' => $data, ':dados' => $dados, ':criado_em' => $criadoEm]);
}

function planilhas_fingerprint(array $files): string
{
    $parts = ['import-v4-full'];
    sort($files);
    foreach ($files as $file) {
        $relative = str_replace((defined('PLANILHAS_DIR') ? PLANILHAS_DIR : dirname($file)) . DIRECTORY_SEPARATOR, '', $file);
        $parts[] = $relative . ':' . filesize($file) . ':' . filemtime($file);
    }

    return hash('sha256', implode('|', $parts));
}

function import_planilha_file(string $file, ?float $deadline = null): array
{
    $reader = IOFactory::createReaderForFile($file);
    $reader->setReadDataOnly(true);
    $imported = 0;

    foreach ($reader->listWorksheetInfo($file) as $sheetInfo) {
        if ($deadline !== null && microtime(true) >= $deadline) {
            return ['imported' => $imported, 'completed' => false];
        }

        $result = import_planilha_sheet_info($file, $sheetInfo, $deadline);
        $imported += (int) ($result['imported'] ?? 0);
        if (($result['completed'] ?? false) !== true) {
            return ['imported' => $imported, 'completed' => false];
        }
    }

    return ['imported' => $imported, 'completed' => true];
}

function import_planilha_sheet_info(string $file, array $sheetInfo, ?float $deadline = null): array
{
    $sheetName = (string) ($sheetInfo['worksheetName'] ?? '');
    $highestRow = min((int) ($sheetInfo['totalRows'] ?? 0), 100000);
    $maxColumn = max(1, min((int) ($sheetInfo['totalColumns'] ?? 1), 80));
    if ($sheetName === '' || $highestRow < 1) {
        return ['imported' => 0, 'completed' => true];
    }

    $headerReader = IOFactory::createReaderForFile($file);
    $headerReader->setReadDataOnly(true);
    $headerReader->setLoadSheetsOnly([$sheetName]);
    $headerReader->setReadFilter(new PlanilhaRowReadFilter([[1, min(30, $highestRow)]], $maxColumn));

    $headerSpreadsheet = $headerReader->load($file);
    $headerSheet = $headerSpreadsheet->getSheet(0);
    $headerRow = find_planilha_header_row($headerSheet);
    if ($headerRow === null) {
        $headerSpreadsheet->disconnectWorksheets();
        return ['imported' => 0, 'completed' => true];
    }

    $highestColumn = Coordinate::stringFromColumnIndex($maxColumn);
    $headers = $headerSheet->rangeToArray("A{$headerRow}:{$highestColumn}{$headerRow}", null, true, true, false)[0] ?? [];
    $fieldMap = map_planilha_headers($headers);
    $headerSpreadsheet->disconnectWorksheets();
    if (!$fieldMap) {
        return ['imported' => 0, 'completed' => true];
    }

    $imported = 0;
    $chunkSize = 1000;
    for ($startRow = $headerRow + 1; $startRow <= $highestRow; $startRow += $chunkSize) {
        if ($deadline !== null && microtime(true) >= $deadline) {
            return ['imported' => $imported, 'completed' => false];
        }

        $endRow = min($highestRow, $startRow + $chunkSize - 1);
        $reader = IOFactory::createReaderForFile($file);
        $reader->setReadDataOnly(true);
        $reader->setLoadSheetsOnly([$sheetName]);
        $reader->setReadFilter(new PlanilhaRowReadFilter([[$startRow, $endRow]], $maxColumn));

        $spreadsheet = $reader->load($file);
        $sheet = $spreadsheet->getSheet(0);
        for ($rowNumber = $startRow; $rowNumber <= $endRow; $rowNumber++) {
            $values = $sheet->rangeToArray("A{$rowNumber}:{$highestColumn}{$rowNumber}", null, true, true, false)[0] ?? [];
            $row = local_acervo_row_from_planilha($file, $sheetName, $rowNumber, $values, $fieldMap);
            if ($row === null) {
                continue;
            }

            upsert_imported_acervo_row($row);
            $imported++;
        }
        $spreadsheet->disconnectWorksheets();
    }

    return ['imported' => $imported, 'completed' => true];
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
    $header = trim($header);

    return match (true) {
        str_contains($header, 'caixa') || preg_match('/\bcx\b/', $header) === 1 => 'CAIXA',
        str_contains($header, 'processo') || str_contains($header, 'nup') => 'PROCESSO',
        str_contains($header, 'interessado') || str_contains($header, 'servidor') || str_contains($header, 'nome') => 'INTERESSADO',
        str_contains($header, 'assunto') || str_contains($header, 'descricao') || str_contains($header, 'conteudo') => 'ASSUNTO',
        str_contains($header, 'unidade') || str_contains($header, 'orgao') || str_contains($header, 'setor') => 'UNIDADE',
        str_contains($header, 'localiza') || str_contains($header, 'endereco') || str_contains($header, 'bloco') || str_contains($header, 'estante') => 'LOCALIZACAO',
        str_contains($header, 'temporalidade') || str_contains($header, 'cod temp') || str_contains($header, 'codigo classificacao') => 'TEMPORALIDADE',
        str_contains($header, 'volume') => 'VOLUMES',
        $header === 'data' || str_contains($header, 'data cadastro') || str_contains($header, 'atualizado em') => 'DATA',
        str_contains($header, 'data limite') || str_contains($header, 'data-limite') => 'DATA_LIMITE',
        str_contains($header, 'respons') => 'RESPONSAVEL',
        str_contains($header, 'observacao') || str_contains($header, 'tipo de documento') || str_contains($header, 'tipo doc') || str_contains($header, 'esp') => 'OBSERVACAO',
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
        'RESPONSAVEL' => planilha_value($values, $fieldMap, 'RESPONSAVEL'),
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
