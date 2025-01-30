<?php

namespace Drupal\domain_unique_path_alias\Plugin\Validation\Constraints;

use Drupal\Core\Path\Plugin\Validation\Constraint\UniquePathAliasConstraintValidator;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;

/**
 * Constraint validator for changing path aliases with domain control.
 */
class DomainUniquePathAliasConstraintValidator extends UniquePathAliasConstraintValidator {

  /**
   * The helper service.
   *
   * @var \Drupal\domain_unique_path_alias\DomainUniquePathAliasHelper
   */
  protected $helper;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->helper = $container->get('domain_unique_path_alias.helper');

    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function validate($entity, Constraint $constraint): void {
    /** @var \Drupal\path_alias\PathAliasInterface $entity */
    $path = $entity->getPath();
    $alias = $entity->getAlias();
    $langcode = $entity->language()->getId();

    $storage = $this->entityTypeManager->getStorage('path_alias');
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('alias', $alias, '=')
      ->condition('langcode', $langcode, '=');

    if (!$entity->isNew()) {
      $query->condition('id', $entity->id(), '<>');
    }
    if ($path) {
      $query->condition('path', $path, '<>');
    }

    $fieldDomainSource = $this->helper->getDomainIdByRequest();
    if (!empty($fieldDomainSource)) {
      $query->condition('domain_id', $fieldDomainSource, '=');
    }

    if ($result = $query->range(0, 1)->execute()) {
      $existing_alias_id = reset($result);
      /** @var \Drupal\path_alias\PathAliasInterface $existingAlias */
      $existingAlias = $storage->load($existing_alias_id);
      if (
        !empty($fieldDomainSource)
        && $existingAlias->get('domain_id')->getString() === $fieldDomainSource
      ) {
        $this->context->buildViolation($constraint->messageDomain, [
          '%alias' => $alias,
          '%domain' => $fieldDomainSource,
        ])->addViolation();
      }
      elseif ($existingAlias->getAlias() !== $alias) {
        $this->context->buildViolation($constraint->differentCapitalizationMessage, [
          '%alias' => $alias,
          '%stored_alias' => $existingAlias->getAlias(),
        ])->addViolation();
      }
      else {
        $this->context->buildViolation($constraint->message, [
          '%alias' => $alias,
        ])->addViolation();
      }
    }
  }

}
