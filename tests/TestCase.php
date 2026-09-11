<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

/**
 * This file didn't exist at all before — every test class in this repo
 * extends Tests\TestCase, so without it the whole suite fails to load
 * (see tests/RouteTest.php, which has been silently uncollected: it lives
 * outside phpunit.xml's <testsuites> directories AND would have failed on
 * this missing class if it had ever been picked up).
 */
abstract class TestCase extends BaseTestCase
{
    //
}
