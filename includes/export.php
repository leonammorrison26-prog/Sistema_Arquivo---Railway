<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/functions.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

function export_xlsx(string $filename, array $rows, string $sheetName = 'Dados'): never
{
    if (!class_exists(Spreadsheet::class)) {
        throw new RuntimeException('Biblioteca PhpSpreadsheet nao instalada. Execute composer install.');
    }

    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle(substr($sheetName, 0, 31));

    if ($rows) {
        $headers = array_keys($rows[0]);
        foreach ($headers as $i => $header) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($i + 1) . '1', $header);
        }
        foreach ($rows as $r => $row) {
            foreach ($headers as $c => $header) {
                $sheet->setCellValue(Coordinate::stringFromColumnIndex($c + 1) . ($r + 2), (string) ($row[$header] ?? ''));
            }
        }
        foreach (range(1, count($headers)) as $col) {
            $sheet->getColumnDimensionByColumn($col)->setAutoSize(true);
        }
    } else {
        $sheet->setCellValue('A1', 'Sem dados');
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}

function export_csv_query(string $filename, string $sql, array $params = []): never
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    $out = fopen('php://output', 'wb');
    if ($out === false) {
        throw new RuntimeException('Nao foi possivel abrir a saida do relatorio.');
    }

    fwrite($out, "\xEF\xBB\xBF");
    $wroteHeader = false;
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (!$wroteHeader) {
            fputcsv($out, array_keys($row), ';');
            $wroteHeader = true;
        }
        fputcsv($out, array_map(fn ($value) => (string) $value, $row), ';');
    }

    if (!$wroteHeader) {
        fputcsv($out, ['Sem dados'], ';');
    }

    fclose($out);
    exit;
}

function export_csv_query_mapped(string $filename, string $sql, callable $mapper, array $params = []): never
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    $out = fopen('php://output', 'wb');
    if ($out === false) {
        throw new RuntimeException('Nao foi possivel abrir a saida do relatorio.');
    }

    fwrite($out, "\xEF\xBB\xBF");
    $wroteHeader = false;
    while ($source = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $row = $mapper($source);
        if (!$wroteHeader) {
            fputcsv($out, array_keys($row), ';');
            $wroteHeader = true;
        }
        fputcsv($out, array_map(fn ($value) => (string) $value, $row), ';');
    }

    if (!$wroteHeader) {
        fputcsv($out, ['Sem dados'], ';');
    }

    fclose($out);
    exit;
}

function export_xlsx_template_query_mapped(string $filename, string $templatePath, string $sql, callable $mapper, array $params = []): never
{
    if (!class_exists(IOFactory::class)) {
        throw new RuntimeException('Biblioteca PhpSpreadsheet nao instalada. Execute composer install.');
    }

    if (!is_file($templatePath)) {
        throw new RuntimeException('Modelo de planilha nao encontrado: ' . $templatePath);
    }

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    $spreadsheet = IOFactory::load($templatePath);
    $sheet = $spreadsheet->getActiveSheet();
    $highestColumn = Coordinate::columnIndexFromString($sheet->getHighestColumn());
    $rowNumber = 2;

    while ($source = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $row = array_values($mapper($source));
        for ($column = 1; $column <= $highestColumn; $column++) {
            $sheet->setCellValue([$column, $rowNumber], (string) ($row[$column - 1] ?? ''));
        }
        $rowNumber++;
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}

function export_clean_value(?string $value): string
{
    $value = trim((string) $value);
    return $value === '---' ? '' : $value;
}

function export_text_after_label(string $text, string $label, array $nextLabels): string
{
    $text = preg_replace('/\s+/', ' ', $text) ?? $text;
    $labelPattern = preg_quote($label, '/');
    $nextPattern = implode('|', array_map(fn ($item) => preg_quote($item, '/'), $nextLabels));
    if (!preg_match('/' . $labelPattern . '\s+(.+?)(?=\s+(?:' . $nextPattern . ')\s+|$)/iu', $text, $matches)) {
        return '';
    }
    return export_clean_value($matches[1] ?? '');
}

function export_first_filled(array $row, string $column, string $label = ''): string
{
    return export_clean_value((string) ($row[$column] ?? ''));
}

function export_source_file(array $row): string
{
    return (string) ($row['FONTE_ARQUIVO'] ?? '');
}

function export_document_type(array $row): string
{
    $source = normalize_search_text(export_source_file($row));
    $observacao = export_first_filled($row, 'OBSERVACAO');
    $processo = export_first_filled($row, 'PROCESSO');
    $assunto = normalize_search_text(export_first_filled($row, 'ASSUNTO'));
    $interessado = export_first_filled($row, 'INTERESSADO');

    if (str_contains($source, 'pasta') || str_contains($source, 'rh') || str_contains($assunto, 'pasta funcional')) {
        return 'PASTA FUNCIONAL';
    }

    if (str_starts_with($observacao, 'Cadastro manual - ')) {
        return trim(substr($observacao, 18));
    }

    if ($observacao !== '' && !in_array($observacao, ['Importado de planilha', 'cadastro manual'], true)) {
        return $observacao;
    }

    if ($processo !== '') {
        return 'PROCESSOS';
    }

    if ($interessado !== '' && $assunto === '') {
        return 'PASTA FUNCIONAL';
    }

    return 'DOCUMENTOS';
}

function export_responsavel(array $row): string
{
    $responsavel = export_first_filled($row, 'RESPONSAVEL');
    return $responsavel === 'Importacao automatica' ? '' : $responsavel;
}

function export_inventario_padrao_row(array $row): array
{
    $row['RESPONSAVEL'] = export_responsavel($row);

    return [
        'UNIDADES' => export_first_filled($row, 'UNIDADE', 'UNIDADES'),
        'Nº CAIXAS' => export_first_filled($row, 'CAIXA', 'Nº CAIXAS'),
        'Nº Cod Temp' => export_first_filled($row, 'TEMPORALIDADE', 'Nº Cod Temp'),
        'TIPO DE DOCUMENTOS' => export_document_type($row),
        'Nº PROCESSOS' => export_first_filled($row, 'PROCESSO', 'Nº PROCESSOS'),
        'VOLUMES' => export_first_filled($row, 'VOLUMES', 'VOLUMES'),
        'ASSUNTOS' => export_first_filled($row, 'ASSUNTO', 'ASSUNTOS'),
        'INTERESSADOS' => export_first_filled($row, 'INTERESSADO', 'INTERESSADOS'),
        'LOCALIZAÇÃO' => export_first_filled($row, 'LOCALIZACAO', 'LOCALIZAÇÃO'),
        'Responsável' => export_first_filled($row, 'RESPONSAVEL', 'Responsável'),
        'DATA' => export_first_filled($row, 'DATA', 'Data'),
        'DATA - limite' => export_first_filled($row, 'DATA_LIMITE', 'DATA - limite'),
    ];
}
