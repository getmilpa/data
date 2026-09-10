<?php

/**
 * This file is part of milpa/data.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/data
 */

declare(strict_types=1);

namespace Milpa\Data\Tests;

use PHPUnit\Framework\TestCase;

/**
 * THE MANIFEST'S COPY IS READ BY A PERSON, SO IT IS ENGLISH.
 *
 * `extra.milpa.capability` is not metadata for a machine: `title`, `briefing` and every entry in
 * `unlocks` are shown to whoever runs `coa capabilities` or opens the panel's Plugins section, and the
 * operation's own schema documents `unlocks` as «what becomes possible once it is installed».
 *
 * This package shipped one `unlocks` entry in Spanish, next to a `title` and a `briefing` in English,
 * for as long as the manifest has existed (greenhouse decisions/0286). Nothing caught it: the house's
 * code-language ratchet reads `src` in the monorepo layout and never a manifest, so this string was
 * outside every instrument that looks at language — which is exactly why the check belongs HERE, in the
 * package that owns the file, rather than in a gate that does not scan it.
 *
 * The check is a word list, not a language model: it asks whether the copy contains Spanish function
 * words no English sentence has. That is enough to catch a sentence composed in the wrong language and
 * cheap enough to run on every commit, and it is deliberately blind to a single borrowed noun.
 */
final class TheManifestSpeaksTheLanguageItShipsInTest extends TestCase
{
    /**
     * Function words that carry no meaning on their own, so they only appear in Spanish PROSE.
     *
     * Deliberately short and deliberately unambiguous: `la`, `el` and `que` are the joints of a
     * Spanish sentence and appear in no English one. Words that exist in both — `no`, `son`, `da` —
     * are left out, because a check that fires on English copy gets disabled instead of obeyed.
     */
    private const array SPANISH_JOINTS = [
        'el', 'la', 'los', 'las', 'un', 'una', 'unos', 'unas',
        'que', 'por', 'para', 'con', 'sin', 'del', 'al', 'como',
        'este', 'esta', 'esto', 'ese', 'esa', 'eso', 'sus', 'lo',
        'pero', 'porque', 'cuando', 'donde', 'aqui', 'aquí', 'ya',
    ];

    /*
     * NO HAY PALABRAS DEL DEFECTO EN ESTA LISTA. La primera versión traía `elige`, `protegidas` y
     * `operaciones` — las tres del par de cadenas que se acababan de traducir. Una lista afinada al
     * defecto que ya conoces pasa su propio control positivo sin decir nada sobre el siguiente. Las
     * junturas de arriba cazan las dos cadenas embarcadas por sí solas (`por` en una, `el` y `que` en
     * la otra), que es la prueba de que la lista general basta.
     */

    /** Every string a person reads in this manifest, keyed by where it lives. */
    public static function copy(): \Generator
    {
        $manifest = json_decode((string) file_get_contents(\dirname(__DIR__) . '/composer.json'), true);
        if (!\is_array($manifest)) {
            throw new \RuntimeException('the manifest must parse before its copy can be read');
        }

        // An empty yield would make this file pass by finding nothing to check, which is the failure
        // mode of every source-scanning test in this family. A manifest with no capability is a
        // changed package, not a green one.
        $capability = $manifest['extra']['milpa']['capability'] ?? null;
        if (!\is_array($capability)) {
            throw new \RuntimeException('this package declares a capability, so its copy is on the hook');
        }

        foreach (['title', 'briefing'] as $field) {
            if (\is_string($capability[$field] ?? null)) {
                yield $field => [$field, $capability[$field]];
            }
        }
        foreach (\is_array($capability['unlocks'] ?? null) ? $capability['unlocks'] : [] as $i => $entry) {
            if (\is_string($entry)) {
                yield 'unlocks[' . $i . ']' => ['unlocks[' . $i . ']', $entry];
            }
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('copy')]
    public function testEveryStringAPersonReadsIsEnglish(string $where, string $copy): void
    {
        $words = preg_split('/[^\\p{L}]+/u', mb_strtolower($copy), -1, \PREG_SPLIT_NO_EMPTY) ?: [];
        $spanish = array_values(array_intersect($words, self::SPANISH_JOINTS));

        self::assertSame([], $spanish, $where . ' is copy a person reads, and it is composed in Spanish: ' . implode(', ', $spanish) . ' — «' . $copy . '»');
    }

    /** Positive control: the check FIRES on the string this package actually shipped. */
    public function testTheCheckCatchesTheStringThatShipped(): void
    {
        $shipped = 'el backend de tokens y entidades que elige config/app.php';
        $words = preg_split('/[^\\p{L}]+/u', mb_strtolower($shipped), -1, \PREG_SPLIT_NO_EMPTY) ?: [];

        self::assertNotSame([], array_intersect($words, self::SPANISH_JOINTS), 'a check that does not fire on the known defect proves nothing about the ones it passes');
    }
}
