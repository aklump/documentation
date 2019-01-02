<?php

namespace AKlump\Documentation;

use Throwable;

/**
 * Thrown when a markdown file doesn't match the expected format.
 */
class MarkdownSyntaxException extends \Exception {

  /**
   * Construct the exception.
   *
   * @param string $filepath
   *   The filepath of the bad syntax file.
   * @param string $message
   *   [optional] The Exception message to throw.
   * @param int $code
   *   [optional] The Exception code.
   * @param Throwable $previous
   *   [optional] The previous throwable used for the exception chaining.
   */
  public function __construct($filepath, $message = "", $code = 0, Throwable $previous = NULL) {
    return parent::__construct('Problem in file "' . basename($filepath) . '": ' . $message, $code, $previous);
  }

}
