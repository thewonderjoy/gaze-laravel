<?php

declare(strict_types=1);

namespace TheWonderJoy\Gaze\Laravel\Tests\Feature;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use TheWonderJoy\Gaze\Gaze;
use TheWonderJoy\Gaze\Laravel\Tests\TestCase;

/** define_ray_helper = true: ray() and rd() exist and return the Gaze fluent API. */
final class RayHelperTest extends TestCase
{
    protected array $gazeConfig = ['define_ray_helper' => true];

    #[RunInSeparateProcess]
    public function testTheOptInShimDefinesRay(): void
    {
        $this->assertTrue(function_exists('ray'));
        $this->assertTrue(function_exists('rd'));
        $this->assertInstanceOf(Gaze::class, ray());
        ray('via the shim')->blue();
        $log = $this->added('log')[0];
        $this->assertSame(['k' => 'str', 'v' => 'via the shim'], $log['body']['values'][0]);
        $this->assertSame('blue', $log['color']);
        $this->assertSame(__FILE__, $log['origin']['file']);
    }
}
