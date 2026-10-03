<?php

namespace App\Support\Spreadsheet;

use RuntimeException;

/** The uploaded file is not a readable .xlsx workbook (message is safe to show to the user). */
class XlsxException extends RuntimeException {}
