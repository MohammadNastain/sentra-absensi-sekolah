<?php

namespace App\Domain\Imports;

use RuntimeException;

/** File impor tidak layak diproses; pesannya aman ditampilkan ke user. */
final class ImportFileException extends RuntimeException {}
