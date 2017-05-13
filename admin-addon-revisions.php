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
    ]);
  }

  public function onPageProcessed(Event $e) {
    $page = $e['page'];
    $this->debugMessage('--- Admin Addon Revision - Analyzing \'' . $page->title(). '\' ---');
    $pageDir = $page->path();
    $revDir = $pageDir . DS . self::DIR;

    // Make sure we have a revisions directory
    if (!file_exists($revDir)) {
      $this->debugMessage('-- Creating revision directory...');
      mkdir($revDir, 0770);
    }

    $changed = false;
    $revisions = array_diff(scandir($revDir), self::SCAN_EXCLUDE);
    if (!$revisions) {
      $this->debugMessage('-- No revisions found, save this one.');
      $changed = true;
    } else {
      $lastRev = end($revisions);

      $currentFiles = array_diff(scandir($pageDir), self::SCAN_EXCLUDE);
      $lastRevDir = $revDir . DS . $lastRev;
      $lastRevFiles = array_diff(scandir($lastRevDir), self::SCAN_EXCLUDE);
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
      $newRevDir = $revDir . DS . date('Ymd-His');
      if (file_exists($newRevDir)) {
        $this->debugMessage('-- Revision directory exists, skipping...');
        return;
      }

      mkdir($newRevDir);
      $currentFiles = array_diff(scandir($pageDir), self::SCAN_EXCLUDE);
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

}