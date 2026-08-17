<?php

namespace Cresenity\PhpCf\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Menjalankan biner phpcf sungguhan dari berbagai direktori.
 *
 * Berupa test integrasi, bukan unit, dan itu disengaja: yang diuji di sini
 * perilaku sebuah skrip CLI - apa yang dicetaknya, ke aliran mana, dan dengan
 * kode keluar berapa. Ketiganya tidak dapat diperiksa dengan memanggil fungsi
 * di dalamnya, sebab yang dahulu rusak justru urutan penjaganya dan bukan
 * perhitungannya.
 *
 * Docroot tiruan dibangun di direktori sementara, bukan di dalam repo, supaya
 * tidak ada berkas penanda `cf` yang tertinggal dan membuat phpcf menganggap
 * repo ini sendiri sebuah docroot.
 */
class CliTest extends TestCase {
    /**
     * @var string
     */
    protected $root;

    /**
     * @var string
     */
    protected $docroot;

    /**
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();

        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phpcf-test-' . getmypid() . '-' . uniqid();
        $this->docroot = $this->root . DIRECTORY_SEPARATOR . 'docroot';

        $appPublic = $this->docroot . '/application/demo/default/public';
        foreach ([$this->docroot . '/system/core', $appPublic, $this->docroot . '/application/tanpa-public', $this->root . '/luar/a/b/c'] as $dir) {
            mkdir($dir, 0777, true);
        }

        // penanda docroot yang dicari phpcf
        touch($this->docroot . '/cf');
        file_put_contents($appPublic . '/index.php', '<?php');

        // Bootstrap tiruan: mencetak apa yang berhasil ditetapkan skripnya,
        // sehingga test dapat memastikan bukan hanya "sampai" tetapi juga
        // "sampai dengan aplikasi yang benar".
        file_put_contents(
            $this->docroot . '/system/core/Bootstrap.php',
            '<?php' . PHP_EOL
                . 'echo "BOOTSTRAP", PHP_EOL;' . PHP_EOL
                . 'echo "APPCODE=", defined("CFCLI_APPCODE") ? CFCLI_APPCODE : "-", PHP_EOL;' . PHP_EOL
                . 'echo "CFINDEX=", defined("CFINDEX") ? "yes" : "no", PHP_EOL;' . PHP_EOL
        );
    }

    /**
     * @return void
     */
    protected function tearDown(): void {
        $this->removeDirectory($this->root);
        parent::tearDown();
    }

