<?php

namespace AKlump\Base;

use AKlump\LoftLib\Testing\PhpUnitTestCase;

/**
 * @coversDefaultClass AKlump\Base/Base
 * @group ${test_group}
 *
 * @SuppressWarnings(PHPMD.StaticAccess)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class BaseTest extends PhpUnitTestCase {

  public function setUp() {
    $this->dependencies = [];
    $this->createObj();
  }

  protected function createObj() {
    $this->obj = new Base();
  }

  public function testExample() {
    $this->assertTrue(FALSE);
  }
}
