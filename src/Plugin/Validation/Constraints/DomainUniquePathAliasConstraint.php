<?php

namespace Drupal\domain_unique_path_alias\Plugin\Validation\Constraints;

use Drupal\Core\Path\Plugin\Validation\Constraint\UniquePathAliasConstraint;

/**
 * Constraint validator for a Domain unique path alias.
 */
final class DomainUniquePathAliasConstraint extends UniquePathAliasConstraint {

  /**
   * The domain violation message.
   */
  public string $messageDomain = 'The alias %alias is already in use in this domain (%domain).';

}
