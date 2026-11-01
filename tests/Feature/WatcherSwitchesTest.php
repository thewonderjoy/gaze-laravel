<?php

declare(strict_types=1);

namespace TheWonderJoy\Gaze\Laravel\Tests\Feature;

use Illuminate\Database\Events\QueryExecuted;
use PHPUnit\Framework\Attributes\DataProvider;
use TheWonderJoy\Gaze\Laravel\GazeManager;
use TheWonderJoy\Gaze\Laravel\Tests\TestCase;

/**
 * Each watcher switch in config/gaze.php, at boot: on registers its watcher, off registers nothing
 * ("A watcher that is off registers nothing, so it costs nothing").
 */
final class WatcherSwitchesTest extends TestCase
{
    protected function setUp(): void
    {
        $data = $this->providedData();
        $on = $data[0] ?? null;
        $watchers = array_fill_keys(GazeManager::WATCHERS, false);
        if (is_string($on)) {
            $watchers[$on] = true;
        }
        $this->gazeConfig = ['watchers' => $watchers];
        parent::setUp();
    }

    /** @return array<string, class-string|null> watcher class => listening */
    private function listening(): array
    {
        $out = [];
        foreach (GazeManager::WATCHERS as $switch) {
            $class = (string) GazeManager::watcherClassFor($switch);
            $out[$class] = $this->manager()->listening($class);
        }

        return $out;
    }

    public function testWithEverySwitchOffNoWatcherIsRegistered(): void
    {
        $this->assertSame([], array_keys(array_filter($this->listening())));
        $this->assertArrayNotHasKey(QueryExecuted::class, $this->packageListeners());
    }

    /** @return iterable<string, array{string}> */
    public static function switches(): iterable
    {
        foreach (GazeManager::WATCHERS as $switch) {
            yield $switch => [$switch];
        }
    }

    #[DataProvider('switches')]
    public function testASwitchOnByItselfRegistersOnlyItsWatcher(string $switch): void
    {
        $class = (string) GazeManager::watcherClassFor($switch);
        $this->assertNotSame('', $class, "$switch has a watcher");
        $this->assertTrue($this->manager()->isOn($switch));
        $this->assertSame([$class], array_keys(array_filter($this->listening())), "only $switch's watcher listens");
    }
}
