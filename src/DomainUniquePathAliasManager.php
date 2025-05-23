<?php

namespace Drupal\domain_unique_path_alias;

use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
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
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

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
   * Constructs an AliasManager with DomainPathAliasManager.
   *
   * @param \Drupal\path_alias\AliasManagerInterface $inner
   *   The decorated alias manager.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Language\LanguageManagerInterface $language_manager
   *   The language manager.
   * @param \Drupal\domain_unique_path_alias\DomainUniquePathAliasHelper $helper
   *   The helper service.
   */
  public function __construct(
    AliasManagerInterface $inner,
    EntityTypeManagerInterface $entity_type_manager,
    LanguageManagerInterface $language_manager,
    DomainUniquePathAliasHelper $helper,
  ) {
    $this->inner = $inner;
    $this->entityTypeManager = $entity_type_manager;
    $this->languageManager = $language_manager;
    $this->helper = $helper;
  }

  /**
   * {@inheritdoc}
   */
  public function getPathByAlias($alias, $langcode = NULL) {
    // Do not process asset files and alias non exist.
    if ($this->isAssetFile($alias) || $this->helper->isExistingAlias($alias) === FALSE) {
      return $alias;
    }

    // @todo Investigate if TYPE_CONTENT is the correct type or should be editable in modules settings.
    $langcode = $langcode ?: $this->languageManager
      ->getCurrentLanguage(LanguageInterface::TYPE_CONTENT)
      ->getId();

    $domain_id = $this->helper->getDomainIdByRequest();
    if ($alias && $domain_id && $langcode) {
      $properties = [
        'alias' => $alias,
        'domain_id' => $domain_id,
        'langcode' => $langcode,
      ];

      $path_aliases = $this->entityTypeManager
        ->getStorage('path_alias')
        ->loadByProperties($properties);

      if (count($path_aliases) === 0) {
        $this->helper->get404Path($domain_id);
      }

      foreach ($path_aliases as $path_alias) {
        $path_domain_id = $path_alias->get('domain_id')->getString();
        if ($domain_id === $path_domain_id) {
          return $path_alias->getPath();
        }
      }
    }

    return $this->inner->getPathByAlias($alias, $langcode);
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
