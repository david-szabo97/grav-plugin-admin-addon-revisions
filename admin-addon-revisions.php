<?php
namespace Grav\Plugin;

use \Grav\Common\Grav;
use Grav\Common\Plugin;
use RocketTheme\Toolbox\Event\Event;
use Grav\Common\Filesystem\Folder;

class AdminAddonRevisionsPlugin extends Plugin {

  const DIR = '.revisions';
  const SCAN_EXCLUDE = ['.', '..', self::DIR];

  public static function getSubscribedEvents() {
    return [
      'onPluginsInitialized' => ['onPluginsInitialized', 0]
    ];
  }

  public function onPluginsInitialized() {
    $uri = $this->grav['uri'];

    $this->enable([
      'onPageProcessed' => ['onPageProcessed', 0],
      'onAdminTwigTemplatePaths' => ['onAdminTwigTemplatePaths', 0],
      'onAdminTaskExecute' => ['onAdminTaskExecute', 0],
      'onTwigSiteVariables' => ['onTwigSiteVariables', 0],
      'onAdminMenu' => ['onAdminMenu', 0],
    ]);
  }

  public function onAdminMenu() {
    $twig = $this->grav['twig'];
    $twig->plugins_hooked_nav = (isset($twig->plugins_hooked_nav)) ? $twig->plugins_hooked_nav : [];
    $twig->plugins_hooked_nav['Revisions'] = [
      'location' => 'revisions',
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

    if ($page->slug() !== 'revisions') {
      return;
    }

    $action = $this->grav['uri']->param('action');
    $page = $this->grav['admin']->page(true);
    $twig->twig_vars['context'] = $page;
    $pageDir = $page->path();
    $revDir = $pageDir . DS . self::DIR;

    if ($action === 'diff') {
      $rev = $this->grav['uri']->param('rev');
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

      include __DIR__ . DS . 'class.Diff.php';
      $finfo = finfo_open(FILEINFO_MIME_TYPE);
      foreach ($changed as &$change) {
        $oldFile = $oldDir . DS . $change;
        $newFile = $newDir . DS . $change;

        $mime = finfo_file($finfo, $oldFile);
        if (strpos($mime, "text") === 0) {
          // Handle text files
          $change = ['filename' => $change, 'type' => 'text'];
          $diff = \Diff::compare( file_get_contents($oldFile), file_get_contents($newFile), true);
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

      $twig->twig_vars['added'] = $added;
      $twig->twig_vars['removed'] = $removed;
      $twig->twig_vars['changed'] = $changed;
      $twig->twig_vars['equal'] = $equal;
      $twig->twig_vars['revision'] = $rev;
    } else {
      if ($this->grav['uri']->basename() === 'revisions') {
        $action = 'list-pages';
        $pages = $this->grav['pages']->instances();
        array_shift($pages);
        foreach ($pages as &$page) {
          $dir = $page->path() . DS . self::DIR;
          if (file_exists($dir)) {
            $page->revisions = count($this->scandirForDirectories($dir));
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

    if ($e['method'] === 'taskRevDelete') {
      // TODO: Permission

      $rev = $this->grav['uri']->param('rev');
      if (!$rev) {
        // TODO: Message
        return false;
      }

      $page = $this->grav['admin']->page(true);
      $pageDir = $page->path();
      $revsDir = $pageDir . DS . self::DIR;
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
    $revDir = $pageDir . DS . self::DIR;

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
        if (is_dir($path)) {
          // TODO: Handle directories?
          $this->debugMessage('-- Found dir: '. $path .' .');
        } else {
          copy($path, $newRevDir . DS . $file);
        }
      }
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
    $files = array_diff(scandir($directory), self::SCAN_EXCLUDE);

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

}