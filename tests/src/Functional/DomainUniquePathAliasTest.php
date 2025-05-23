<?php

namespace Drupal\Tests\domain_unique_path_alias\Functional;

use Drupal\domain_access\DomainAccessManagerInterface;
use Drupal\domain_source\DomainSourceElementManagerInterface;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\domain\Traits\DomainTestTrait;

/**
 * Tests path alias on different domains.
 *
 * @group domain_unique_path_alias
 */
class DomainUniquePathAliasTest extends BrowserTestBase {

  use DomainTestTrait;

  /**
   * We use the standard profile for testing.
   *
   * @var string
   */
  protected $profile = 'standard';

  /**
   * Stores the created test domains.
   *
   * @var \Drupal\domain\Entity\Domain[]
   */
  protected array $domains;

  /**
   * Stores the created test nodes.
   *
   * @var \Drupal\node\Entity\Node[]
   */
  protected array $nodes;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'domain_source',
    'domain_unique_path_alias',
    'domain',
    'field',
    'node',
    'path_alias',
    'pathauto',
    'path',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Set the base hostname for domains.
    /** @var \Drupal\domain\DomainStorageInterface $storage */
    $storage = \Drupal::entityTypeManager()->getStorage('domain');
    $this->baseHostname = $storage->createHostname();

    // Ensure that $this->baseTLD is set.
    $this->setBaseDomain();

    $this->database = \Drupal::database();

    // Create 3 test domains.
    $this->domainCreateTestDomains(3);
    $this->domains = $this->container->get('entity_type.manager')->getStorage('domain')->loadMultiple();

    $this->configureTrustedHostPatterns();
    $this->createTestNodes();

    $account = $this->drupalCreateUser([
      'access content',
      'access administration pages',
      'access content overview',
      'create url aliases',
      'edit any article content',
      'delete any article content',
    ]);
    $this->drupalLogin($account);
  }

  /**
   * Configures trusted host patterns.
   */
  private function configureTrustedHostPatterns(): void {
    $patterns = [];
    foreach ($this->domains as $domain) {
      $patterns[] = '^' . $this->prepareTrustedHostname($domain->getHostname()) . '$';
    }

    $settings = [
      'settings' => [
        'trusted_host_patterns' => (object) [
          'value' => $patterns,
          'required' => TRUE,
        ],
      ],
    ];
    $this->writeSettings($settings);
  }

  /**
   * Creates test nodes with domain-specific path aliases.
   */
  private function createTestNodes(): void {
    $this->nodes = [
      'node_1' => $this->createArticle('/contact', 'example_com'),
      'node_2' => $this->createArticle('/contact-bis', 'example_com'),
      'node_3' => $this->createArticle('/contact', 'one_example_com'),
    ];
  }

  /**
   * Helper function to create an article node with a domain-specific alias.
   */
  private function createArticle(string $alias, string $domain_key) {
    return $this->drupalCreateNode([
      'type' => 'article',
      'path' => ['alias' => $alias],
      DomainAccessManagerInterface::DOMAIN_ACCESS_FIELD => [$this->domains[$domain_key]->id()],
      DomainSourceElementManagerInterface::DOMAIN_SOURCE_FIELD => [$this->domains[$domain_key]->id()],
    ]);
  }

  /**
   * Tests domain-specific unique path aliases.
   */
  public function testDomainUniquePathAlias(): void {
    $this->testAliasGeneration();
    $this->testAliasConstraintValidation();
    $this->testNodeDeletion();
  }

  /**
   * Tests if node aliases are generated correctly per domain.
   */
  private function testAliasGeneration(): void {
    $this->drupalGet('admin/content');

    $this->assertSession()->responseContains($this->domains['example_com']->getPath() . 'contact" hreflang="en">');
    $this->assertSession()->responseContains($this->domains['example_com']->getPath() . 'contact-bis" hreflang="en">');
    $this->assertSession()->responseContains($this->domains['one_example_com']->getPath() . 'contact" hreflang="en">');
  }

  /**
   * Tests alias constraint validation when editing nodes.
   */
  private function testAliasConstraintValidation(): void {
    $constraint_message = 'The alias /contact is already in use in this domain (example_com).';

    // Change node_2 alias to a unique alias.
    $this->drupalGet('node/' . $this->nodes['node_2']->id() . '/edit');
    $this->submitForm(['path[0][alias]' => '/contact-bis-bis'], 'Save');
    $this->assertSession()->pageTextNotContains($constraint_message);

    // Attempt setting alias to an existing alias within the same domain.
    $this->drupalGet('node/' . $this->nodes['node_2']->id() . '/edit');
    $this->submitForm(['path[0][alias]' => '/contact'], 'Save');
    $this->assertSession()->pageTextContains($constraint_message);
  }

  /**
   * Tests that deleting a node results in a 404 for its alias.
   */
  private function testNodeDeletion(): void {
    $node_id = $this->nodes['node_3']->id();

    // Ensure node_3 exists before deletion.
    $this->drupalGet('node/' . $node_id);
    $this->assertSession()->statusCodeEquals(200);

    // Delete node_3 and verify 404.
    $this->drupalGet('node/' . $node_id . '/delete');
    $this->submitForm([], 'Delete');

    $this->rebuildContainer();
    $this->drupalGet('node/' . $node_id);
    $this->assertSession()->statusCodeEquals(404);
  }

}
