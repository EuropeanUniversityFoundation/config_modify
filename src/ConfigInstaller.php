<?php

declare(strict_types=1);

namespace Drupal\config_modify;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ConfigInstaller as OriginalConfigInstaller;
use Drupal\Core\Config\ConfigManagerInterface;
use Drupal\Core\Config\ExtensionInstallStorage;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Extension\ExtensionPathResolver;
use Drupal\update_helper\UpdateDefinitionInterface;
use Drupal\update_helper\Updater;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Installer for altered optional config.
 *
 * Extends Drupal's config installer to hook into the process after optional
 * config is installed so that we can make any install-time alterations to
 * config that is defined by modules.
 *
 * @phpstan-type ConfigDependencies (array{config: string[], modules?: string[], themes?: string[]}|array{config?: string[], modules: string[], themes?: string[]}|array{config?: string[], modules?: string[], themes: string[]})
 * @phpstan-type GlobalUpdateActions array{install_modules?: string[], install_themes?: string[], import_configs?: string[]}
 * @phpstan-type ConfigUpdateActions array{add?: array<string, mixed>, change?: array<string, mixed>, delete?: array<string, mixed>}
 * @phpstan-type ConfigUpdateDefinitions array<string, array{expected_config: array<string, mixed>, update_actions: non-empty-array<string, ConfigUpdateActions>}>
 * @phpstan-type ModifyDefinition array{dependencies?: ConfigDependencies, __global_actions?: GlobalUpdateActions, items: ConfigUpdateDefinitions}
 */
class ConfigInstaller extends OriginalConfigInstaller {

  /**
   * Our altered version of the update_helper's Updater.
   */
  protected Updater $updater;

  /**
   * {@inheritdoc}
   */
  public function __construct(ConfigFactoryInterface $config_factory, StorageInterface $active_storage, TypedConfigManagerInterface $typed_config, ConfigManagerInterface $config_manager, EventDispatcherInterface $event_dispatcher, $install_profile, ExtensionPathResolver $extension_path_resolver = NULL) {
    parent::__construct($config_factory, $active_storage, $typed_config, $config_manager, $event_dispatcher, $install_profile, $extension_path_resolver);
    // We can't use dependency injection here because it would cause a circular
    // dependency.
    // @phpstan-ignore-next-line
    $this->updater = \Drupal::service("update_helper.updater");
  }

  /**
   * {@inheritdoc}
   */
  public function installOptionalConfig(StorageInterface $storage = NULL, $dependency = []) : void {
    parent::installOptionalConfig($storage, $dependency);

    // We only want to install optional config when we're newly installing
    // modules. If we're syncing config then the module was installed on another
    // platform which would've already run our alterations.
    if ($this->isSyncing()) {
      return;
    }

    // We ignore the storage here because it's specifically for
    // `config/optional` and that's not the folder we want.
    $this->installOptionalAlterConfig();
  }

  public function installOptionalAlterConfig(StorageInterface $storage = NULL) : void {
    // When this module is being installed this function is called but our
    // service provider hasn't run yet so we don't have the required functions
    // available. The most secure way (avoiding PHP issues) is just doing an
    // instance check. We can remove this if we can get the update_helper module
    // to expose the method we need.
    if (!$this->updater instanceof Updater) {
      return;
    }

    $alterations_applied = $this->configFactory->getEditable("config_modify.applied");
    $enabled_extensions = $this->getEnabledExtensions();
    $existing_config = $this->getActiveStorages()->listAll();

    // Create the storages to read configuration from.
    if ($storage === NULL) {
      // Search the install profile's optional configuration too.
      // We don't need to do anything special for the install profile based on a
      // dependency because we never create new configb ut only alter existing.
      $storage = new ExtensionInstallStorage($this->getActiveStorages(), "config/modify", StorageInterface::DEFAULT_COLLECTION, TRUE, $this->installProfile);
    }

    // Filter out any previously applied files.
    $list = array_diff($storage->listAll(), $alterations_applied->get('files'));

    // Read all alter files and filter out any, where the dependencies aren't
    // met yet.
    $config_to_alter = array_filter(
      $storage->readMultiple($list),
      /** @phpstan-var ModifyDefinition $data */
      function ($data, $alter_name) use ($enabled_extensions, $existing_config) {
        // CUD allows for global actions not tied to a specific config item
        // which is not a valid config item name.
        $config_items = $data["items"];
        unset($config_items[UpdateDefinitionInterface::GLOBAL_ACTIONS]);
        $config_item_names = array_keys($config_items);
        // The config items that should be altered are implicit dependencies so
        // for our validation we must add them.
        $data['dependencies']['config'] = ($data['dependencies']['config'] ?? []) + $config_item_names;

        return $this->validateDependencies($alter_name, $data, $enabled_extensions, $existing_config);
      },
      ARRAY_FILTER_USE_BOTH
    );

    if (!empty($config_to_alter)) {
      foreach ($config_to_alter as $data) {
        $this->updater->doExecuteUpdate($data["items"]);
      }

      $alterations_applied->set("files", $alterations_applied->get("files") + array_keys($config_to_alter))->save();
    }
  }

}
