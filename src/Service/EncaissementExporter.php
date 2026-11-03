<?php

namespace App\Service;

use App\Entity\Encaissement;
use Dompdf\Dompdf;
use Dompdf\Options;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

/** Exports de la liste filtrée (Excel et PDF), avec la ligne de totaux. */
class EncaissementExporter
{
    private const HEADERS = ['Date', 'Client', 'Nature', 'TTC', 'HT', 'TVA prélevée à la source', 'TVA à reverser', 'AIB prélevé', 'Net reçu', 'Espèces / MoMo', 'Chèque / virement', 'Observation', 'Cohérence'];

    public function __construct(private readonly Environment $twig)
    {
    }

    /**
     * @param list<Encaissement>         $rows
     * @param array<string, string|int>  $totals
     */
    public function excel(array $rows, array $totals, string $filterSummary): Response
    {
        $sheet = ($book = new Spreadsheet())->getActiveSheet();
        $sheet->setTitle('Encaissements');
        $sheet->setCellValue('A1', 'Suivi des encaissements');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->setCellValue('A2', '' !== $filterSummary ? 'Filtres : '.$filterSummary : 'Aucun filtre');
        $sheet->setCellValue('A3', 'Édité le '.date('d/m/Y à H:i'));

        $headerRow = 5;
        foreach (self::HEADERS as $i => $header) {
            $sheet->setCellValue([$i + 1, $headerRow], $header);
        }
        $sheet->getStyle('A5:M5')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '0F766E']],
            'alignment' => ['wrapText' => true, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);

        $line = $headerRow;
        foreach ($rows as $e) {
            ++$line;
            $sheet->setCellValue([1, $line], Date::PHPToExcel($e->getDateEncaissement()));
            // Texte forcé : un nom ou une observation commençant par « = » ne doit jamais devenir une formule.
            $sheet->setCellValueExplicit([2, $line], $e->getClient()->getName(), DataType::TYPE_STRING);
            $sheet->setCellValueExplicit([3, $line], $e->getNatureLabel(), DataType::TYPE_STRING);
            $this->numbers($sheet, $line, [$e->getMontantTtc(), $e->getMontantHt(), $e->getTvaPreleveeSource(), $e->getTvaAReverser(), $e->getAibPreleveSource(), $e->getMontantNetRecu(), $e->getMontantEspecesMomo(), $e->getMontantChequeVirement()]);
            $sheet->setCellValueExplicit([12, $line], (string) $e->getObservation(), DataType::TYPE_STRING);
            $sheet->setCellValueExplicit([13, $line], $this->coherenceLabel($e), DataType::TYPE_STRING);
        }
        $sheet->getStyle(sprintf('A%d:A%d', $headerRow + 1, max($headerRow + 1, $line)))->getNumberFormat()->setFormatCode('dd/mm/yyyy');

        ++$line;
        $sheet->setCellValue([1, $line], sprintf('Total (%d encaissement%s)', $totals['n'], $totals['n'] > 1 ? 's' : ''));
        $this->numbers($sheet, $line, [$totals['ttc'], $totals['ht'], $totals['tvaSource'], $totals['tvaReverser'], $totals['aib'], $totals['net'], $totals['especes'], $totals['cheque']]);
        $sheet->getStyle(sprintf('A%d:M%d', $line, $line))->applyFromArray([
            'font' => ['bold' => true],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E6F4F2']],
        ]);

        $sheet->getStyle(sprintf('D%d:K%d', $headerRow + 1, $line))->getNumberFormat()->setFormatCode('#,##0');
        foreach (['A' => 12, 'B' => 30, 'C' => 20, 'D' => 15, 'E' => 15, 'F' => 18, 'G' => 15, 'H' => 14, 'I' => 15, 'J' => 16, 'K' => 16, 'L' => 40, 'M' => 22] as $col => $width) {
            $sheet->getColumnDimension($col)->setWidth($width);
        }
        $sheet->freezePane('A6');

        $stream = fopen('php://temp', 'w+b');
        (new Xlsx($book))->save($stream);
        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);

        return $this->download($content, 'encaissements-'.date('Ymd').'.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    /**
     * @param list<Encaissement>        $rows
     * @param array<string, string|int> $totals
     */
    public function pdf(array $rows, array $totals, string $filterSummary): Response
    {
        $html = $this->twig->render('encaissement/pdf.html.twig', ['rows' => $rows, 'totals' => $totals, 'filterSummary' => $filterSummary, 'generatedAt' => new \DateTimeImmutable()]);

        $options = (new Options())->setDefaultFont('DejaVu Sans')->setIsRemoteEnabled(false)->setIsPhpEnabled(false);
        $pdf = new Dompdf($options);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('A4', 'landscape');
        $pdf->render();

        return $this->download($pdf->output(), 'encaissements-'.date('Ymd').'.pdf', 'application/pdf');
    }

    public function coherenceLabel(Encaissement $e): string
    {
        $ecart = $e->getEcart();

        return match (true) {
            0 === $ecart => 'OK',
            $ecart > 0 => 'Écart de '.number_format($ecart, 0, '', ' ').' FCFA',
            default => 'Dépassement de '.number_format(-$ecart, 0, '', ' ').' FCFA',
        };
    }

    /** @param list<string|int|null> $values écrits à partir de la colonne D */
    private function numbers(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, int $line, array $values): void
    {
        foreach ($values as $i => $value) {
            $sheet->setCellValue([$i + 4, $line], EncaissementCalculator::toInt((string) $value));
        }
    }

    private function download(string $content, string $filename, string $type): Response
    {
        return new Response($content, 200, [
            'Content-Type' => $type,
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $filename),
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
