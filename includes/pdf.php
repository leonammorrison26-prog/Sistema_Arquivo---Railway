<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

function text_pdf(string $value): string
{
    return iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $value) ?: $value;
}

function output_etiqueta_pdf(array $data): never
{
    $pdf = new FPDF('L', 'mm', 'A4');
    $pdf->AddPage();
    $pdf->SetMargins(0, 0, 0);
    $pdf->SetAutoPageBreak(false);

    $x = 27;
    $y = 10;
    $w = 125;
    $h = 181;
    $pad = 3;
    $innerX = $x + $pad;
    $innerY = $y + $pad;
    $innerW = $w - ($pad * 2);
    $innerH = $h - ($pad * 2);
    $labelW = 34;
    $topH = 19;
    $unitH = 10;
    $bottomRowH = 12.2;
    $bottomH = $bottomRowH * 3;
    $bodyY = $innerY + $topH + $unitH;
    $bodyH = $innerH - $topH - $unitH - $bottomH;
    $bottomY = $bodyY + $bodyH;

    $pdf->SetDrawColor(0, 0, 0);
    $pdf->SetTextColor(0, 0, 0);

    $pdf->SetLineWidth(0.8);
    $pdf->Rect($x, $y, $w, $h);
    $pdf->SetLineWidth(0.5);
    $pdf->Rect($x + 1.8, $y + 1.8, $w - 3.6, $h - 3.6);
    $pdf->SetLineWidth(0.35);
    $pdf->Rect($x + 3, $y + 3, $w - 6, $h - 6);

    $pdf->SetLineWidth(0.35);
    $pdf->Rect($innerX, $innerY, $innerW, $topH);
    $pdf->Line($innerX + $labelW, $innerY, $innerX + $labelW, $innerY + $topH + $unitH);
    $pdf->Rect($innerX, $innerY + $topH, $innerW, $unitH);

    $brasao = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'brasao.png.png';
    if (is_file($brasao)) {
        $pdf->Image($brasao, $innerX + 8.5, $innerY + 2.4, 17, 15.5);
    }

    $pdf->SetFont('Arial', '', 22);
    $pdf->SetXY($innerX + $labelW, $innerY + 3.2);
    $pdf->Cell($innerW - $labelW, 10, text_pdf('MDS'), 0, 0, 'C');

    $pdf->SetFont('Arial', '', 16);
    $pdf->SetXY($innerX + 3, $innerY + $topH + 2.2);
    $pdf->Cell($labelW - 6, 6, text_pdf('UNIDADE'), 0, 0, 'L');
    $pdf->SetFont('Arial', '', 13);
    $pdf->SetXY($innerX + $labelW + 3, $innerY + $topH + 2.1);
    $pdf->Cell($innerW - $labelW - 6, 6, text_pdf((string) ($data['unidade'] ?? '')), 0, 0, 'L');

    $assunto = trim((string) ($data['assunto'] ?? ''));
    if ($assunto !== '') {
        $pdf->SetFont('Arial', '', 12);
        $pdf->SetXY($innerX + 6, $bodyY + 8);
        $pdf->MultiCell($innerW - 12, 6, text_pdf($assunto), 0, 'L');
    }

    $rows = [
        'Data-limite' => $data['data_limite'] ?? ($data['data'] ?? ''),
        'Caixa' => $data['caixa'] ?? '',
        'Localização' => $data['localizacao'] ?? '',
    ];

    $rowY = $bottomY;
    foreach ($rows as $label => $value) {
        $pdf->Rect($innerX, $rowY, $innerW, $bottomRowH);
        $pdf->Line($innerX + $labelW, $rowY, $innerX + $labelW, $rowY + $bottomRowH);
        $pdf->SetFont('Arial', '', 16);
        $pdf->SetXY($innerX + 3, $rowY + 2.2);
        $pdf->Cell($labelW - 6, 7, text_pdf($label), 0, 0, 'L');
        $pdf->SetFont('Arial', '', 13);
        $pdf->SetXY($innerX + $labelW + 3, $rowY + 2.4);
        $pdf->Cell($innerW - $labelW - 6, 7, text_pdf((string) $value), 0, 0, 'L');
        $rowY += $bottomRowH;
    }

    $pdf->Output('D', 'etiqueta_diarq.pdf');
    exit;
}

