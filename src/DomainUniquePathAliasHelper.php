<?php

namespace Drupal\domain_unique_path_alias;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\domain\DomainInterface;
use Drupal\domain\DomainNegotiatorInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Provides helper functions for the Domain Unique Path Alias.
 */
class DomainUniquePathAliasHelper {

  /**
   * Constructs a DomainUniquePathAliasHelper object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\domain\DomainNegotiatorInterface $domainNegotiator
   *   The domain negotiator.
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   The request stack.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected DomainNegotiatorInterface $domainNegotiator,
    protected RequestStack $requestStack,
  ) {}

  /**
   * Gets the domain id from the path.
   *
   * Currently works only with nodes.
   *
   * The node's domain_source field is read; if absent or empty,
   * the first domain_access value is used.
   *
   * @param string $path
   *   The path to get the domain id from.
   *
   * @return string|null
   *   Domain id, if any.
   */
  public function getPathDomainId(string $path): ?string {
    $path = ltrim($path, '/');
    $parts = explode('/', $path);
    if ($parts[0] !== 'node' || !isset($parts[1]) || !is_numeric($parts[1])) {
      return NULL;
    }
    $entity = $this->entityTypeManager->getStorage('node')->load($parts[1]);
    return $entity ? $this->getDomainIdFromEntity($entity) : NULL;
  }

  /**
   * Get the domain id for a given entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity.
   *
   * @return string|null
   *   Domain id, if any.
   */
  public function getDomainIdFromEntity(ContentEntityInterface $entity): ?string {
    // Get domain_id using domain_source or fallback with domain_access field.
    if ($entity->hasField('field_domain_source') && !$entity->get('field_domain_source')->isEmpty()) {
      $domain_id = $entity->get('field_domain_source')->getString();
    }
    elseif ($entity->hasField('field_domain_access') && !$entity->get('field_domain_access')->isEmpty()) {
      $domain_id = $entity->get('field_domain_access')->first()->getString();
    }

    return $domain_id ?? NULL;
  }

  /**
   * Get the domain id for a given request.
   *
   * @param \Symfony\Component\HttpFoundation\Request|null $request
   *   The request.
   *
   * @return string
   *   Domain id if any or empty string.
   */
  public function getDomainIdByRequest(?Request $request = NULL): string {
    $domain = $this->domainNegotiator->getActiveDomain();
    if (!$domain instanceof DomainInterface) {
      return '';
    }

    $domain_id = $domain->id();
    $request ??= $this->requestStack->getCurrentRequest();
    if ($request->request->has('field_domain_source')) {
      $domain_id = $request->request->get('field_domain_source');
    }

    return $domain_id;
  }

  /**
   * Gets if the current alias exist.
   *
   * @return bool
   *   Boolean if alias exist.
   */
  public function isExistingAlias(string $alias): bool {
    return $this->entityTypeManager
      ->getStorage('path_alias')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('alias', (array) $alias, 'IN')
      ->count()
      ->execute() > 0;
  }

}
