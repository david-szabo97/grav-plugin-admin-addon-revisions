<?php
namespace Grav\Plugin;

use \Grav\Common\Grav;
use Grav\Common\Plugin;
use RocketTheme\Toolbox\Event\Event;
use Grav\Common\Filesystem\Folder;

class AdminAddonRevisionsPlugin extends Plugin {

  const SLUG = 'admin-addon-revisions';
  const PAGE_LOCATION = 'revisions';
  const CONFIG_KEY = 'plugins.' . self::SLUG;

  protected $directoryName;

  public static function getSubscribedEvents() {
    return [
      'onPluginsInitialized' => ['onPluginsInitialized', 0]
    ];
  }

  public function onPluginsInitialized() {
    $this->directoryName = $this->config->get(self::CONFIG_KEY . '.directory', '.revisions');

    // Add revisions directory to ignored folders
    $ignoreFolders = $this->config->get('system.pages.ignore_folders');
    $ignoreFolders[] = $this->directoryName;
    $this->config->set('system.pages.ignore_folders', $ignoreFolders);

    $this->enable([
      'onPageProcessed' => ['onPageProcessed', 0],
      'onAdminTwigTemplatePaths' => ['onAdminTwigTemplatePaths', 0],
      'onAdminTaskExecute' => ['onAdminTaskExecute', 0],
      'onTwigSiteVariables' => ['onTwigSiteVariables', 0],
      'onAdminMenu' => ['onAdminMenu', 0],
      'onAssetsInitialized' => ['onAssetsInitialized', 0],
    ]);
  }

  public function onAssetsInitialized() {
    $this->grav['assets']->addCss('plugin://' . self::SLUG . '/assets/style.css');
  }

  public function onAdminMenu() {
    $twig = $this->grav['twig'];
    $twig->plugins_hooked_nav = (isset($twig->plugins_hooked_nav)) ? $twig->plugins_hooked_nav : [];
    $twig->plugins_hooked_nav['Revisions'] = [
      'location' => self::PAGE_LOCATION,
      'icon' => 'fa-file-text'
    ];
  }

  public function onAdminTwigTemplatePaths($e) {
    $paths = $e['paths'];
    $paths[] = __DIR__ . DS . 'templates';
    $e['paths'] = $paths;
  }