function output_guia_pdf(array $data): never
{
    $pdf = new FPDF('P', 'mm', 'A4');
    $pdf->AddPage();
    $pdf->SetMargins(0, 0, 0);
    $pdf->SetAutoPageBreak(false);
    $pdf->SetDrawColor(0, 0, 0);
    $pdf->SetTextColor(0, 0, 0);

    $left = 23;
    $right = 187;
    $width = $right - $left;

    $brasao = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'brasao.png.png';
    if (is_file($brasao)) {
        $pdf->Image($brasao, 93.5, 13.5, 23);
    }

    $pdf->SetFont('Arial', '', 11.5);
    $headerLines = [
        'MINISTÉRIO DO DESENVOLVIMENTO E ASSISTÊNCIA SOCIAL, FAMÍLIA E',
        'COMBATE À FOME',
        'SECRETARIA EXECUTIVA',
        'SUBSECRETARIA DE ASSUNTOS ADMINISTRATIVOS',
        'COORDENAÇÃO-GERAL DE LOGÍSTICA E ADMINISTRAÇÃO',
        'COORDENAÇÃO DE DOCUMENTAÇÃO E ARQUIVO',
        'DIVISÃO DE ARQUIVO',
    ];

    $y = 40;
    foreach ($headerLines as $line) {
        $pdf->SetXY($left, $y);
        $pdf->Cell($width, 4.8, text_pdf($line), 0, 0, 'C');
        $y += 4.8;
    }

    $pdf->SetFont('Arial', 'B', 22);
    $pdf->SetXY($left, 84);
    $pdf->Cell($width, 10, text_pdf('GUIA FORA'), 0, 0, 'C');
    $pdf->SetLineWidth(0.6);
    $pdf->Line(82, 94, 128, 94);

    $tableX = $left;
    $tableY = 105;
    $headerH = 6.5;
    $rowH = 14;
    $cols = [
        ['NUP', 49],
        ['VOL.', 15],
        ['INTERESSADO', 40],
        ['CAIXA', 20],
        ['LOCAL.', 40],
    ];

    $pdf->SetFillColor(191, 191, 191);
    $pdf->SetLineWidth(0.25);
    $pdf->SetFont('Arial', 'B', 12.5);
    $x = $tableX;
    foreach ($cols as [$label, $colW]) {
        $pdf->Rect($x, $tableY, $colW, $headerH, 'DF');
        $pdf->SetXY($x, $tableY + .7);
        $pdf->Cell($colW, 5, text_pdf($label), 0, 0, 'C');
        $x += $colW;
    }

    $pdf->SetFont('Arial', '', 12);
    $values = [
        (string) ($data['nup'] ?? ''),
        (string) ($data['vol'] ?? ''),
        (string) ($data['interessado_topo'] ?? ''),
        (string) ($data['caixa'] ?? ''),
        (string) ($data['localizacao'] ?? ''),
    ];
    $x = $tableX;
    foreach ($cols as $idx => [, $colW]) {
        $pdf->Rect($x, $tableY + $headerH, $colW, $rowH);
        pdf_fit_cell($pdf, $x + 1.5, $tableY + $headerH + 4.1, $colW - 3, 5, $values[$idx], 12, 'C');
        $x += $colW;
    }

    $lineY = 138;
    guia_field_line($pdf, 'DESTINO:', $data['destino'] ?? '', $left + 1.5, $lineY, 24, 91, 34);
    $lineY += 10.2;
    guia_field_line($pdf, 'INTERESSADO:', $data['interessado_corpo'] ?? '', $left + 1.5, $lineY, 34, 90, 38);
    $lineY += 10.2;
    guia_field_line($pdf, 'PROCESSO SEI:', $data['processo_sei'] ?? '', $left + 1.5, $lineY, 37, 90, 38);
    $lineY += 10.2;
    guia_field_line($pdf, 'SOLICITANTE:', $data['solicitante'] ?? '', $left + 1.5, $lineY, 33, 90, 38);
    $lineY += 10.2;
    guia_field_line($pdf, 'ENDEREÇO:', $data['endereco'] ?? '', $left + 1.5, $lineY, 29, 90, 38);

    $lineY += 18;
    $responsavelLabelW = 52;
    guia_field_line($pdf, 'RESPONSÁVEL DIARQ:', $data['responsavel'] ?? '', $left + 1.5, $lineY, $responsavelLabelW, 88, 88, true);
    $pdf->SetFont('Arial', '', 12);
    $responsavelLineX = $left + 1.5 + max($responsavelLabelW, $pdf->GetStringWidth(text_pdf('RESPONSÁVEL DIARQ:')) + 3) + 1;
    $pdf->SetXY($responsavelLineX, $lineY + 5.4);
    $pdf->Cell(88, 5, text_pdf('(Responsável pela entrega)'), 0, 0, 'C');

    $pdf->SetLineWidth(0.55);
    $pdf->Line($left + 1, 221, $right - 1, 221);

    $lineY = 238;
    $date = trim((string) ($data['data'] ?? ''));
    guia_field_line($pdf, 'DATA:', $date !== '' ? $date : '___/___/______', $left + 1.5, $lineY, 16, 31, 30);
    $lineY += 15;
    guia_field_line($pdf, 'RECEBIDO POR:', '', $left + 1.5, $lineY, 39, 91, 86);
    $pdf->SetFont('Arial', '', 12);
    $pdf->SetXY(92, $lineY + 5.4);
    $pdf->Cell(35, 5, text_pdf('(Assinatura)'), 0, 0, 'C');

    $pdf->Output('D', 'guia_fora.pdf');
    exit;
}

