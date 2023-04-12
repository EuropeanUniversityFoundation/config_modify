<?php

declare(strict_types=1);

namespace Drupal\config_modify;

use Drupal\update_helper\Events\ConfigurationUpdateEvent;
use Drupal\update_helper\Events\UpdateHelperEvents;
use Drupal\update_helper\UpdateDefinitionInterface;
use Drupal\update_helper\Updater as OriginalUpdater;

/**
 * An altered updater that allows executing preloaded CUDs.
 */
class Updater extends OriginalUpdater {

  /**
   * {@inheritdoc}
   */
  public function executeUpdate($module, $update_definition_name) : bool {
    $this->warningCount = 0;

    $update_definitions = $this->configHandler->loadUpdate($module, $update_definition_name);
    $this->doExecuteUpdate($update_definitions);

    // Dispatch event after update has finished.
    $event = new ConfigurationUpdateEvent($module, $update_definition_name, $this->warningCount);
    // PHPStan can't handle Symfony's undeclared second parameter for Symfony 4
    // BC.
    // @phpstan-ignore-next-line
    $this->eventDispatcher->dispatch($event, UpdateHelperEvents::CONFIGURATION_UPDATE);

    return $this->warningCount === 0;
  }

  /**
   * Executes the updates contained in Config Update Definitions.
   *
   * @param array $update_definitions
   *   The update definitions.
   */
  public function doExecuteUpdate(array $update_definitions) : void {
    if (isset($update_definitions[UpdateDefinitionInterface::GLOBAL_ACTIONS])) {
      $this->executeGlobalActions($update_definitions[UpdateDefinitionInterface::GLOBAL_ACTIONS]);

      unset($update_definitions[UpdateDefinitionInterface::GLOBAL_ACTIONS]);
    }

    if (!empty($update_definitions)) {
      $this->executeConfigurationActions($update_definitions);
    }
  }

}
