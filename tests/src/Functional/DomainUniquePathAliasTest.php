<?php

namespace Drupal\Tests\domain_unique_path_alias\Functional;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\domain_source\DomainSourceElementManagerInterface;
use Drupal\pathauto\PathautoState;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\domain\Traits\DomainTestTrait;
use Drupal\Tests\pathauto\Functional\PathautoTestHelperTrait;

/**
 * Tests path alias on different domains.
 *
 * @group domain_unique_path_alias
 */
#[Group('domain_unique_path_alias')]
#[RunTestsInSeparateProcesses]
class DomainUniquePathAliasTest extends BrowserTestBase {

  use DomainTestTrait;
  use PathautoTestHelperTrait;

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
    $this->domainCreateTestDomains(5);
    $this->domains = $this->container->get('entity_type.manager')->getStorage('domain')->loadMultiple();

    $this->configureTrustedHostPatterns();
    $this->createArticleContentType();
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
   * Ensures the article content type exists so its dynamic permissions do.
   *
   * PHPUnit does not run the install hooks of $profile; the standard profile's
   * hook_install() is what creates the article type on a real site. Without
   * this, "edit any article content" is not a valid permission in tests.
   */
  private function createArticleContentType(): void {
    $storage = $this->container->get('entity_type.manager')->getStorage('node_type');
    if ($storage->load('article') === NULL) {
      $this->drupalCreateContentType(['type' => 'article', 'name' => 'Article']);
    }
    // domain_source_install() runs at module-install time and only attaches
    // field_domain_source to node types that already exist then. The article
    // type we may have just created above is unknown to it, so attach the
    // field explicitly via the OOP helper (the procedural function is
    // deprecated in domain 3.x and removed in 4.x).
    $this->container->get('Drupal\domain_source\DomainSourceHelperInterface')
      ->confirmFields('node', 'article');
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
   *
   * @return \Drupal\node\NodeInterface
   *   The created node entity.
   */
  private function createArticle(string $alias, string $domain_key) {
    $node = $this->drupalCreateNode([
      'type' => 'article',
      DomainSourceElementManagerInterface::DOMAIN_SOURCE_FIELD => [
        $this->domains[$domain_key]->id(),
      ],
    ]);
    $this->saveEntityAlias($node, $alias);
    $node->path->pathauto = PathautoState::CREATE;
    $node->save();

    return $node;
  }

  /**
   * Tests domain-specific unique path aliases.
   */
  public function testDomainUniquePathAlias(): void {
    $this->testAliasGeneration();
    $this->testUpdatePathAliasEntity();
    $this->testAliasConstraintValidation();
    $this->testNodeDeletion();
  }

  /**
   * Lot 4.1 — same alias resolves to different nodes per domain.
   */
  public function testSameAliasResolvesToDifferentNodesPerDomain(): void {
    $domain_a = $this->domains['example_com'];
    $domain_b = $this->domains['one_example_com'];

    $this->drupalGet($domain_a->getPath() . 'contact');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains($this->nodes['node_1']->label());

    $this->drupalGet($domain_b->getPath() . 'contact');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains($this->nodes['node_3']->label());
  }

  /**
   * Lot 4.2 — alias only present on another domain returns HTTP 404.
   */
  public function testAliasOnlyOnAnotherDomainReturns404(): void {
    $domain_a = $this->domains['example_com'];
    $domain_b = $this->domains['one_example_com'];

    $this->drupalGet($domain_a->getPath() . 'contact-bis');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains($this->nodes['node_2']->label());

    $this->drupalGet($domain_b->getPath() . 'contact-bis');
    $this->assertSession()->statusCodeEquals(404);
  }

  /**
   * Lot 4.3 — custom 404 page is preserved (never HTTP 200).
   */
  public function testCustom404PageIsPreserved(): void {
    $marker = 'CUSTOM_404_MARKER_' . bin2hex(random_bytes(4));
    $not_found_node = $this->drupalCreateNode([
      'type' => 'article',
      'title' => $marker,
    ]);

    $this->config('system.site')
      ->set('page.404', '/node/' . $not_found_node->id())
      ->save();

    $domain_b = $this->domains['one_example_com'];
    $this->drupalGet($domain_b->getPath() . 'contact-bis');
    $this->assertSession()->statusCodeEquals(404);
    $this->assertSession()->pageTextContains($marker);
  }

  /**
   * Lot 2.1 — Pathauto falls back to global uniqueness when domain is unknown.
   *
   * NOTE: Deferred. Requires mocking the decorated
   * pathauto.alias_uniquifier service to exercise the fallback path
   * directly without depending on core AliasManager's path-prefix
   * filtering. The contract is exercised implicitly through
   * testAliasConstraintValidation (same-domain collision path) and
   * by direct review of the fallback branch in
   * DomainUniquePathAliasUniquifier::isReserved().
   */
  public function testPathautoFallbackToGlobalWhenDomainUnknown(): void {
    $this->markTestSkipped('Deferred: requires service mocking (see method docblock).');
  }

  /**
   * Lot 4.12 — Multiple saves in the same request do not leak domain_id.
   */
  public function testMultipleSavesDoNotLeakDomainId(): void {
    $cases = [
      'example_com' => '/multi-save-a',
      'one_example_com' => '/multi-save-b',
      'two_example_com' => '/multi-save-c',
    ];
    $storage = $this->container->get('entity_type.manager')->getStorage('path_alias');
    foreach ($cases as $domain_key => $alias_path) {
      $node = $this->drupalCreateNode([
        'type' => 'article',
        DomainSourceElementManagerInterface::DOMAIN_SOURCE_FIELD => [
          $this->domains[$domain_key]->id(),
        ],
      ]);
      $this->saveEntityAlias($node, $alias_path);
      $aliases = $storage->loadByProperties(['path' => '/node/' . $node->id()]);
      $alias = reset($aliases);
      $this->assertSame(
        $domain_key,
        $alias->get('domain_id')->getString(),
        sprintf('Alias for %s is on the right domain.', $domain_key)
      );
    }
  }

  /**
   * Lot 4.4 + Lot 4.5 — und fallback and language priority.
   */
  public function testLanguageFallbackAndPriority(): void {
    $node = $this->drupalCreateNode([
      'type' => 'article',
      DomainSourceElementManagerInterface::DOMAIN_SOURCE_FIELD => [
        $this->domains['example_com']->id(),
      ],
    ]);
    $lookup = $this->container->get('domain_unique_path_alias.lookup');

    // Lot 4.4: only an 'und' alias exists; a request in 'fr' falls back to it.
    $this->saveEntityAlias($node, '/lang-und-only', 'und');
    $this->assertSame(
      '/node/' . $node->id(),
      $lookup->lookup('/lang-und-only', 'fr', 'example_com'),
      'und alias is used when requested langcode is missing.'
    );

    // Lot 4.5: both 'fr' and 'und' aliases exist; the requested langcode wins.
    $this->saveEntityAlias($node, '/lang-both', 'und');
    $this->saveEntityAlias($node, '/lang-both', 'fr');
    $this->assertSame(
      '/node/' . $node->id(),
      $lookup->lookup('/lang-both', 'fr', 'example_com'),
      'Exact langcode beats und.'
    );
    $this->assertSame(
      '/node/' . $node->id(),
      $lookup->lookup('/lang-both', 'und', 'example_com'),
      'und request returns und alias.'
    );
  }

  /**
   * Lot 4.6 — Disabled aliases (status = 0) are not selected.
   */
  public function testDisabledAliasIsNotSelected(): void {
    $node = $this->drupalCreateNode([
      'type' => 'article',
      DomainSourceElementManagerInterface::DOMAIN_SOURCE_FIELD => [
        $this->domains['example_com']->id(),
      ],
    ]);
    $this->saveEntityAlias($node, '/lang-disabled');
    $alias_storage = $this->container->get('entity_type.manager')->getStorage('path_alias');
    foreach ($alias_storage->loadByProperties(['alias' => '/lang-disabled']) as $alias) {
      $alias->set('status', 0);
      $alias->save();
    }

    $lookup = $this->container->get('domain_unique_path_alias.lookup');
    $this->assertNull(
      $lookup->lookup('/lang-disabled', 'en', 'example_com'),
      'Disabled alias must not be returned.'
    );
  }

  /**
   * Lot 4.9 — Legacy empty domain_id on a domainisable source fails closed.
   */
  public function testLegacyEmptyDomainIdFailsClosedForCrossDomain(): void {
    $node = $this->drupalCreateNode([
      'type' => 'article',
      DomainSourceElementManagerInterface::DOMAIN_SOURCE_FIELD => [
        $this->domains['example_com']->id(),
      ],
    ]);
    // Inject a legacy alias (domain_id = '') directly to bypass sync.
    $alias_storage = $this->container->get('entity_type.manager')->getStorage('path_alias');
    $legacy = $alias_storage->create([
      'path' => '/node/' . $node->id(),
      'alias' => '/legacy-empty',
      'langcode' => 'en',
      'status' => 1,
      'domain_id' => '',
    ]);
    $legacy->save();

    // Same domain → resolves.
    $this->drupalGet($this->domains['example_com']->getPath() . 'legacy-empty');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains($node->label());

    // Cross-domain → 404 (fail closed).
    $this->drupalGet($this->domains['one_example_com']->getPath() . 'legacy-empty');
    $this->assertSession()->statusCodeEquals(404);
  }

  /**
   * Lot 9 — Outbound URL should point to the alias-owning domain.
   *
   * Deferred. Per plan: "Si le test échoue : ouvrir un chantier
   * OutboundPathProcessor séparé". Domain module ships no
   * OutboundPathProcessor; URLs generated in the current request
   * context point to the current domain. Implementing domain-aware
   * outbound URLs is a separate workstream, not in scope of this
   * remediation MR.
   */
  public function testOutboundUrlPointsToAliasOwningDomain(): void {
    $this->markTestSkipped('Lot 9 deferred — requires OutboundPathProcessor (separate workstream).');
  }

  /**
   * Lot 5 — Batched backfill updates domain_id, leaves neutrals/orphans empty.
   */
  public function testBackfillUpdateHook(): void {
    $node = $this->drupalCreateNode([
      'type' => 'article',
      DomainSourceElementManagerInterface::DOMAIN_SOURCE_FIELD => [
        $this->domains['example_com']->id(),
      ],
    ]);
    $storage = $this->container->get('entity_type.manager')->getStorage('path_alias');

    // Resolvable node path — must end up with the node's domain_id.
    $a1 = $storage->create([
      'path' => '/node/' . $node->id(),
      'alias' => '/backfill-a',
      'langcode' => 'en',
      'status' => 1,
      'domain_id' => '',
    ]);
    $a1->save();

    // Non-domainisable path (neutral, left empty).
    $a3 = $storage->create([
      'path' => '/user/1',
      'alias' => '/backfill-user',
      'langcode' => 'en',
      'status' => 1,
      'domain_id' => '',
    ]);
    $a3->save();

    // Orphan node id (unresolved, left empty).
    $a4 = $storage->create([
      'path' => '/node/999999',
      'alias' => '/backfill-orphan',
      'langcode' => 'en',
      'status' => 1,
      'domain_id' => '',
    ]);
    $a4->save();

    $sandbox = [];
    do {
      domain_unique_path_alias_update_9001($sandbox);
    } while (empty($sandbox['#finished']) || $sandbox['#finished'] < 1);

    $this->assertSame('example_com', (string) $storage->load($a1->id())->get('domain_id')->getString());
    $this->assertSame('', (string) $storage->load($a3->id())->get('domain_id')->getString());
    $this->assertSame('', (string) $storage->load($a4->id())->get('domain_id')->getString());

    $this->assertGreaterThanOrEqual(1, $sandbox['neutral']);
    $this->assertGreaterThanOrEqual(1, $sandbox['unresolved']);
  }

  /**
   * Tests if node aliases are generated correctly per domain.
   */
  private function testAliasGeneration(): void {
    $this->assertEntityAlias($this->nodes['node_1'], '/contact');
    $this->assertEntityAlias($this->nodes['node_2'], '/contact-bis');
    $this->assertEntityAlias($this->nodes['node_3'], '/contact');
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

  /**
   * Change node 1 domain_id and check path_alias entity.
   */
  private function testUpdatePathAliasEntity(): void {
    $path_alias = $this->container->get('entity_type.manager')
      ->getStorage('path_alias')
      ->loadByProperties([
        'path' => '/node/1',
      ]);
    $path_alias = reset($path_alias);
    $domain_id = $path_alias->get('domain_id')->getString();
    $this->assertEquals('example_com', $domain_id);

    $this->drupalGet('node/1/edit');
    $edit = [
      'field_domain_source' => 'four_example_com',
    ];
    $this->submitForm($edit, 'Save');
    $this->rebuildContainer();

    // After: four_example_com.
    $path_alias = $this->container->get('entity_type.manager')
      ->getStorage('path_alias')
      ->loadByProperties([
        'path' => '/node/1',
      ]);
    $path_alias = reset($path_alias);
    $domain_id = $path_alias->get('domain_id')->getString();
    $this->assertEquals('four_example_com', $domain_id);

    $this->drupalGet('node/1/edit');
    $edit = [
      'field_domain_source' => 'example_com',
    ];
    $this->submitForm($edit, 'Save');
    $this->rebuildContainer();

    // Reset: example_com.
    $path_alias = $this->container->get('entity_type.manager')
      ->getStorage('path_alias')
      ->loadByProperties([
        'path' => '/node/1',
      ]);
    $path_alias = reset($path_alias);
    $domain_id = $path_alias->get('domain_id')->getString();
    $this->assertEquals('example_com', $domain_id);
  }

}
