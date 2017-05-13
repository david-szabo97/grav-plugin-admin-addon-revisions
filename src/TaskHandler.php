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
    $messages = $this->plugin->grav()['messages'];
    // TODO: Permission

    $rev = $this->uri->param('rev');
    if (!$rev) {
      $messages->add("Revision param is missing", 'error');
      $this->plugin->grav()->redirect($this->uri->url());
      return false;
    }

    $page = $this->plugin->getCurrentPage();
    $revision = new Revision($page, $rev);
    if (!$revision->exists()) {
      $messages->add("Revision not found", 'error');
      $this->plugin->grav()->redirect($this->uri->url());
      return false;
    }

    $revision->delete();

    $messages->add("Succesfully deleted the '$rev' revision", 'info');
    $this->plugin->grav()->redirect($this->uri->url());

    return true;
  }

  public function taskRevRevert() {
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