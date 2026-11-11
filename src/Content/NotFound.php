<?php

declare(strict_types=1);

namespace ElSchneider\ContentMcp\Content;

use RuntimeException;

/**
 * Also thrown for objects the user may not see, so the model can't tell them apart.
 */
final class NotFound extends RuntimeException {}
