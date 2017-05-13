<?php
namespace AdminAddonRevisions;

class TaskHandler {

  public function __construct($plugin) {
    $this->plugin = $plugin;
    $this->admin = $plugin->grav()['admin'];
    $this->uri = $plugin->grav()['uri'];
  }

  public function execute($method) {
    if (method_exists($this, $method)) {
      return call_user_func([$this, $method]);
    }

    return false;
  }

  public function taskRevDelete() {
    // TODO: Permission

    $rev = $this->uri->param('rev');
    if (!$rev) {
      // TODO: Message
      return false;
    }

    $page = $this->plugin->getCurrentPage();
    $revision = new Revision($page, $rev);
    if (!$revision->exists()) {
      // TODO: Message
      return false;
    }

    $revision->delete();

    return true;
  }

}