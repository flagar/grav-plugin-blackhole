<?php
namespace Grav\Plugin\Console;

use Grav\Common\Grav;
use Grav\Console\ConsoleCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Grav\Common\Page\Page;
use Grav\Common\Filesystem\Folder;

require __DIR__ . '/../functions.php';
require __DIR__ . '/../vendor/RollingCurl/Request.php';
require __DIR__ . '/../vendor/RollingCurl/RollingCurl.php';

class GenerateCommand extends ConsoleCommand {
  protected $options = [];

  protected function configure() {
    $this
    ->setName("g")
    ->setName("gen")
    ->setName("generate")
    ->setDescription("Generate your static site.")
    ->addArgument(
      'input-url',
      InputArgument::REQUIRED,
      'Enter the URL to your live Grav site.'
    )
    ->addOption(
      'output-url',
      'd',
      InputOption::VALUE_REQUIRED,
      'The URL of your static site. This determines the domain used in the absolute path of your links.'
    )
    ->addOption(
      'output-path',
      'p',
      InputOption::VALUE_REQUIRED,
      'The directory to which your static site will be written (relative to Grav root).'
    )
    ->addOption(
      'routes',
      'r',
      InputOption::VALUE_REQUIRED,
      'Limit generation to a select list of page routes.'
    )
    ->addOption(
      'simultaneous',
      's',
      InputOption::VALUE_OPTIONAL,
      'Determine how many files will generate at the same time.',
      10
    )
    ->addOption(
      'assets',
      'a',
      InputOption::VALUE_NONE,
      'Copy assets to the output path.'
    )
    ->addOption(
      'taxonomy',
      't',
      InputOption::VALUE_NONE,
      'Process the taxonomy map.'
    )
    ->addOption(
      'force',
      'f',
      InputOption::VALUE_NONE,
      'Overwrite previously generated files.'
    )
    ->addOption(
      'directories-copy',
      'dc',
      InputOption::VALUE_REQUIRED,
      'Comma separated list of directories to copy (URIs relative to Grav root).'
    );
  }

    /**
     * Generate localized route based on the translated slugs found through the pages hierarchy
     */
    protected function _getTranslatedUrl($lang, $path)
    {
        $translated_url_parts = array();
        $grav = Grav::instance();
        $pages = $grav['pages'];
        $page = $pages->get($path);
        $current_node = $page;
        $max_recursions = 10;
        while ($max_recursions > 0 && $current_node->slug() != 'pages' && $path != 'pages') {
            $translated_md_filepath = "{$path}/{$current_node->template()}.{$lang}.md";
            if (file_exists($translated_md_filepath)) {
                //$grav['language']->setActive($lang);
                $translated_page = new Page();
                $translated_page->init(new \SplFileInfo($translated_md_filepath));
                //$translated_page->filePath($translated_md_filepath);
                $translated_slug = $translated_page->slug();
                if (!empty($translated_slug)) {
                    array_unshift($translated_url_parts, $translated_slug);
                } else {
                    $untranslated_slug = $current_node->slug();
                    if (!empty($untranslated_slug)) {
                        array_unshift($translated_url_parts, $untranslated_slug);
                    }
                }
                $current_node = $current_node->parent();
                $path = dirname($path);
            }
            $max_recursions--;
        }
        if (!empty($translated_url_parts)) {
            //array_unshift($translated_url_parts, $lang);
            array_unshift($translated_url_parts, '');
            return implode('/', $translated_url_parts);
        } else {
            return '';
        }
    }