  public function onTwigSiteVariables() {
    $twig = $this->grav['twig'];
    $page = $this->grav['page'];
    $uri = $this->grav['uri'];

    if ($page->slug() !== self::PAGE_LOCATION) {
      return;
    }

    $action = $uri->param('action');
    $page = $this->getCurrentPage();
    $twig->twig_vars['context'] = $page;
    $pageDir = $page->path();
    $revDir = $pageDir . DS . $this->directoryName;

    if ($action === 'diff') {
      $rev = $uri->param('rev');
      $currentFiles = $this->scandirForFiles($pageDir);
      $revFiles = $this->scandirForFiles($revDir . DS . $rev);

      $oldDir = $revDir . DS . $rev;
      $oldFiles = $revFiles;
      $newDir = $pageDir;
      $newFiles = $currentFiles;

      $added = [];
      $removed = [];
      $changed = [];
      $equal = [];

      // Find removed files
      foreach ($oldFiles as $oldFile) {
        if (array_search($oldFile, $newFiles) === false) {
          $removed[] = $oldFile;
        }
      }

      // Find added files
      foreach ($newFiles as $newFile) {
        if (array_search($newFile, $oldFiles) === false) {
          $added[] = $newFile;
        }
      }

      // Find changed and equal files
      foreach ($oldFiles as $oldFile) {
        $key = array_search($oldFile, $newFiles);
        if ($key !== false) {
          $newFile = $newFiles[$key];
          if (filesize($oldDir . DS . $oldFile) !== filesize($newDir . DS . $newFile) || md5_file($oldDir . DS . $oldFile) !== md5_file($newDir . DS . $newFile)) {
            $changed[] = $oldFile;
          } else {
            $equal[] = $oldFile;
          }
        }
      }

      // Process changes
      include __DIR__ . DS . 'class.Diff.php';
      $finfo = finfo_open(FILEINFO_MIME_TYPE);
      foreach ($changed as &$change) {
        $oldFile = $oldDir . DS . $change;
        $newFile = $newDir . DS . $change;

        $mime = finfo_file($finfo, $oldFile);
        if (strpos($mime, "text") === 0) {
          // Handle text files
          $change = ['filename' => $change, 'type' => 'text'];
          $diff = \Diff::compare(file_get_contents($oldFile), file_get_contents($newFile), true);
          $change['diff'] = $this->difftoHTML($diff);
        } else if (strpos($mime, "image") === 0) {
          // Handle image files
          $change = ['filename' => $change, 'type' => 'image'];
          $change['oldUrl'] = $this->filePathToUrl($oldFile);
          $change['newUrl'] = $this->filePathToUrl($newFile);
        } else {
          // Handle anything else
          $change = ['filename' => $change, 'type' => 'unknown'];
        }
      }
      finfo_close($finfo);

      $twig->twig_vars['added'] = $added;
      $twig->twig_vars['removed'] = $removed;
      $twig->twig_vars['changed'] = $changed;
      $twig->twig_vars['equal'] = $equal;
      $twig->twig_vars['revision'] = $rev;
    } else {
      if ($uri->basename() === self::PAGE_LOCATION) {
        $action = 'list-pages';
        $pages = $this->grav['pages']->instances();
        array_shift($pages);
        foreach ($pages as $k => &$page) {
          // Remove folders
          if (!$page->file()) {
            unset($pages[$k]);
            continue;
          }

          $dir = $page->path() . DS . $this->directoryName;
          if (file_exists($dir)) {
            // Decrement by one, which is the current revision
            $page->revisions = count(Util::scandirForDirectories($dir)) - 1;
          } else {
            $page->revisions = 0;
          }
        }
        $twig->twig_vars['revPages'] = $pages;
        $twig->twig_vars['context'] = null;
      } else {
        $action = 'list-revisions';
        if (file_exists($revDir)) {
          $twig->twig_vars['revisions'] = $this->scandirForDirectories($revDir);
        }
      }
    }

    $twig->twig_vars['action'] = $action;
  }

  public function onAdminTaskExecute($e) {
    $method = $e['method'];
    $uri = $this->grav['uri'];

    if ($method === 'taskRevDelete') {
      // TODO: Permission

      $rev = $uri->param('rev');
      if (!$rev) {
        // TODO: Message
        return false;
      }

      $page = $this->getCurrentPage();
      $pageDir = $page->path();
      $revsDir = $pageDir . DS . $this->directoryName;
      $revDir = $revsDir . DS . $rev;
      if (!file_exists($revDir) || !is_dir($revDir)) {
        // TODO: Message
        return false;
      }

      Folder::delete($revDir);
      return true;
    }

    return false;
  }

