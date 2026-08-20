<?php

namespace Drupal\domain_unique_path_alias;

use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\path_alias\AliasManagerInterface;

/**
 * The path alias manager decorator.
 */
class DomainUniquePathAliasManager implements AliasManagerInterface {

  use DependencySerializationTrait;

  /**
   * The decorated path alias manager.
   *
   * @var \Drupal\path_alias\AliasManagerInterface
   */
  protected $inner;

  /**
   * Language manager.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected $languageManager;

  /**
   * The helper service.
   *
   * @var \Drupal\domain_unique_path_alias\DomainUniquePathAliasHelper
   */
  protected $helper;

  /**
   * The domain-aware alias lookup.
   *
   * @var \Drupal\domain_unique_path_alias\DomainUniquePathAliasLookup
   */
  protected $lookup;

  /**
   * Constructs an AliasManager with DomainPathAliasManager.
   *
   * @param \Drupal\path_alias\AliasManagerInterface $inner
   *   The decorated alias manager.
   * @param \Drupal\Core\Language\LanguageManagerInterface $language_manager
   *   The language manager.
   * @param \Drupal\domain_unique_path_alias\DomainUniquePathAliasHelper $helper
   *   The helper service.
   * @param \Drupal\domain_unique_path_alias\DomainUniquePathAliasLookup $lookup
   *   The domain-aware alias lookup.
   */
  public function __construct(
    AliasManagerInterface $inner,
    LanguageManagerInterface $language_manager,
    DomainUniquePathAliasHelper $helper,
    DomainUniquePathAliasLookup $lookup,
  ) {
    $this->inner = $inner;
    $this->languageManager = $language_manager;
    $this->helper = $helper;
    $this->lookup = $lookup;
  }

  /**
   * {@inheritdoc}
   */
  public function getPathByAlias($alias, $langcode = NULL) {
    if ($this->isAssetFile($alias)) {
      return $alias;
    }

    $langcode = $langcode ?: $this->languageManager
      ->getCurrentLanguage(LanguageInterface::TYPE_CONTENT)
      ->getId();

    $domain_id = $this->helper->getDomainIdByRequest();

    // Without domain context, defer to core resolution.
    if (!$alias || !$domain_id || !$langcode) {
      return $this->inner->getPathByAlias($alias, $langcode);
    }

    // Keep cross-domain aliases unresolved.
    $path = $this->lookup->lookup($alias, $langcode, $domain_id);
    return $path ?? $alias;
  }

  /**
   * {@inheritdoc}
   */
  public function getAliasByPath($path, $langcode = NULL) {
    return $this->inner->getAliasByPath($path, $langcode);
  }

  /**
   * {@inheritdoc}
   */
  public function cacheClear($source = NULL) {
    $this->inner->cacheClear($source);
  }

  /**
   * This method is part of AliasManager, but not AliasManagerInterface.
   */
  public function setCacheKey($key) {
    if (method_exists($this->inner, 'setCacheKey')) {
      $this->inner->setCacheKey($key);
    }
  }

  /**
   * This method is part of AliasManager, but not AliasManagerInterface.
   */
  public function writeCache() {
    if (method_exists($this->inner, 'writeCache')) {
      $this->inner->writeCache();
    }
  }

  /**
   * Check if a path is an asset by its extension.
   *
   * @param string $alias
   *   An alias.
   *
   * @return bool
   *   Check result.
   */
  private function isAssetFile($alias) {
    $noAlias = ['svg', 'png', 'jpeg', 'jpg', 'css', 'js', 'gif', 'webp', 'ts'];
    $extension = pathinfo($alias, PATHINFO_EXTENSION);
    $extension = explode('?', $extension);
    if (in_array($extension[0], $noAlias)) {
      return TRUE;
    }
    return FALSE;
  }

}
