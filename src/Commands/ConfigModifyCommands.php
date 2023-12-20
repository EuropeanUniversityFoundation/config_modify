<?php

namespace Drupal\config_modify\Commands;

use Drush\Commands\DrushCommands;

/**
 * Drush commands for the config modify module.
 */
class ConfigModifyCommands extends DrushCommands {

  /**
   * Register newly added configurations before updates are run.
   *
   * @usage config-modify:pre-update
   *   Register any newly added config modify files that match the current
   *   requirements as applied so that they don't cause errors during updates.
   *
   * @command config-modify:pre-update
   * @aliases cmpu
   */
  public function preUpdate() : void {
    \Drupal::service("config.installer")->markAvailableModificationsAsApplied();
  }

}
