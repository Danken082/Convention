<?php

namespace App\Services;

use App\Models\Registration;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

class RegistrationWorkbook
{
    public function create(iterable $registrations): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Registrations');

        $headers = [
            'District',
            'Surname',
            'Given name',
            'Middle initial',
            'Nickname',
            'Age',
            'Gender',
            'Shirt size',
            'Division',
            'Ministry',
            'Email',
            'Proof of payment',
            'Submitted at',
            'Birthdate',
        ];

        foreach ($headers as $column => $header) {
            $cell = Coordinate::stringFromColumnIndex($column + 1).'1';
            $sheet->setCellValueExplicit($cell, $header, DataType::TYPE_STRING);
        }

        $row = 2;
        foreach ($registrations as $registration) {
            if (! $registration instanceof Registration) {
                throw new RuntimeException('Registration workbook entries must be registration records.');
            }

            $proofPath = $registration->proof_of_payment_path;
            $values = [
                $registration->district,
                $registration->surname,
                $registration->given_name,
                $registration->middle_initial ?? '',
                $registration->nickname ?? '',
                (string) $registration->age,
                $registration->gender,
                $registration->shirt_size,
                $registration->division,
                $registration->ministry,
                $registration->email,
                basename($proofPath),
                $registration->created_at?->toDateTimeString() ?? '',
                $registration->birthdate?->format('Y-m-d') ?? '',
            ];

            foreach ($values as $column => $value) {
                $cell = Coordinate::stringFromColumnIndex($column + 1).$row;
                $sheet->setCellValueExplicit($cell, $value, DataType::TYPE_STRING);
            }

            $proofExtension = strtolower(pathinfo($proofPath, PATHINFO_EXTENSION));
            if (
                in_array($proofExtension, ['jpg', 'jpeg', 'png'], true)
                && Storage::disk('local')->exists($proofPath)
            ) {
                $imagePath = Storage::disk('local')->path($proofPath);
                $imageInfo = @getimagesize($imagePath);

                if ($imageInfo !== false && in_array($imageInfo[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) {
                    $drawing = new Drawing;
                    $drawing
                        ->setPath($imagePath)
                        ->setCoordinates('L'.$row)
                        ->setResizeProportional(true)
                        ->setWidthAndHeight(170, 90)
                        ->setWorksheet($sheet);
                    $sheet->getRowDimension($row)->setRowHeight(72);
                }
            }

            $row++;
        }

        foreach (range('A', 'N') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }
        $sheet->getColumnDimension('L')->setAutoSize(false)->setWidth(26);

        return $spreadsheet;
    }

    public function toXlsx(iterable $registrations): string
    {
        $path = tempnam(sys_get_temp_dir(), 'convention-registrations-');
        if ($path === false) {
            throw new RuntimeException('Could not create a temporary Excel workbook.');
        }

        try {
            (new Xlsx($this->create($registrations)))->save($path);
            $contents = file_get_contents($path);
            if ($contents === false) {
                throw new RuntimeException('Could not read the generated Excel workbook.');
            }

            return $contents;
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}
