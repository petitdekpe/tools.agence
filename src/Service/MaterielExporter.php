<?php

namespace App\Service;

use App\Entity\Materiel;
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

/** Exports de l'inventaire filtré (Excel et PDF), avec la ligne de totaux. */
class MaterielExporter
{
    private const HEADERS = ['Désignation', 'Catégorie', 'Référence', 'État', 'Détenteur', 'Depuis le', 'Date d\'achat', 'Valeur d\'achat', 'Observation'];

    public function __construct(private readonly Environment $twig)
    {
    }

    /**
     * @param list<Materiel>                              $rows
     * @param array{n: int, value: string, assigned: int} $totals
     */
    public function excel(array $rows, array $totals, string $filterSummary): Response
    {
        $sheet = ($book = new Spreadsheet())->getActiveSheet();
        $sheet->setTitle('Matériel');
        $sheet->setCellValue('A1', 'Inventaire du matériel');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->setCellValue('A2', '' !== $filterSummary ? 'Filtres : '.$filterSummary : 'Aucun filtre');
        $sheet->setCellValue('A3', 'Édité le '.date('d/m/Y à H:i'));

        $headerRow = 5;
        foreach (self::HEADERS as $i => $header) {
            $sheet->setCellValue([$i + 1, $headerRow], $header);
        }
        $sheet->getStyle('A5:I5')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '0F766E']],
            'alignment' => ['wrapText' => true, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);

        $line = $headerRow;
        foreach ($rows as $m) {
            ++$line;
            // Texte forcé : une valeur commençant par « = » ne doit jamais devenir une formule.
            $texts = [$m->getDesignation(), $m->getCategoryLabel(), (string) $m->getReference(), $m->getEtatLabel(), $m->getHolder()?->getFullName() ?? '', null, null, null, (string) $m->getObservation()];
            foreach ($texts as $col => $text) {
                if (null !== $text) {
                    $sheet->setCellValueExplicit([$col + 1, $line], $text, DataType::TYPE_STRING);
                }
            }
            if ($since = $m->getCurrentAffectation()?->getAssignedAt()) {
                $sheet->setCellValue([6, $line], Date::PHPToExcel($since));
            }
            if ($m->getPurchasedAt()) {
                $sheet->setCellValue([7, $line], Date::PHPToExcel($m->getPurchasedAt()));
            }
            if (null !== $m->getPurchaseValue()) {
                $sheet->setCellValue([8, $line], EncaissementCalculator::toInt($m->getPurchaseValue()));
            }
        }
        $last = max($headerRow + 1, $line);
        $sheet->getStyle(sprintf('F%d:G%d', $headerRow + 1, $last))->getNumberFormat()->setFormatCode('dd/mm/yyyy');

        ++$line;
        $sheet->setCellValue([1, $line], sprintf('Total (%d matériel%s, dont %d affecté%s)', $totals['n'], $totals['n'] > 1 ? 's' : '', $totals['assigned'], $totals['assigned'] > 1 ? 's' : ''));
        $sheet->setCellValue([8, $line], EncaissementCalculator::toInt($totals['value']));
        $sheet->getStyle(sprintf('A%d:I%d', $line, $line))->applyFromArray([
            'font' => ['bold' => true],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E6F4F2']],
        ]);
        $sheet->getStyle(sprintf('H%d:H%d', $headerRow + 1, $line))->getNumberFormat()->setFormatCode('#,##0');
        foreach (['A' => 34, 'B' => 16, 'C' => 20, 'D' => 12, 'E' => 26, 'F' => 12, 'G' => 13, 'H' => 16, 'I' => 40] as $col => $width) {
            $sheet->getColumnDimension($col)->setWidth($width);
        }
        $sheet->freezePane('A6');

        $stream = fopen('php://temp', 'w+b');
        (new Xlsx($book))->save($stream);
        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);

        return $this->download($content, 'materiel-'.date('Ymd').'.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    /**
     * @param list<Materiel>                              $rows
     * @param array{n: int, value: string, assigned: int} $totals
     */
    public function pdf(array $rows, array $totals, string $filterSummary): Response
    {
        $html = $this->twig->render('materiel/pdf.html.twig', ['rows' => $rows, 'totals' => $totals, 'filterSummary' => $filterSummary, 'generatedAt' => new \DateTimeImmutable()]);

        $pdf = new Dompdf((new Options())->setDefaultFont('DejaVu Sans')->setIsRemoteEnabled(false)->setIsPhpEnabled(false));
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('A4', 'landscape');
        $pdf->render();

        return $this->download($pdf->output(), 'materiel-'.date('Ymd').'.pdf', 'application/pdf');
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
