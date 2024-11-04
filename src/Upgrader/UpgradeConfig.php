<?php

namespace Drupal\bo_system\Upgrader;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\FileStorage;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;

/**
 * Defines the UpgradeConfig class.
 *
 * @package Drupal\bp_system\Upgrader
 */
class UpgradeConfig {

  /**
   * Update configs.
   *
   * @param array $configs
   *   An array of configurations grouped by name module.
   *
   * @return int
   *   The code of result operation.
   */
  public static function updateConfigs(array $configs): int {

    $extensionListModule = \Drupal::service('extension.list.module');
    assert($extensionListModule instanceof ModuleExtensionList);
    $configFactory = \Drupal::configFactory();
    assert($configFactory instanceof ConfigFactoryInterface);

    foreach ($configs as $module => $moduleConfigs) {
      foreach ($moduleConfigs as $moduleConfig) {
        $configPath = $extensionListModule->getPath($module) . '/config/install';
        $data = (new FileStorage($configPath))->read($moduleConfig);
        assert(is_array($data));
        $configFactory->getEditable($moduleConfig)
          ->setData($data)
          ->save();
      }
    }

    return 0;
  }

  /**
   * Create configurations.
   *
   * @param array $configs
   *   An array of configurations grouped by name module.
   *
   * @return int
   *   The code of result operation.
   */
  public static function createConfigs(array $configs): int {
    $extension_list_module = \Drupal::service('extension.list.module');
    assert($extension_list_module instanceof ModuleExtensionList);
    $config_factory = \Drupal::configFactory();
    assert($config_factory instanceof ConfigFactoryInterface);

    foreach ($configs as $module => $moduleConfigs) {
      foreach ($moduleConfigs as $moduleConfig) {
        $configPath = $extension_list_module->getPath($module) . '/config/install';
        $data = (new FileStorage($configPath))->read($moduleConfig);
        assert(is_array($data));
        $config_factory
          ->getEditable($moduleConfig['config'])
          ->setData($data)
          ->save();
      }
    }

    return 0;
  }

  /**
   * Delete configurations.
   *
   * @param array $configs
   *   An array of configurations grouped by name module.
   *
   * @return int
   *   The code of result operation.
   */
  public static function deleteConfigs(array $configs): int {
    $config_factory = \Drupal::configFactory();
    assert($config_factory instanceof ConfigFactoryInterface);
    foreach ($configs as $config) {
      $config_factory
        ->getEditable($config)
        ->delete();
    }

    return 0;
  }

  /**
   * Create new fields.
   *
   * @param array $configs
   *   An array of configurations grouped by name module.
   *
   * @return int
   *   The code of result operation.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public static function createNewFields(array $configs): int {
    foreach ($configs as $module => $moduleConfigs) {
      foreach ($moduleConfigs as $moduleConfig) {
        $configPath = \Drupal::service('extension.list.module')->getPath($module) . '/config/install';
        $source = new FileStorage($configPath);

        $storage = explode(".", $moduleConfig['storage']);
        $storage_type = $storage[2];
        $storage_name_field = $storage[3];

        $config_storage = FieldStorageConfig::loadByName($storage_type, $storage_name_field);
        if ($config_storage === NULL) {
          // Obtain the storage manager for field storage bases
          // Create a new field from the yaml configuration and save
          \Drupal::entityTypeManager()->getStorage('field_storage_config')
            ->create($source->read($moduleConfig['storage']))
            ->save();
        }

        $field = explode(".", $moduleConfig['config']);
        $field_type = $field[2];
        $field_bundle = $field[3];
        $field_name_field = $field[4];

        $config_field = FieldConfig::loadByName($field_type, $field_bundle, $field_name_field);
        if ($config_field === NULL) {
          // Obtain the storage manager for field instances
          // Create a new field instance from the yaml configuration and save
          \Drupal::entityTypeManager()->getStorage('field_config')
            ->create($source->read($moduleConfig['config']))
            ->save();
        }
      }
    }

    return 0;
  }

  /**
   * Update field type.
   *
   * @param string $entityType
   *   The entity type.
   * @param string $fieldName
   *   The field name.
   * @param string $newType
   *   The new field type.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public static function updateFieldType(string $entityType, string $fieldName, string $newType): void {
    $database = \Drupal::database();
    $table = $entityType . '__' . $fieldName;
    $currentRows = NULL;
    $newFieldsList = [];
    $fieldStorage = FieldStorageConfig::loadByName($entityType, $fieldName);

    if (is_null($fieldStorage)) {
      return;
    }

    // Get all current data from DB.
    if ($database->schema()->tableExists($table)) {
      // The table data to restore after the update is completed.
      $currentRows = $database->select($table, 'n')
        ->fields('n')
        ->execute()
        ->fetchAll();
    }

    // Use existing field config for new field.
    foreach ($fieldStorage->getBundles() as $bundle => $label) {
      /** @var \Drupal\field\FieldConfigInterface $field */
      $field = FieldConfig::loadByName($entityType, $bundle, $fieldName);
      $newField = $field->toArray();
      $newField['field_type'] = $newType;
      $newField['settings'] = [];
      $newFieldsList[] = $newField;
    }

    // Deleting field storage which will also delete bundles(fields).
    $newFieldStorage = $fieldStorage->toArray();
    $newFieldStorage['type'] = $newType;
    $newFieldStorage['settings'] = [];

    $fieldStorage->delete();

    // Purge field data now to allow new field and field_storage with same name
    // to be created.
    field_purge_batch(40);

    // Create new field storage.
    $newFieldStorage = FieldStorageConfig::create($newFieldStorage);
    $newFieldStorage->save();

    // Create new fields.
    foreach ($newFieldsList as $nfield) {
      $nfieldConfig = FieldConfig::create($nfield);
      $nfieldConfig->save();
    }

    // Restore existing data in new table.
    if (!is_null($currentRows)) {
      foreach ($currentRows as $row) {
        $database->insert($table)
          ->fields((array) $row)
          ->execute();
      }
    }
  }

}
