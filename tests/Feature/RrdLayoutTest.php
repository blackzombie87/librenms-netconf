<?php

namespace SafferIt\LibrenmsNetconf\Tests\Feature;

use SafferIt\LibrenmsNetconf\Collect\RrdLayout;

require_once __DIR__ . '/LibrenmsTestCase.php';

/**
 * RrdLayout against a real rrdtool (plan G6): the data sources of an existing file decide
 * the write order, a field the file lacks is appended by "rrdtool tune", and a file that
 * cannot be read falls back to the last known order instead of scrambling the values.
 */
final class RrdLayoutTest extends LibrenmsTestCase
{
    private string $dir = '';

    private string $rrdtool = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/netconf-rrdlayout-' . uniqid();
        mkdir($this->dir, 0777, true);
        $this->rrdtool = $this->findRrdtool();
        if ($this->rrdtool === '') {
            $this->markTestSkipped('needs rrdtool on PATH or in the LibreNMS config');
        }
    }

    protected function tearDown(): void
    {
        if ($this->dir !== '' && is_dir($this->dir)) {
            foreach (glob($this->dir . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($this->dir);
        }
        parent::tearDown();
    }

    public function testTheFileOrderWinsAndMissingDataSourcesAreAdded(): void
    {
        $file = $this->dir . '/netconf-test.rrd';
        $this->rrd(['create', $file, '--step', '300', 'DS:received:GAUGE:600:U:U', 'DS:active:GAUGE:600:U:U', 'RRA:AVERAGE:0.5:1:12']);
        $layout = $this->layout();

        // YAML order differs from the file: the file wins, so values keep their slot
        $order = $layout->reconcile($file, ['active' => 'GAUGE', 'received' => 'GAUGE'], null);
        $this->assertSame(['received', 'active'], array_keys($order));

        // a new field in the definition is appended to the file and to the order
        $order = $layout->reconcile($file, ['active' => 'GAUGE', 'received' => 'GAUGE', 'accepted' => 'GAUGE'], null);
        $this->assertSame(['received', 'active', 'accepted'], array_keys($order));
        $this->assertStringContainsString('ds[accepted]', (string) $this->rrd(['info', $file]));
        $this->assertStringContainsString('2 file(s) verified, 1 data source(s) added, 0 rrdtool call(s) failed', (string) $layout->summary());

        // the stored order covers every wanted field: no rrdtool call at all
        $this->assertSame(['received' => 'GAUGE'], $layout->reconcile($file, ['received' => 'GAUGE'], ['received' => 'GAUGE']));
    }

    public function testAMissingFileKeepsTheDefinitionOrderAndAnUnreadableOneTheStoredOrder(): void
    {
        $layout = $this->layout();
        $desired = ['errors' => 'COUNTER', 'drops' => 'COUNTER'];

        // the datastore creates the file in the definition's order
        $this->assertSame($desired, $layout->reconcile($this->dir . '/gone.rrd', $desired, null));

        // a file rrdtool cannot read: the last known order is kept, nothing is invented
        $broken = $this->dir . '/broken.rrd';
        file_put_contents($broken, 'not an rrd');
        $stored = ['drops' => 'COUNTER', 'errors' => 'COUNTER'];
        $this->assertSame($stored, $layout->reconcile($broken, $desired + ['late' => 'GAUGE'], $stored));
        $this->assertStringContainsString('failed', (string) $layout->summary());
    }

    private function layout(): RrdLayout
    {
        return new RrdLayout(rrdtool: $this->rrdtool(), rrdDir: $this->dir);
    }

    private function rrdtool(): string
    {
        return $this->rrdtool;
    }

    /** The configured binary, or the first "rrdtool" on PATH; empty when there is none. */
    private function findRrdtool(): string
    {
        $configured = (string) \App\Facades\LibrenmsConfig::get('rrdtool', 'rrdtool');
        if (is_executable($configured)) {
            return $configured;
        }
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
            $candidate = rtrim($dir, '/') . '/' . basename($configured);
            if (is_executable($candidate)) {
                return $candidate;
            }
        }

        return '';
    }

    /**
     * @param  list<string>  $arguments
     */
    private function rrd(array $arguments): ?string
    {
        $process = new \Symfony\Component\Process\Process([$this->rrdtool(), ...$arguments]);
        $process->run();

        return $process->isSuccessful() ? $process->getOutput() : null;
    }
}
