<?php

namespace Drupal\domain_unique_path_alias;

use Drupal\Core\Database\Connection;
use Drupal\Core\Language\LanguageInterface;

/**
 * Domain-aware alias lookup.
 *
 * Complements path_alias.repository by adding the domain constraint.
 * Does not replace core's repository: only adds the additional query
 * required by this module.
 */
final class DomainUniquePathAliasLookup {

  public function __construct(
    private readonly Connection $database,
    private readonly DomainUniquePathAliasHelper $helper,
  ) {}

  /**
   * Resolves an alias to its system path for the current domain.
   *
   * Resolution order:
   * 1. alias explicitly attached to the current domain.
   * 2. legacy alias (domain_id = '') whose source belongs to the current
   *    domain, or whose source is not domainisable.
   *
   * Returns null when no acceptable alias is found. Cross-domain aliases
   * are never returned.
   */
  public function lookup(string $alias, string $langcode, string $domain_id): ?string {
    $explicit = $this->lookupByDomain($alias, $langcode, $domain_id);
    if ($explicit !== NULL) {
      return $explicit;
    }
    return $this->lookupLegacy($alias, $langcode, $domain_id);
  }

  /**
   * Looks up an alias explicitly attached to the given domain.
   *
   * Priority: requested langcode > und.
   */
  private function lookupByDomain(string $alias, string $langcode, string $domain_id): ?string {
    foreach ([$langcode, LanguageInterface::LANGCODE_NOT_SPECIFIED] as $lc) {
      $path = $this->database->select('path_alias', 'pa')
        ->fields('pa', ['path'])
        ->condition('alias', $alias)
        ->condition('status', 1)
        ->condition('langcode', $lc)
        ->condition('domain_id', $domain_id)
        ->range(0, 1)
        ->execute()
        ->fetchField();
      if ($path) {
        return (string) $path;
      }
    }
    return NULL;
  }

  /**
   * Looks up a legacy alias (domain_id = '') with source-domain resolution.
   *
   * Accepts the candidate when:
   * - the source is not domainisable (neutral alias), or
   * - the source's resolved domain matches the current domain.
   *
   * Refuses candidates from other domains (fail closed).
   */
  private function lookupLegacy(string $alias, string $langcode, string $domain_id): ?string {
    foreach ([$langcode, LanguageInterface::LANGCODE_NOT_SPECIFIED] as $lc) {
      $candidates = $this->database->select('path_alias', 'pa')
        ->fields('pa', ['path'])
        ->condition('alias', $alias)
        ->condition('status', 1)
        ->condition('langcode', $lc)
        ->condition('domain_id', '')
        ->execute()
        ->fetchAllAssoc('path', \PDO::FETCH_ASSOC);
      foreach (array_keys($candidates) as $path) {
        $source_domain = $this->helper->getPathDomainId($path);
        if ($source_domain === NULL || $source_domain === $domain_id) {
          return $path;
        }
      }
    }
    return NULL;
  }

}
