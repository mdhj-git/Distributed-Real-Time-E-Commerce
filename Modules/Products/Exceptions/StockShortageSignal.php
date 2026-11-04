<?php

namespace Modules\Products\Exceptions;

use RuntimeException;

/**
 * Internal marker thrown inside reserve()'s transaction. It lets the
 * transaction roll back before the shortage details are read, and it never
 * escapes the module.
 */
class StockShortageSignal extends RuntimeException {}
