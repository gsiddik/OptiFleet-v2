<?php

namespace App\Support\Spreadsheet\Import;

use App\Domain\Shared\Support\Messages;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Upload checks shared by every Excel import: one .xlsx file (extension and detected MIME type), not
 * empty, at most ExcelImportEngine::MAX_FILE_KB. The workbook structure itself (zip, XML, sheets,
 * headers, formulas) is checked by ExcelImportEngine.
 */
trait ValidatesExcelUpload
{
    protected function validateFile(Request $request): void
    {
        $request->validate([
            'file' => ['required', 'file', 'max:'.ExcelImportEngine::MAX_FILE_KB, 'extensions:xlsx',
                'mimetypes:application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/zip,application/octet-stream'],
        ], [
            'file.required' => Messages::localized('excelImport.errors.fileRequired'),
            'file.extensions' => Messages::localized('excelImport.errors.onlyXlsx'),
            'file.mimetypes' => Messages::localized('excelImport.errors.onlyXlsx'),
            'file.max' => Messages::localized('excelImport.errors.fileTooLarge', ['maxMb' => ExcelImportEngine::MAX_FILE_KB / 1024]),
        ]);
        if ($request->file('file')->getSize() === 0) {
            throw ValidationException::withMessages(['file' => Messages::localized('excelImport.errors.fileEmpty')]);
        }
    }
}
