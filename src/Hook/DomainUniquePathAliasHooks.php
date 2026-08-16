<?php

namespace Drupal\domain_unique_path_alias\Hook;

use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Entity\ContentEntityType;
use Drupal\Core\Language\LanguageInterface;
use Drupal\node\NodeInterface;
use Drupal\node\Entity\Node;
use Drupal\path_alias\PathAliasInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Hook\Attribute\Hook;

/**
 * Hook implementations for domain_unique_path_alias.
 */
class DomainUniquePathAliasHooks {

  /**
   * Implements hook_ENTITY_TYPE_presave().
   */
  #[Hook('node_presave')]
  public function nodePresave(EntityInterface $entity) {
    $helper = \Drupal::service('domain_unique_path_alias.helper');
    if ($domain_id = $helper->getDomainIdFromEntity($entity)) {
      $path_alias = \Drupal::entityTypeManager()->getStorage('path_alias')->loadByProperties([
        'path' => '/node/' . $entity->id(),
        'langcode' => $entity->language()->getId(),
      ]);
      $path_alias = reset($path_alias);
      if ($path_alias instanceof PathAliasInterface) {
        $path_alias_domain_id = $path_alias->get('domain_id')->getString();
        if ($domain_id != $path_alias_domain_id) {
          $request = \Drupal::requestStack()->getCurrentRequest();
          $request->attributes->set('domain_id', $domain_id);
          $path_alias->save();
        }
      }
    }
  }

  /**
   * Implements hook_ENTITY_TYPE_presave().
   */
  #[Hook('path_alias_presave')]
  public function pathAliasPresave(PathAliasInterface $path_alias) {
    $path = explode("/", $path_alias->getPath());
    if (isset($path[1], $path[2]) && $path[1] === 'node' && is_numeric($path[2])) {
      $request = \Drupal::requestStack()->getCurrentRequest();
      $domain_id = $request->attributes->get('domain_id');
      if ($domain_id === NULL) {
        $node = Node::load($path[2]);
        if ($node instanceof NodeInterface) {
          $langcode = \Drupal::languageManager()->getCurrentLanguage(LanguageInterface::TYPE_CONTENT)->getId();
          $langcode = $path_alias->get('langcode')->getString() ?? $langcode;
          $translation_languages = $node->getTranslationLanguages();
          if (in_array($langcode, array_keys($translation_languages))) {
            $helper = \Drupal::service('domain_unique_path_alias.helper');
            $domain_id = $helper->getDomainIdFromEntity($node);
          }
        }
      }
      if ($path_alias->get('domain_id')->getString() != $domain_id) {
        $path_alias->set('domain_id', $domain_id);
      }
    }
  }

  /**
   * Implements hook_entity_base_field_info().
   */
  #[Hook('entity_base_field_info')]
  public function entityBaseFieldInfo(ContentEntityType $entity_type) {
    $fields = [];
    if ($entity_type->id() === 'path_alias') {
      $fields['domain_id'] = BaseFieldDefinition::create('string')->setLabel('Domain Id')->setDescription('Domain identification.');
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

}
