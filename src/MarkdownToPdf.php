<?php

namespace AKlump\Documentation;

use mikehaertl\wkhtmlto\Pdf;
use Parsedown;
use Spatie\YamlFrontMatter\YamlFrontMatter;
use Twig_Environment;
use Twig_Loader_Filesystem;

/**
 * Parent class for solutions that convert markdown files to PDF.
 *
 * This class uses wkhtmltopdf, which must be installed.
 *
 * @link https://wkhtmltopdf.org/downloads.html
 */
abstract class MarkdownToPdf implements MarkdownToPdfInterface {

  /**
   * Holds the filepath for the events fired by fireEvent.
   *
   * @var string
   */
  protected $eventPath;

  /**
   * Holds all the added callables (filters).
   *
   * @var array
   */
  protected $filters = [];

  /**
   * An array of absolute dirs that should be searched for .md files.
   *
   * These values will be passed through glob().
   *
   * @var array
   */
  protected $markdownGlobDirs = [];

  /**
   * {@inheritdoc}
   *
   * Child classes must populate $this->markdownGlobDirs before calling this.
   *
   * @throws \RuntimeException
   *   - If $this->markdownGlobDirs is empty.
   *   - If there are no markdown files in $this->markdownGlobDirs.
   */
  public function getMarkdownFiles() {
    $markdown_filepaths = [];
    if (empty($this->markdownGlobDirs)) {
      throw new \RuntimeException("\$this->markdownGlobDirs cannot be empty.");
    }
    foreach ($this->markdownGlobDirs as $glob_dir) {
      $items = glob($glob_dir . '/*.md');
      $markdown_filepaths = array_merge($markdown_filepaths, $items);
    }

    $markdown_filepaths = array_unique($markdown_filepaths);
    if (empty($markdown_filepaths)) {
      throw new \RuntimeException("There are no source files to convert.");
    }

    return $markdown_filepaths;
  }

  /**
   * Get all stylesheets to be used in the HTML generation.
   *
   * @return array
   *   An array of stylesheet filepaths.
   */
  protected function getCssStylesheets() {
    $styles = [];
    foreach ($this->getTemplateDirs() as $template_dir) {
      if (is_file($template_dir . '/style.css')) {
        $styles['style.css'] = $template_dir . '/style.css';
      }
    }

    return $styles;
  }

  /**
   * Return all directories containing twig templates.
   *
   * @return array
   *   An array of paths to directories to be searched for Twig templates.
   */
  abstract protected function getTemplateDirs();

  /**
   * {@inheritdoc}
   */
  public function saveCompiledPdfTo($pdf_filepath, $overwrite = FALSE) {
    if (!$overwrite && file_exists($pdf_filepath)) {
      return FALSE;
    }
    $pdf = new Pdf($this->getWkHtmlToPdfConfig());
    $pdf->addPage($this->getCompiledHtml());

    return $pdf->saveAs($pdf_filepath);
  }

  /**
   * Return metadata for a single markdown file.
   *
   * @param string $path_to_markdown_file
   *   Path to the source file.
   *
   * @return array
   *   An array of metadata; this has been altered by onProcessMeta.
   *
   * @see ::onProcessMeta
   */
  protected function getSourceFileMeta($path_to_markdown_file) {
    // Add the leading '---' to make valid frontmatter for lazy authors.
    $contents = "---\n" . preg_replace("/^\-\-\-\n/s", '', file_get_contents($path_to_markdown_file));

    return YamlFrontMatter::parse($contents)->matter();
  }

  /**
   * Return HTML for a single markdown file.
   *
   * @param string $path_to_markdown_file
   *   Path to a single markdown file.
   *
   * @return string
   *   The html to use for the testcase.
   *
   * @throws \Throwable
   * @throws \Twig_Error_Loader
   * @throws \Twig_Error_Syntax
   *
   * @see ::onProcessLoaded
   * @see ::onProcessMarkdown
   * @see ::onProcessHTML
   */
  protected function getSourceFileHtml($path_to_markdown_file) {
    $this->eventPath = $path_to_markdown_file;
    $contents = $this->fireEvent('loaded', file_get_contents($path_to_markdown_file));
    $contents = "---\n" . preg_replace("/^\-\-\-\n/s", '', $contents);
    $markdown = $this->fireEvent('markdown', YamlFrontMatter::parse($contents)
      ->body());
    $parsedown = new Parsedown();
    $html = $this->fireEvent('html', $parsedown->text($markdown));
    $twig = $this->getTwig();
    $template = $twig->createTemplate($html);
    $html = $template->render([]);

    return $html;
  }

  /**
   * Modify the loaded content for a SINGLE markdown file before parsing.
   *
   * @param string $content
   *   The raw file content.
   * @param string $source_path
   *   The filepath to the source markdown file.
   *
   * @return string
   *   The (altered) file contents.
   */
  protected function onProcessFileLoad($content, $source_path) {
    return $content;
  }

  /**
   * Modify markdown for a SINGLE file before conversion to HTML.
   *
   * @param string $markdown
   *   The markdown portion of the file (frontmatter removed).
   * @param string $source_path
   *   The filepath to the source markdown file.
   *
   * @return string
   *   The (altered) markdown.
   */
  protected function onProcessMarkdown($markdown, $source_path) {
    return $markdown;
  }

