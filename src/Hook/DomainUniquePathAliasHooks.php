<?php

namespace Drupal\domain_unique_path_alias\Hook;

use Drupal\Core\Entity\ContentEntityType;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\domain_unique_path_alias\DomainUniquePathAliasHelper;
use Drupal\node\NodeInterface;
use Drupal\path_alias\PathAliasInterface;

/**
 * Hook implementations for domain_unique_path_alias.
 */
class DomainUniquePathAliasHooks {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected DomainUniquePathAliasHelper $helper,
  ) {}

  /**
   * Sync existing aliases after the node domain has changed.
   */
  #[Hook('node_update')]
  public function nodeUpdate(NodeInterface $node): void {
    $domain_id = $this->helper->getDomainIdFromEntity($node);
    if (!$domain_id) {
      return;
    }
    $this->syncNodeAliases($node, $domain_id);
  }

  /**
   * Propagate the current node domain onto a path_alias being saved.
   */
  #[Hook('path_alias_presave')]
  public function pathAliasPresave(PathAliasInterface $path_alias): void {
    $parts = explode('/', $path_alias->getPath());
    if (!isset($parts[1], $parts[2]) || $parts[1] !== 'node' || !is_numeric($parts[2])) {
      return;
    }
    $node = $this->entityTypeManager->getStorage('node')->load($parts[2]);
    if (!$node instanceof NodeInterface) {
      return;
    }
    $domain_id = $this->helper->getDomainIdFromEntity($node) ?? '';
    if ($path_alias->get('domain_id')->getString() !== $domain_id) {
      $path_alias->set('domain_id', $domain_id);
    }
  }

  /**
   * Implements hook_entity_base_field_info().
   */
  #[Hook('entity_base_field_info')]
  public function entityBaseFieldInfo(ContentEntityType $entity_type) {
    $fields = [];
    if ($entity_type->id() === 'path_alias') {
      $fields['domain_id'] = BaseFieldDefinition::create('string')
        ->setLabel('Domain Id')
        ->setDescription('Domain identification.');
    }
    return $fields;
  }

  /**
   * Implements hook_validation_constraint_alter().
   */
  #[Hook('validation_constraint_alter')]
  public function validationConstraintAlter(array &$definitions) {
    if (isset($definitions['UniquePathAlias'])) {
      $definitions['UniquePathAlias']['class'] = '\Drupal\domain_unique_path_alias\Plugin\Validation\Constraints\DomainUniquePathAliasConstraint';
    }
  }

  /**
   * Updates all aliases for a node to the given domain_id.
   */
  private function syncNodeAliases(NodeInterface $node, string $domain_id): void {
    $storage = $this->entityTypeManager->getStorage('path_alias');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('path', '/node/' . $node->id())
      ->execute();
    foreach ($storage->loadMultiple($ids) as $alias) {
      if ($alias->get('domain_id')->getString() !== $domain_id) {
        $alias->set('domain_id', $domain_id);
        $alias->save();
      }
    }
  }

}