    /**
     * @param string $path
     *
     * @return void
     */
    protected function removeDirectory($path) {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $path . DIRECTORY_SEPARATOR . $entry;
            is_dir($full) ? $this->removeDirectory($full) : unlink($full);
        }
        rmdir($path);
    }

    /**
     * @param string $cwd
     * @param array  $arguments
     *
     * @return array ['stdout' => string, 'stderr' => string, 'exit' => int, 'seconds' => float]
     */
    protected function runCli($cwd, array $arguments = []) {
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/bin/phpcf');
        foreach ($arguments as $argument) {
            $command .= ' ' . escapeshellarg($argument);
        }

        $descriptor = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $started = microtime(true);
        $process = proc_open($command, $descriptor, $pipes, $cwd);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [
            'stdout' => $stdout,
            'stderr' => $stderr,
            'exit' => proc_close($process),
            'seconds' => microtime(true) - $started,
        ];
    }

    /**
     * Jalur normal, dan satu-satunya yang selama ini benar-benar dipakai orang.
     *
     * @return void
     */
    public function testItBootstrapsFromAnApplicationDirectory() {
        $result = $this->runCli($this->docroot . '/application/demo', ['list']);

        $this->assertSame(0, $result['exit']);
        $this->assertStringContainsString('BOOTSTRAP', $result['stdout']);
        $this->assertStringContainsString('APPCODE=demo', $result['stdout']);
        $this->assertStringContainsString('CFINDEX=yes', $result['stdout']);
    }

    /**
     * Aplikasi tanpa default/public/index.php tetap boleh - hanya CFINDEX-nya
     * yang tidak ditetapkan.
     *
     * @return void
     */
    public function testAnApplicationWithoutPublicIndexStillBootstraps() {
        $result = $this->runCli($this->docroot . '/application/tanpa-public', ['list']);

        $this->assertSame(0, $result['exit']);
        $this->assertStringContainsString('APPCODE=tanpa-public', $result['stdout']);
        $this->assertStringContainsString('CFINDEX=no', $result['stdout']);
    }

    /**
     * Dari docroot, perintah cf:* memang ditujukan untuk berjalan tanpa
     * aplikasi.
     *
     * @return void
     */
    public function testCfCommandsRunFromTheDocroot() {
        $result = $this->runCli($this->docroot, ['cf:test']);

        $this->assertSame(0, $result['exit']);
        $this->assertStringContainsString('BOOTSTRAP', $result['stdout']);
        $this->assertStringContainsString('APPCODE=-', $result['stdout']);
    }

    /**
     * Perintah bawaan console tidak menyentuh aplikasi mana pun, jadi tetap
     * boleh dari docroot.
     *
     * Sebelum perbaikan ini bekerja hanya karena penjaganya bocor - siapa pun
     * yang menambalnya tanpa menyadari itu akan menghilangkannya.
     *
     * @dataProvider globalCommandProvider
     *
     * @param string $argument
     *
     * @return void
     */
    public function testConsoleGlobalsRunFromTheDocroot($argument) {
        $result = $this->runCli($this->docroot, [$argument]);

        $this->assertSame(0, $result['exit'], $argument . ' ditolak dari docroot');
        $this->assertStringContainsString('BOOTSTRAP', $result['stdout']);
    }

    /**
     * @return array
     */
    public function globalCommandProvider() {
        return [
            'daftar perintah' => ['list'],
            'bantuan' => ['help'],
            'versi' => ['--version'],
            'versi pendek' => ['-V'],
        ];
    }

    /**
     * Perintah milik aplikasi ditolak lebih awal saat dijalankan dari docroot,
     * berikut penyebutan perintahnya - bukan diteruskan lalu gagal di dalam
     * kerangka kerja dengan pesan tentang namespace yang tidak dikenal.
     *
     * @return void
     */
    public function testAnApplicationCommandIsRefusedFromTheDocroot() {
        $result = $this->runCli($this->docroot, ['demo:seed']);

        $this->assertSame(1, $result['exit']);
        $this->assertStringNotContainsString('BOOTSTRAP', $result['stdout']);
        $this->assertStringContainsString('demo:seed', $result['stderr']);
    }

    /**
     * **Cacat utama yang diperbaiki.** Syarat penjaganya memakai DAN, sehingga
     * perintah yang membawa argumen membuat ruas pertama salah dan penjaganya
     * tidak pernah berbunyi - eksekusinya jatuh ke require dan fatal menyebut
     * '/system/core/Bootstrap.php', berkas yang memang tidak pernah ada di akar
     * sistem berkas.
     *
     * @return void
     */
    public function testItFailsCleanlyOutsideAnyDocroot() {
        $result = $this->runCli($this->root . '/luar', ['--version']);

        $this->assertSame(1, $result['exit']);
        $this->assertStringNotContainsString('Fatal error', $result['stderr']);
        $this->assertStringNotContainsString('Bootstrap.php', $result['stderr']);
        $this->assertStringContainsString('docroot', $result['stderr']);
    }

    /**
     * Tanpa argumen sama sekali, ruas kedua penjaganya membaca $argv[1] yang
     * tidak ada - "Undefined array key 1" mendahului pesannya. Dan kegagalannya
     * dikembalikan sebagai 0, sehingga skrip pemanggil menyangka berhasil.
     *
     * @return void
     */
    public function testItFailsCleanlyWithNoArgumentsAtAll() {
        $result = $this->runCli($this->root . '/luar');

        $this->assertSame(1, $result['exit'], 'kegagalan dikembalikan sebagai berhasil');
        $this->assertStringNotContainsString('Undefined array key', $result['stderr']);
        $this->assertStringNotContainsString('Notice', $result['stderr']);
    }

    /**
     * Pesan kegagalan harus keluar lewat STDERR, bukan STDOUT - kalau tidak, ia
     * ikut tertangkap pemanggil yang menampung keluaran perintahnya.
     *
     * @return void
     */
    public function testFailuresAreWrittenToStandardError() {
        $result = $this->runCli($this->root . '/luar', ['--version']);

        $this->assertSame('', trim($result['stdout']));
        $this->assertNotSame('', trim($result['stderr']));
    }

    /**
     * Penelusuran ke atas berhenti di akar sistem berkas.
     *
     * Sebelumnya ia menambahkan '/..' ke akhir path tanpa menormalkannya,
     * sehingga pathnya terus memanjang dan berhentinya hanya terjadi ketika
     * panjangnya melewati batas sistem berkas - terukur 1.330 panggilan
     * rekursif untuk satu direktori biasa. Yang diperiksa di sini akibatnya
     * yang dapat diamati: dari direktori yang dalam sekalipun, ia selesai
     * seketika.
     *
     * @return void
     */
    public function testTheUpwardSearchStopsAtTheFilesystemRoot() {
        $result = $this->runCli($this->root . '/luar/a/b/c', ['--version']);

        $this->assertSame(1, $result['exit']);
        $this->assertLessThan(5.0, $result['seconds'], 'penelusuran ke atas terlalu lama - kemungkinan tidak berhenti di akar');
    }

    /**
     * Docroot ditemukan dari kedalaman berapa pun di bawahnya, bukan hanya dari
     * direktori aplikasi.
     *
     * @return void
     */
    public function testTheDocrootIsFoundFromAnyDepthBelowIt() {
        $deep = $this->docroot . '/application/demo/default/public';
        $result = $this->runCli($deep, ['list']);

        $this->assertSame(0, $result['exit']);
        $this->assertStringContainsString('APPCODE=demo', $result['stdout']);
    }
}