  protected function serve() {

    $start = microtime(true);
    $grav = Grav::instance();
    $grav['pages']->init();

    $this->options = [
      'input-url'    => $this->input->getArgument('input-url'),
      'output-url'   => $this->input->getOption('output-url'),
      'output-path'  => $this->input->getOption('output-path'),
      'routes'       => $this->input->getOption('routes'),
      'simultaneous' => $this->input->getOption('simultaneous'),
      'assets'       => $this->input->getOption('assets'),
      'taxonomy'     => $this->input->getOption('taxonomy'),
      'force'        => $this->input->getOption('force'),
      'directories-copy' => $this->input->getOption('directories-copy')
    ];
    $input_url    = $this->options['input-url'];
    $output_url   = $this->options['output-url'];
    $output_path  = $this->options['output-path'];
    $routes       = $this->options['routes'];
    $simultaneous = $this->options['simultaneous'];
    $assets       = $this->options['assets'];
    $taxonomy     = $this->options['taxonomy'];
    $force        = $this->options['force'];
    $directories_copy = $this->options['directories-copy'];

    // default output path
    $event_horizon = GRAV_ROOT . '/';
    // set user defined output path
    if (!empty($output_path)) $event_horizon .= $output_path;
    // make output path
    if (!is_dir(dirname($event_horizon))) mkdir(dirname($event_horizon), 0755, true);
    // get page routes
    $pages = $grav['pages']->routes();
    $pageCount = count((array)$pages);
    foreach ($pages as $path => $dir) {
      if (strpos($path, '/_') !== false) {
        unset($pages[$path]);
      } elseif (!empty($routes)) {
        $pages2 = array(); $pages2['/'.$path] = '';
        $pages = array_intersect_key((array)$pages, $pages2);
      }
    }

    // override pages array with translated routes if more than 1 language is found
    $languages = $grav['language']->getLanguages();
    if (is_array($languages) && count($languages)>1) {
        $sitemap = array();
        $pages_new = array();
        foreach ($languages as $lang_k => $language) {
            foreach ($pages as $page_slug => $page_path) {
                $page_key = sprintf('/%s%s', $language, $this->_getTranslatedUrl($language, $page_path));
                $page_key_0 = sprintf('/%s/%s', $languages[0], trim($page_slug,'/'));
                if (array_key_exists($page_key_0, $sitemap) && is_array($sitemap[$page_key_0])) {
                    $sitemap[$page_key_0][$language] = $page_key;
                } else {
                    $sitemap[$page_key_0] = array(
                        $language => $page_key
                    );
                }
                $pages_new[$page_key] = $page_path;
            }
        }
        $pages = $pages_new;
    }

    // get taxonomy map
    $taxonomies = $grav['taxonomy']->taxonomy();
    $taxonomyCount = count((array)$taxonomies);

    /* generate pages
    ----------------- */
    if ($pageCount) {
      //$this->output->writeln(print_r($pages, true));
      $rollingCurl = new \RollingCurl\RollingCurl();
      foreach ($pages as $grav_slug => $grav_file_path) {
        $request = new \RollingCurl\Request($input_url . $grav_slug);
        $request->event_horizon = $event_horizon;
        $request->grav_file_path = $grav_file_path;
        $request->bh_route = preg_replace('/\/\/+/', '/', $event_horizon . $grav_slug);
        $request->bh_file_path = preg_replace('/\/\/+/', '/', $request->bh_route . '/index.html');
        $request->input_url = $input_url;
        $request->output_url = $output_url;
        $request->simultaneous = (int)$simultaneous < 1 ? 1 : (int)$simultaneous;
        $request->assets = $assets;
        $request->taxonomy = $taxonomy;
        $request->force = $force;
        $rollingCurl->add($request);
      }
      $rollingCurl
        ->setCallback(function(\RollingCurl\Request $request, \RollingCurl\RollingCurl $rollingCurl) {
          $grav_page_data = $request->getResponseText();
          // generate assets
          if ($request->assets) assets($this->output, $request->event_horizon, $request->input_url, $grav_page_data);
          // swap links
          $grav_page_data = portal($request->input_url, $request->output_url, $grav_page_data);
          // generate pages
          pages($this->output, $request->bh_route, $request->bh_file_path, $request->grav_file_path, $grav_page_data, $request->force);
          // clear list of completed requests and prune pending request queue to avoid memory growth
          $rollingCurl->clearCompleted();
          $rollingCurl->prunePendingRequestQueue();
        })
        ->setSimultaneousLimit($request->simultaneous)
        ->execute()
      ;
    } else {
      $this->output->writeln('<red>ERROR</red> No pages were found');
      die();
    }

    /* generate taxonomies
    ---------------------- */
    if ($taxonomy) {
      if ($taxonomyCount) {
        $rollingCurl = new \RollingCurl\RollingCurl();
        foreach ($taxonomies as $taxonomy_parent => $taxonomy_children) {
          foreach ($taxonomy_children as $taxonomy_child => $pages_in_taxonomy) {
            $taxonomy_url  = $input_url . '/' . $taxonomy_parent . ':' . $taxonomy_child;
            $request = new \RollingCurl\Request($taxonomy_url);
            $request->taxonomy_path = $event_horizon . '/' . $taxonomy_parent . '/' . $taxonomy_child;
            $request->input_url = $input_url;
            $request->output_url = $output_url;
            $request->simultaneous = (int)$simultaneous < 1 ? 1 : (int)$simultaneous;
            $rollingCurl->add($request);
          }
        }
        $rollingCurl
          ->setCallback(function(\RollingCurl\Request $request, \RollingCurl\RollingCurl $rollingCurl) {
            $grav_page_data = $request->getResponseText();
            // swap links
            $grav_page_data = portal($request->input_url, $request->output_url, $grav_page_data);
            // generate taxonomy
            taxonomies($this->output, $request->taxonomy_path, $request->taxonomy_path . '/index.html', $grav_page_data);
            // clear list of completed requests and prune pending request queue to avoid memory growth
            $rollingCurl->clearCompleted();
            $rollingCurl->prunePendingRequestQueue();
          })
          ->setSimultaneousLimit($request->simultaneous)
          ->execute()
        ;
      } else {
        $this->output->writeln('<red>ERROR</red> No taxonomies were found');
      }
    }

    /* copying some folders */
    if (!empty($directories_copy)) {
        $dirs_to_copy = explode(',', $directories_copy);
        foreach ($dirs_to_copy as $dir_to_copy) {
            Folder::copy(trim($dir_to_copy,'/'), rtrim($output_path, ' /').'/'.trim($dir_to_copy,'/'));
        }
    }

    /* generate sitemap */
    $pages_to_exclude = array(
        'i-nostri-clienti',
        'mercati-settori-di-applicazione',
        'soluzioni-dam',
        'chi-siamo',
        'dam-solutions',
        'who-we-are',
        'our-clients',
        'dam-industries',
        'qui-sommes-nous',
        'solutions-DAM',
        'clients',
        'secteurs-du-dam'
    );
    $languages = $grav['language']->getLanguages();
    if (is_array($languages) && count($languages)>1) {
        $urlset = new \SimpleXMLElement('<urlset></urlset>');
        $urlset->addAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $urlset->addAttribute('xmlns:xhtml', 'http://www.w3.org/1999/xhtml');
        $sitemap_output_baseurl = rtrim($output_url, ' /');
        foreach ($sitemap as $url_loc => $url_i18n) {
            if (!in_array(end(explode('/', $url_loc)), $pages_to_exclude)) {
                $url = $urlset->addChild('url');
                $url->addChild('loc', $sitemap_output_baseurl.$url_loc.'/');
                if (is_array($url_i18n)) {
                    foreach ($url_i18n as $url_i18n_lang => $url_i18n_href) {
                        //echo $url_i18n_href.PHP_EOL;
                        $link = $url->addChild('xhtml:link');
                        $link->addAttribute('rel', 'alternate');
                        $link->addAttribute('hreflang', $url_i18n_lang);
                        $link->addAttribute('href', $sitemap_output_baseurl.$url_i18n_href.'/');
                    }
                }
                $url->addChild('lastmod', date('Y-m-d'));
            }
        }
        file_put_contents(sprintf('%s/sitemap.xml', rtrim($output_path,' /')), $urlset->asXML());
    }

    /* generate php redirects if home alias is not empty */
    if (is_array($languages) && count($languages)>1) {
        if (!empty($grav['config']['system']['home']['alias'])) {
            foreach ($languages as $language) {
                $redirect_contents = sprintf(
                    '<?php'.PHP_EOL.'header("Location: %s");'.PHP_EOL.'?>',
                    trim($grav['config']['system']['home']['alias'], '/')
                );
                file_put_contents(sprintf(
                    '%s/%s/index.php',
                    rtrim($output_path, ' /'),
                    $language
                ), $redirect_contents);
            }
            $main_redirect_contents = sprintf(
                '<?php'.PHP_EOL.'header("Location: %s/%s");'.PHP_EOL.'?>',
                $languages[0],
                trim($grav['config']['system']['home']['alias'], '/')
            );
            file_put_contents(sprintf(
                '%s/index.php',
                rtrim($output_path, ' /')
            ), $main_redirect_contents);
        }
    }

    /* done
    ------- */
    $this->output->writeln(
      '<green>DONE</green> Blackhole processed ' .
      $pageCount . ' page' . ($pageCount !== 1 ? 's' : '') .
      ($taxonomy && $taxonomyCount ? ' and ' . $taxonomyCount . ' taxonom' . ($taxonomyCount !== 1 ? 'ies' : 'y') : '') .
      ' in ' . (microtime(true) - $start) . ' seconds'
    );
  }
}
