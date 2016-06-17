<?php


namespace Tagcade\Service;
use PHPExcel;
use PHPExcel_IOFactory;
use PHPExcel_Shared_Date;
use RecursiveIteratorIterator;

class Excel2CSVConverter implements Excel2CSVConverterInterface
{
    const FIRST_SHEET = 0;
    /**
     * @var string
     */
    protected $dataDir;
    /**
     * @var string
     */
    protected $processedDir;

    /**
     * Excel2CSVConverter constructor.
     * @param string $dataDir
     * @param string $processedDir
     */
    public function __construct($dataDir, $processedDir)
    {
        $this->dataDir = $dataDir;
        $this->processedDir = $processedDir;
    }

    public function convert(RecursiveIteratorIterator $files, $saveOriginal = true)
    {
        /** @var \SplFileInfo $file */
        foreach($files as $file) {
            if (!$this->isSupportedFile($file->getRealPath())) {
                continue;
            }

            $this->convertSingleFile($file);
        }
    }

    private function isSupportedFile($filePath)
    {
        $ext = pathinfo($filePath, PATHINFO_EXTENSION);
        return in_array($ext,['xls', 'xlsx']);
    }

    private function convertSingleFile(\SplFileInfo $file)
    {
        $objPHPExcel = PHPExcel_IOFactory::load($file->getRealPath());
        $sheetCount = $objPHPExcel->getSheetCount();
        if ($sheetCount === 0) {
            return;
        }

        for($i = 0; $i < $sheetCount; $i++) {
            $this->convertSingleSheet($file, $objPHPExcel, $i, $sheetCount === 1);
        }

        // remove the original file
        rename($file->getRealPath(), join('/', array($this->processedDir, $file->getBasename())) );
    }

    private function convertSingleSheet(\SplFileInfo $file, PHPExcel $excel, $sheetNumber, $singleSheet)
    {
        $sheet = $excel->getSheet($sheetNumber);
        $highestRow = $sheet->getHighestRow();
        $highestColumn = $sheet->getHighestColumn();
        $columns = range('A', $highestColumn);
        if ($singleSheet === false) {
            $fp = fopen(sprintf('%s.sheet%d.csv', $file->getRealPath(), $sheetNumber + 1), 'w');
        } else {
            $fp = fopen(sprintf('%s.csv', $file->getRealPath()), 'w');
        }

        //  Loop through each row of the worksheet in turn
        for ($row = 1; $row <= $highestRow; $row++) {
            $rowData = [];
            foreach($columns as $column) {
                $cell = $sheet->getCell($column. $row);

                if(PHPExcel_Shared_Date::isDateTime($cell)) {
                    $rowData[] = date('m/d/Y', PHPExcel_Shared_Date::ExcelToPHP($cell->getValue()));
                }
                else {
                    $rowData[] = $cell->getFormattedValue();
                }
            }

            fputcsv($fp, $rowData);
        }

        fclose($fp);
    }
}