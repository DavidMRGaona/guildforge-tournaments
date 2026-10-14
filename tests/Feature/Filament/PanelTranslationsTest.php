<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Feature\Filament;

use Illuminate\Support\Facades\Lang;
use Tests\Support\Modules\ModuleTestCase;

/**
 * Panel texts go through the module's translations, and every key the panel uses exists.
 */
final class PanelTranslationsTest extends ModuleTestCase
{
    protected ?string $moduleName = 'tournaments';

    protected bool $autoEnableModule = true;

    public function test_every_translation_key_used_by_the_panel_resolves(): void
    {
        $missing = [];
        foreach ($this->panelFiles() as $file) {
            preg_match_all("/__\\('(tournaments::messages\\.[a-z0-9_.]+)'/", (string) file_get_contents($file), $matches);
            foreach ($matches[1] as $key) {
                if (! Lang::has($key, 'es')) {
                    $missing[] = basename($file).': '.$key;
                }
            }
        }

        $this->assertSame([], $missing);
    }

    public function test_the_relation_managers_have_no_hardcoded_spanish(): void
    {
        $found = [];
        foreach ($this->panelFiles() as $file) {
            if (! str_contains($file, 'RelationManager')) {
                continue;
            }

            foreach (['Jugador', 'Desconocido', 'Estadísticas', "'Bye'", "'Victoria'", "'Empate'", "'Derrota'"] as $text) {
                if (str_contains((string) file_get_contents($file), $text)) {
                    $found[] = basename($file).': '.$text;
                }
            }
        }

        $this->assertSame([], $found);
    }

    /**
     * @return list<string>
     */
    private function panelFiles(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 3).'/src/Filament'));
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
