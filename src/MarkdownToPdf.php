<?php

namespace AKlump\Documentation;

use mikehaertl\wkhtmlto\Pdf;
use Parsedown;
use Spatie\YamlFrontMatter\YamlFrontMatter;
use Twig_Environment;
use Twig_Loader_Filesystem;
use Webmozart\Glob\Glob;

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
      $items = Glob::glob($glob_dir . '/*.md');
      $markdown_filepaths = array_merge($markdown_filepaths, $items);
    }

    $markdown_filepaths = array_map('realpath', array_unique($markdown_filepaths));
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
  protected function getTemplateDirs() {
    return [__DIR__ . '/templates'];
  }

  /**
   * {@inheritdoc}
   */
  public function getCompiledHtml() {
    return $this->getTwig()->render('page.twig', [
      'page' => [
        'title' => 'Page Title',
        'styles' => $this->getCssStylesheets(),
      ],
    ]);
  }

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
    $contents = $this->fireEvent('fileload', file_get_contents($path_to_markdown_file));
    $contents = "---\n" . preg_replace("/^\-\-\-\n/s", '', $contents);
    $markdown = $this->fireEvent('markdown', YamlFrontMatter::parse($contents)
      ->body());
    $parsedown = new Parsedown();
    $html = $this->fireEvent('html', $parsedown->text($markdown));
    $twig = $this->getTwigForTokenReplacement();
    $template = $twig->createTemplate($html);
    $html = $template->render($this->getTokens());

    return $html;
  }

  /**
   * Return an array of tokens that can be used in html rendering.
   *
   * @return array
   *   This is an array that can be used by twig.
   */
  protected function getTokens() {
    return [];
  }

  /**
   * Return an array of URL variants to use for tokens.
   *
   * @param string $url
   *   A domain URL.
   *
   * @return array
   *   With these keys:
   *   - url string The url with trailing / stripped
   *   - pretty string The url with http:// stripped
   *   - link string An HTML <a> tag with the url as the target.
   */
  protected function getUrlTokens($url) {
    return [
      'url' => ($url = rtrim($url, '/')),
      'pretty' => ($clean = preg_replace('/^https?:\/\//', '', $url)),
      'link' => '<a href="' . $url . '">' . $clean . '</a>',
    ];
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
   * Resolve relative links in an html string to a given basepath.
   *
   * @param string $basepath
   *   The basepath to prepend to all relative links.
   * @param $html
   *   An html string containing one or more relative links.
   *
   * @return string|string[]|null
   */
  protected function resolveRelativeFilepathsInString($basepath, $html) {

    if (!is_dir($basepath)) {
      throw new \InvalidArgumentException("\"$basepath\" is not a directory.");
    }

    $images_dir = rtrim(rtrim($basepath, '/') . '/images', '/');
    $html = preg_replace_callback('/((?:href|src)=")(.+?)(")/', function ($matches) use ($basepath, $images_dir) {
      array_shift($matches);
      if (preg_match('/^images/', $matches[1])) {
        $matches[1] = str_replace("images/", "$images_dir/", $matches[1]);
      }
      elseif (!preg_match('/^http/', $matches[1])) {
        $matches[1] = rtrim($basepath, '/') . '/' . trim($matches[1], '/');
      }

      return implode($matches);
    }, $html);

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
  protected function fireEvent($event, $data) {
    $method = "onProcess$event";
    if (method_exists($this, $method)) {
      $data = $this->{$method}($data, $this->eventPath);
    }

    return $data;
  }

  /**
   * Return a configured Twig environment.
   *
   * @param array $options
   *   Options to use when creating environment.
   *
   * @return \Twig_Environment
   *   A twig environment with the template dirs loaded.
   *
   * @link https://twig.symfony.com/doc/1.x/api.html
   */
  protected function getTwig(array $options = []) {
    return new Twig_Environment(new Twig_Loader_Filesystem($this->getTemplateDirs()), $options);
  }

  /**
   * Return the Twig instance for use with token replacement.
   *
   * This is isolated so it can be extended to allow for custom options, such
   * as enabling autoescape, which has been disabled by default.
   *
   * @return \Twig_Environment
   *   A twig environment that will be used for replacing tokens in the HTML
   *   files.
   *
   * @link https://twig.symfony.com/doc/1.x/api.html
   */
  protected function getTwigForTokenReplacement() {
    return $this->getTwig(['autoescape' => FALSE]);
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

    return is_numeric($inches) ? round($inches * 25.4, 2) : 0;
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
      'page-size' => 'Letter',
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

      $header = [
        (string) $data->header->left,
        (string) $data->header->center,
        (string) $data->header->right,
      ];
      $has_header = !empty(trim(implode('', $header)));
      $header_spacing = 0;
      if ($has_header) {
        $header_spacing = $this->getInlineCssStyleValue('margin-bottom', $data->header, function ($value) {
          return $this->inchesToMm($value);
        });
      }

      $footer = [
        (string) $data->footer->left,
        (string) $data->footer->center,
        (string) $data->footer->right,
      ];
      $has_footer = !empty(trim(implode('', $footer)));
      $footer_spacing = 0;
      if ($has_footer) {
        $footer_spacing = $this->getInlineCssStyleValue('margin-top', $data->footer, function ($value) {
          return $this->inchesToMm($value);
        });
      }

      $min_header_footer_margin = 5;

      $page_top = $this->getInlineCssStyleValue('margin-top', $data, function ($value) {
        return $this->inchesToMm($value);
      });
      // If there is a header then we need to have a minium page top or the header won't show.
      $page_top = max($has_header ? $min_header_footer_margin : 0, $page_top);
      // For some reason the spacing doesn't seem to work right, so we try to normalize here.
      $page_top += $header_spacing;

      $page_bottom = $this->getInlineCssStyleValue('margin-top', $data, function ($value) {
        return $this->inchesToMm($value);
      });
      // If there is a header then we need to have a minium page top or the header won't show.
      $page_bottom = max($has_footer ? $min_header_footer_margin : 0, $page_bottom);
      $page_bottom += $footer_spacing;

      // Return the first value of a CSV string.
      $first_csv = function ($value) {
        $value = explode(',', $value);

        return reset($value);
      };

      $config = [
        // Spacing between footer and content in mm.
        'footer-spacing' => $footer_spacing,
        'footer-font-name' => $this->getInlineCssStyleValue('font-family', $data->footer, $first_csv),
        'footer-font-size' => $this->getInlineCssStyleValue('font-size', $data->footer, 'intval'),
        'footer-left' => $footer[0],
        'footer-center' => $footer[1],
        'footer-right' => $footer[2],
        'header-font-name' => $this->getInlineCssStyleValue('font-family', $data->header, $first_csv),
        'header-font-size' => $this->getInlineCssStyleValue('font-size', $data->header, 'intval'),
        'header-left' => $header[0],
        'header-center' => $header[1],
        'header-right' => $header[2],

        // Spacing between header and content in mm.
        'header-spacing' => $header_spacing,
        'margin-bottom' => $this->getInlineCssStyleValue('margin-bottom', $data, function ($value) {
          return $this->inchesToMm($value);
        }),
        'margin-top' => $page_top,
        'margin-bottom' => $page_bottom,
        'margin-left' => $this->getInlineCssStyleValue('margin-left', $data, function ($value) {
          return $this->inchesToMm($value);
        }),
        'margin-right' => $this->getInlineCssStyleValue('margin-right', $data, function ($value) {
          return $this->inchesToMm($value);
        }),
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