function guia_field_line(FPDF $pdf, string $label, string $value, float $x, float $y, float $labelW, float $lineW, float $minLineW = 40, bool $centerValue = true): void
{
    $pdf->SetFont('Arial', '', 13.5);
    $pdf->SetXY($x, $y);
    $labelText = text_pdf($label);
    $labelW = max($labelW, $pdf->GetStringWidth($labelText) + 3);
    $pdf->Cell($labelW, 6, $labelText, 0, 0, 'L');
    $lineX = $x + $labelW + 1;
    $value = trim((string) $value);
    $drawW = $minLineW;
    if ($value !== '') {
        $pdf->SetFont('Arial', '', 13.5);
        $drawW = min($lineW, max($minLineW, $pdf->GetStringWidth(text_pdf($value)) + 5));
    }
    $pdf->Line($lineX, $y + 5.2, $lineX + $drawW, $y + 5.2);
    pdf_fit_cell($pdf, $lineX + 1.5, $y + .2, $drawW - 3, 5.5, $value, 13.5, $centerValue ? 'C' : 'L');
}

function guia_continuation_line(FPDF $pdf, float $x, float $y, float $lineW): void
{
    $pdf->Line($x, $y + 5.2, $x + $lineW, $y + 5.2);
}

function pdf_fit_cell(FPDF $pdf, float $x, float $y, float $w, float $h, string $value, float $fontSize = 11, string $align = 'L'): void
{
    $value = text_pdf(trim($value));
    $size = $fontSize;
    while ($size > 7) {
        $pdf->SetFont('Arial', '', $size);
        if ($pdf->GetStringWidth($value) <= $w) {
            break;
        }
        $size--;
    }
    $pdf->SetXY($x, $y);
    $pdf->Cell($w, $h, $value, 0, 0, $align);
}

function labeled_pdf_line(FPDF $pdf, string $label, string $value): void
{
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(42, 9, text_pdf($label . ':'), 0, 0);
    $pdf->SetFont('Arial', '', 12);
    $pdf->MultiCell(0, 9, text_pdf($value));
}