  public function onPageProcessed(Event $e) {
    $page = $e['page'];
    $this->debugMessage('--- Admin Addon Revision - Analyzing \'' . $page->title(). '\' ---');
    $pageDir = $page->path();
    $revDir = $pageDir . DS . $this->directoryName;

    // Make sure we have a revisions directory
    if (!file_exists($revDir)) {
      $this->debugMessage('-- Creating revision directory...');
      mkdir($revDir, 0770); // TODO: const / config
    }

    $changed = false;
    $revisions = $this->scandirForDirectories($revDir);
    if (!$revisions) {
      $this->debugMessage('-- No revisions found, save this one.');
      $changed = true;
    } else {
      $lastRev = end($revisions);

      $currentFiles = $this->scandirForFiles($pageDir);
      $lastRevDir = $revDir . DS . $lastRev;
      $lastRevFiles = $this->scandirForFiles($lastRevDir);
      if (count($currentFiles) !== count($lastRevFiles)) {
        $this->debugMessage('-- Number of files changed, save revision.');
        $changed = true;
      } else {
        foreach ($currentFiles as $curFile) {
          $key = array_search($curFile, $lastRevFiles);
          if ($key === false) {
            $this->debugMessage('-- File ' . $curFile . ' does not exist in the revision, save revision.');
            $changed = true;
            break;
          }
          $revFile = $revDir . DS . $lastRev . DS . $lastRevFiles[$key];
          $curFile = $pageDir . DS . $curFile;
          if (filesize($curFile) !== filesize($revFile) || md5_file($curFile) !== md5_file($revFile)) {
            $this->debugMessage('-- Content of ' . $curFile . ' changed, save revision.');
            $changed = true;
            break;
          }
        }
      }
    }

    if ($changed) {
      $this->debugMessage('-- Something changed, saving revision...');
      $newRevDir = $revDir . DS . date('Ymd-His'); // TODO: const
      if (file_exists($newRevDir)) {
        $this->debugMessage('-- Revision directory exists, skipping...');
        return;
      }

      mkdir($newRevDir);
      $currentFiles = $this->scandirForFiles($pageDir);
      foreach ($currentFiles as $file) {
        $path = $pageDir . DS . $file;
        // TODO: Handle directories?
        copy($path, $newRevDir . DS . $file);
      }

      // Limit number of revisions
      $deletedRevision = false;
      do {
        $deletedRevision = false;

        // Check for maximum count and delete the oldest revisions first
        $maximum = $this->config->get(self::CONFIG_KEY . '.limit.maximum', 0);
        if ($maximum) {
          // Increment by one because we don't want to count the current revision
          if (count($revisions) > $maximum + 1) {
            $firstRev = reset($revisions);
            $this->debugMessage('-- Deleting revision: ' . $firstRev . ', limit exceeded.');
            Folder::delete($revDir . DS . $firstRev);
            $deletedRevision = true;
          }
        }

        // Check for old revisions
        $older = $this->config->get(self::CONFIG_KEY . '.limit.older', null);
        if ($older) {
          $revisions = $this->scandirForDirectories($revDir);
          foreach ($revisions as $rev) {
            $time = $this->directoryToDate($rev);
            $olderTime = strtotime('-' . $older);
            if ($olderTime !== false && $older > $time) {
              $this->debugMessage('-- Deleting revision: ' . $rev . ', older than ' . $older . '.');
              Folder::delete($revDir . DS . $rev);
              $deletedRevision = true;
            }
          }
        }
      } while($deletedRevision);
    } else {
      $this->debugMessage('-- No changes.');
    }
  }

  private function debugMessage($msg) {
    $this->grav['debugger']->addMessage($msg);
  }

  private function diffToHTML($diff) {
    $html = '';

    foreach ($diff as $c) {
      switch ($c[1]) {
        case \Diff::UNMODIFIED:
          $html .= '' . $c[0];
          break;
        case \Diff::INSERTED:
          $html .= '<span class="inserted">' . $c[0]. '</span>';
          break;
        case \Diff::DELETED:
          $html .= '<span class="deleted">' . $c[0]. '</span>';
          break;
      }
    }

    return $html;
  }

  public function filePathToUrl($filePath) {
    return Grav::instance()['base_url'] . preg_replace('|^' . preg_quote(GRAV_ROOT) . '|', '', $filePath);
  }

  public function scandir($directory, $fileOnly = true) {
    $files = array_diff(scandir($directory), ['.', '..', $this->directoryName]);

    $files = array_filter($files, function($file) use($directory, $fileOnly) {
      $t = is_dir($directory . DS . $file) === !$fileOnly;
      return $t;
    });

    return $files;
  }

  public function scandirForFiles($directory) {
    return $this->scandir($directory, true);
  }

  public function scandirForDirectories($directory) {
    return $this->scandir($directory, false);
  }

  private function directoryToDate($dir) {
    $dir = basename($dir);

    $year = substr($dir, 0, 4);
    $month = substr($dir, 4, 2);
    $day = substr($dir, 6, 2);
    $hour = substr($dir, 9, 2);
    $minute = substr($dir, 11, 2);
    $second = substr($dir, 13, 2);

    $str = "$year-$month-$day $hour:$minute:$second";
    return strtotime($str);
  }

  private function getCurrentPage() {
    return $this->grav['admin']->page(true);
  }

}