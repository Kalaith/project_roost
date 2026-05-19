<?php

declare(strict_types=1);

namespace Tests;

use App\Services\SummaryImportService;
use App\Services\ProjectDiscoveryService;
use PHPUnit\Framework\TestCase;

final class SmokeTest extends TestCase
{
    public function testTemplateBackendLoads(): void
    {
        self::assertTrue(true);
    }

    public function testRustGamesImportParsesInventoryPayload(): void
    {
        $payload = json_encode([
            'projects' => [[
                'slug' => 'sample_rust_game',
                'path' => 'H:\\WebHatchery\\RustGames\\sample_rust_game',
                'package_name' => 'sample_rust_game',
                'version' => '0.1.0',
                'description' => 'A sample Rust web game.',
                'has_index' => true,
                'has_publish_script' => true,
                'has_assets' => true,
                'has_readme' => true,
                'has_server_component' => false,
                'uses_macroquad' => true,
                'readme_summary' => '',
                'source_files' => 8,
            ]],
        ]);

        self::assertIsString($payload);

        $batch = (new SummaryImportService())->parse('rust-games', $payload);

        self::assertSame('rust-games-summary', $batch['source']);
        self::assertCount(1, $batch['records']);
        self::assertSame('rust_sample_rust_game', $batch['records'][0]['profile']['slug']);
        self::assertSame('rust_sample_rust_game', $batch['records'][0]['project']['title']);
        self::assertSame('Sample Rust Game (Rust)', $batch['records'][0]['profile']['display_name']);
        self::assertSame('rust-game', $batch['records'][0]['profile']['category']);
        self::assertSame('rust_games', $batch['records'][0]['project']['group_name']);
        self::assertSame('MVP', $batch['records'][0]['project']['status']);
        self::assertGreaterThanOrEqual(8.0, $batch['records'][0]['review']['overall_score']);
    }

    public function testAppsImportSeparatesProjectNameAndDisplayName(): void
    {
        $html = <<<'HTML'
        <table>
          <tr data-backend="full" data-status="strong">
            <td class="app-name">adventcon<span class="subtle">Fantasy adventure planner and draft studio</span></td>
            <td><span class="score">8.5</span></td>
            <td><span class="score">8</span></td>
            <td><span class="score">7</span></td>
            <td><span class="score">8</span></td>
            <td class="notes">Modern app.</td>
            <td class="notes">Keep shared auth.</td>
          </tr>
        </table>
        HTML;

        $_ENV['APPS_SUMMARY_PATH'] = __FILE__;
        $batch = (new SummaryImportService())->parse('apps', $html);

        self::assertCount(1, $batch['records']);
        self::assertSame('adventcon', $batch['records'][0]['project']['title']);
        self::assertSame('adventcon', $batch['records'][0]['profile']['slug']);
        self::assertSame('Adventure Story Generator', $batch['records'][0]['profile']['display_name']);
    }

    public function testDiscoveryMapsRustGamesToGamesSubfolder(): void
    {
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'project_roost_discovery_' . uniqid();
        $appsRoot = $root . DIRECTORY_SEPARATOR . 'apps';
        $gamesRoot = $root . DIRECTORY_SEPARATOR . 'game_apps';
        $rustRoot = $root . DIRECTORY_SEPARATOR . 'RustGames';
        $sampleRoot = $rustRoot . DIRECTORY_SEPARATOR . 'sample_game';

        mkdir($appsRoot, 0777, true);
        mkdir($gamesRoot, 0777, true);
        mkdir($sampleRoot, 0777, true);
        file_put_contents($appsRoot . DIRECTORY_SEPARATOR . 'PROJECT_SUMMARY.html', '');
        file_put_contents($gamesRoot . DIRECTORY_SEPARATOR . 'PROJECT_SUMMARY.html', '');
        file_put_contents(
            $sampleRoot . DIRECTORY_SEPARATOR . 'Cargo.toml',
            "[package]\nname = \"sample_game\"\nversion = \"0.2.0\"\ndescription = \"A sample Rust game.\"\n"
        );
        file_put_contents($sampleRoot . DIRECTORY_SEPARATOR . 'index.html', '<!doctype html>');

        $_ENV['APPS_SUMMARY_PATH'] = $appsRoot . DIRECTORY_SEPARATOR . 'PROJECT_SUMMARY.html';
        $_ENV['GAME_APPS_SUMMARY_PATH'] = $gamesRoot . DIRECTORY_SEPARATOR . 'PROJECT_SUMMARY.html';
        $_ENV['RUST_GAMES_ROOT'] = $rustRoot;

        try {
            $candidates = (new ProjectDiscoveryService())->discover([], ['source' => 'rust-games']);

            self::assertCount(1, $candidates);
            self::assertSame('rust_sample_game', $candidates[0]['name']);
            self::assertSame('rust_sample_game', $candidates[0]['slug']);
            self::assertSame('Sample Game (Rust)', $candidates[0]['display_name']);
            self::assertSame('rust-game', $candidates[0]['category']);
            self::assertSame('https://webhatchery.au/games/sample_game', $candidates[0]['production_url']);
            self::assertSame('http://127.0.0.1/sample_game', $candidates[0]['preview_url']);
        } finally {
            $this->removeDirectory($root);
        }
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $entry) {
            if (!$entry instanceof \SplFileInfo) {
                continue;
            }

            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }

        rmdir($path);
    }
}
