<?php

namespace Drupal\domain_unique_path_alias;

use Drupal\Component\Utility\Unicode;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\pathauto\AliasStorageHelperInterface;
use Drupal\pathauto\AliasUniquifierInterface;

/**
 * Provides a utility for creating a unique path alias.
 */
class DomainUniquePathAliasUniquifier implements AliasUniquifierInterface {

  public function __construct(
    protected AliasUniquifierInterface $inner,
    protected ConfigFactoryInterface $configFactory,
    protected AliasStorageHelperInterface $aliasStorageHelper,
    protected ModuleHandlerInterface $moduleHandler,
    protected Connection $database,
    protected DomainUniquePathAliasHelper $helper,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function uniquify(&$alias, $source, $langcode, ?string $domain_id = NULL) {
    $domain_id ??= $this->helper->getPathDomainId($source);

    if (!$this->isReserved($alias, $source, $langcode, $domain_id)) {
      return;
    }

    // If the alias already exists, generate a new, hopefully unique, variant.
    $config = $this->configFactory->get('pathauto.settings');
    $maxlength = min($config->get('max_length'), $this->aliasStorageHelper->getAliasSchemaMaxlength());
    $separator = $config->get('separator');
    $original_alias = $alias;

    $i = 0;
    do {
      // Append an incrementing numeric suffix until we find a unique alias.
      $unique_suffix = $separator . $i;
      $alias = Unicode::truncate($original_alias, $maxlength - mb_strlen($unique_suffix), TRUE) . $unique_suffix;
      $i++;
    } while ($this->isReserved($alias, $source, $langcode, $domain_id));
  }

  /**
   * {@inheritdoc}
   */
  public function isReserved($alias, $source, $langcode = LanguageInterface::LANGCODE_NOT_SPECIFIED, ?string $domain_id = NULL) {
    $domain_id ??= $this->helper->getPathDomainId($source);

    // Fall back to global uniqueness when domain is unknown.
    if ($domain_id === NULL || $domain_id === '') {
      return $this->inner->isReserved($alias, $source, $langcode);
    }

    // Domain-scoped collision check, with langcode priority: exact > und.
    foreach ([$langcode, LanguageInterface::LANGCODE_NOT_SPECIFIED] as $lc) {
      $existing_path = $this->database->select('path_alias', 'pa')
        ->fields('pa', ['path'])
        ->condition('alias', $alias)
        ->condition('status', 1)
        ->condition('langcode', $lc)
        ->condition('domain_id', $domain_id)
        ->range(0, 1)
        ->execute()
        ->fetchField();
      if ($existing_path !== FALSE && (string) $existing_path !== $source) {
        return TRUE;
      }
    }

    // Route check; fall back to inner when isRoute is unavailable.
    if (method_exists($this->inner, 'isRoute')) {
      if ($this->inner->isRoute($alias)) {
        return TRUE;
      }
    }
    elseif ($this->inner->isReserved($alias, $source, $langcode)) {
      return TRUE;
    }

    // pathauto_is_alias_reserved via short-circuiting invokeAllWith.
    $reserved = FALSE;
    $this->moduleHandler->invokeAllWith(
      'pathauto_is_alias_reserved',
      function (callable $hook) use (&$reserved, $alias, $source, $langcode): void {
        if (!$reserved && $hook($alias, $source, $langcode)) {
          $reserved = TRUE;
        }
      },
    );

    return $reserved;
  }

}