  /**
   * Modify HTML for a SINGLE file.
   *
   * @param string $html
   *   The HTML resulting from the markdown conversion.
   * @param string $source_path
   *   The filepath to the source markdown file.
   *
   * @return string
   *   The (altered) markdown.
   */
  protected function onProcessHtml($html, $source_path) {
    return $html;
  }

  /**
   * Fire a mutation event.
   *
   * @param string $event
   *   Something like 'loaded'.
   * @param mixed $data
   *   The data to send to the mutator.
   *
   * @return mixed
   *   The data returned from the mutator.
   */
  private function fireEvent($event, $data) {
    $method = "onProcess$event";
    if (method_exists($this, $method)) {
      $data = $this->{$method}($data, $this->eventPath);
    }

    return $data;
  }

  /**
   * Return a configured Twig environment.
   *
   * @return \Twig_Environment
   *   A twig environment with the template dirs loaded.
   */
  protected function getTwig() {
    return new Twig_Environment(new Twig_Loader_Filesystem($this->getTemplateDirs()));
  }

  /**
   * Convert a string value of inches to mm.
   *
   * @param string|int|float $inches
   *   E.g. '1in', 1, .5, '.5in'.
   *
   * @return float
   *   The value in MM.
   */
  private function inchesToMm($inches) {
    $inches = preg_replace('/[^\d\.]/', '', $inches);

    return round($inches * 25.4, 2);
  }

  /**
   * Get the value of a CSS style in the style attribute of a node.
   *
   * @param string $style_key
   *   The name of the CSS style.
   * @param \SimpleXMLElement $node
   *   The XML node, e.g $pdf->page, $pdf->page->header.
   * @param callable $mutator
   *   Optional callback to process the returned value.
   *
   * @return mixed
   *   The value of the CSS inline style.
   */
  private function getInlineCssStyleValue($style_key, \SimpleXMLElement $node, callable $mutator = NULL) {
    $style = (string) $node->attributes()->style;
    $style = explode(';', (string) $style);
    $value = array_reduce($style, function ($carry, $value) use ($style_key) {
      list($k, $v) = explode(':', $value);
      if (trim($k) === trim($style_key)) {
        return $carry . trim($v);
      }

      return $carry;
    });
    if ($mutator) {
      $value = $mutator($value);
    }

    return $value;
  }

  /**
   * Get the wkhtmltopdf configuration array as defined by our templates.
   *
   * @return array
   *   The configuration data based on templates.
   *
   * @throws \Twig_Error_Loader
   * @throws \Twig_Error_Runtime
   * @throws \Twig_Error_Syntax
   *
   * @link https://wkhtmltopdf.org/usage/wkhtmltopdf.txt
   */
  protected function getWkHtmlToPdfConfig() {
    $default_config = [
      0 => 'enable-forms',
    ];

    try {
      $xml = $this->getTwig()->render('pdf.twig', [
        'pageNumber' => '[page]',
        'totalPages' => '[toPage]',
        'project' => [
          'name' => $this->getProjectTitle(),
        ],
      ]);
    }
    catch (\Exception $exception) {
      // There is no problem if pdf.twig does not exist.
    }

    if (!empty($xml)) {
      $data = simplexml_load_string($xml);

      $header_spacing = $this->getInlineCssStyleValue('margin-bottom', $data->header, function ($value) {
        return $this->inchesToMm($value);
      });

      // For some reason the spacing doesn't seem to work right, so we try to normalize here.
      $header_spacing *= .66;
      $page_top = $this->getInlineCssStyleValue('margin-top', $data, function ($value) {
        return $this->inchesToMm($value);
      });
      $page_top += $header_spacing;

      // Return the first value of a CSV string.
      $first_csv = function ($value) {
        $value = explode(',', $value);

        return reset($value);
      };

      $config = [
        'footer-center' => (string) $data->footer->center,
        'footer-font-name' => $this->getInlineCssStyleValue('font-family', $data->footer, $first_csv),
        'footer-font-size' => $this->getInlineCssStyleValue('font-size', $data->footer, 'intval'),
        'footer-left' => (string) $data->footer->left,
        'footer-right' => (string) $data->footer->right,
        'header-center' => (string) $data->header->center,
        'header-font-name' => $this->getInlineCssStyleValue('font-family', $data->header, $first_csv),
        'header-font-size' => $this->getInlineCssStyleValue('font-size', $data->header, 'intval'),
        'header-left' => (string) $data->header->left,
        'header-right' => (string) $data->header->right,
        'header-spacing' => $header_spacing,
        'margin-bottom' => $this->getInlineCssStyleValue('margin-bottom', $data, function ($value) {
          return $this->inchesToMm($value);
        }),
        'margin-top' => $page_top,
      ];
      $config += $default_config;
    }

    return $config;
  }


  /**
   * {@inheritdoc}
   */
  public function addFilter(callable $filter) {
    $this->filters[] = $filter;
  }

  /**
   * {@inheritdoc}
   */
  public function removeFilters() {
    $this->filters = [];
  }

}
