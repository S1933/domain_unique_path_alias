<?php

namespace Drupal\domain_unique_path_alias;

use Drupal\Core\Config\ConfigFactory;
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
   * @param \Drupal\Core\Config\ConfigFactory $configFactory
   *   The config factory.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected DomainNegotiatorInterface $domainNegotiator,
    protected RequestStack $requestStack,
    protected ConfigFactory $configFactory,
  ) {}

  /**
   * Gets the domain id from the path.
   *
   * Currently works only with nodes and taxonomy terms.
   *
   * The path entity's domain_source field is first checked, the then first
   * value from the domain_access field.
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
    $id = end($parts);
    switch ($parts[0]) {
      case 'taxonomy':
        /** @var \Drupal\taxonomy\TermInterface $entity */
        $entity = $this->entityTypeManager->getStorage('taxonomy_term')->load($id);
        break;

      case 'node':
        /** @var \Drupal\node\NodeInterface $entity */
        $entity = $this->entityTypeManager->getStorage('node')->load($id);
        break;
    }

    return isset($entity) ? $this->getDomainIdFromEntity($entity) : NULL;
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
   * Get 404 path.
   *
   * @param string $domain_id
   *   The domain id.
   *
   * @return string|null
   *   Return 404 path.
   */
  public function get404Path(string $domain_id): ?string {
    $domain_config = $this->configFactory
      ->get('domain.config.' . $domain_id . '.system.site')
      ?->get('page.404');
    $global_config = $this->configFactory
      ->get('system.site')
      ?->get('page.404');

    return $domain_config ?? $global_config;
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
