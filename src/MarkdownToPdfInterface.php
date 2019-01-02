<?php

namespace AKlump\Documentation;

/**
 * Interface for converting markdown files to a pdf document.
 *
 * @package AKlump\ManualTest
 */
interface MarkdownToPdfInterface {

  /**
   * Get the project's title.
   *
   * @return string
   *   The name of the project.  May be used for titles, footers, etc.
   */
  public function getProjectTitle();

  /**
   * Return an array of markdown files to be used in PDF generation.
   *
   * - Implementations must apply filters to the result set returned.
   * - Implementations should validate each file at this point.
   *
   * @return array
   *   An array of absolute paths to markdown files.
   *
   * @see ::addFilter
   *
   * @throws \AKlump\Documentation\MarkdownSyntaxException
   *   When the format of a markdown file is invalid syntax.
   */
  public function getMarkdownFiles();

  /**
   * Render the HTML document based on all markdown files.
   *
   * @return string
   *   The rendered HTML document.
   */
  public function getCompiledHtml();

  /**
   * Save the PDF file of all markdown files.
   *
   * @param string $pdf_filepath
   *   The output path of the PDF file to generate.
   * @param bool $overwrite
   *   TRUE to replace existing file at $pdf_filepath.
   *
   * @return bool
   *   True if saved successfully; false otherwise.
   */
  public function saveCompiledPdfTo($pdf_filepath, $overwrite = FALSE);

  /**
   * Reduce the set of markdown files returned by ::getMarkdownFiles.
   *
   * @param callable $filter
   *   A callback that must return a list of filepaths.  What it receives as
   *   arguments is up to the implementing class.  ::getMarkdownFiles should
   *   call all filters and only return those files that make it through all of
   *   them.
   *
   * @return \AKlump\ManualTest\MarkdownToPdfInterface
   *   An instance of self for chaining.
   *
   * @see ::getMarkdownFiles
   */
  public function addFilter(callable $filter);

  /**
   * Remove all filters affecting ::getMarkdownFiles.
   *
   * @return \AKlump\ManualTest\MarkdownToPdfInterface
   *   An instance of self for chaining.
   */
  public function removeFilters();

}
