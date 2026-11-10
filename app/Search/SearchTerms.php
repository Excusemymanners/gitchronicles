<?php

namespace App\Search;

use Illuminate\Support\Str;

/**
 * Turns a question (in Romanian or English) into the words the code search looks for.
 *
 * 1. words(): the identifiers are split ("LoginController" → "login controller"), the diacritics
 *    are removed („autentificării” → "autentificarii") and everything becomes lowercase words;
 * 2. the stop words („unde”, „se”, „face”, "the", "is", ...) are dropped: they are in every text;
 * 3. stem(): each word is shortened to a prefix, searched as "prefix*", so the other forms of
 *    the word match too: "modulul" → "modul*" (module, modules), "users" → "user*";
 * 4. GLOSSARY: the code is written in English and the questions in Romanian, so a Romanian
 *    word also brings the English words used in code ("autentificarea" → authenticat*, login*).
 *
 * The same splitting of identifiers is used when the code is indexed (splitIdentifiers()),
 * so a question and the code are cut into words the same way.
 */
final class SearchTerms
{
    /**
     * At most this many terms are searched: a long question would only make the search slower.
     */
    public const MAX_TERMS = 16;

    /**
     * Words that say nothing about the code, in Romanian (without diacritics) and in English.
     */
    private const STOP_WORDS = [
        // Romanian
        'unde', 'se', 'face', 'fac', 'facut', 'facuta', 'ce', 'cum', 'care', 'cine', 'cand', 'cat', 'cata', 'cati', 'cate',
        'este', 'sunt', 'era', 'fi', 'fie', 'fost', 'de', 'la', 'in', 'din', 'pe', 'cu', 'si', 'sau', 'un', 'una', 'unei',
        'unui', 'al', 'ai', 'ale', 'lui', 'ei', 'lor', 'mai', 'pentru', 'ca', 'sa', 'nu', 'da', 'acest', 'aceasta',
        'acesta', 'aceste', 'acestea', 'acestui', 'acestei', 'asta', 'aici', 'acolo', 'toate', 'tot', 'totul', 'toti',
        'fiecare', 'prin', 'dupa', 'despre', 'intre', 'spre', 'catre', 'daca', 'iar', 'dar', 'ori', 'poate', 'pot',
        'poti', 'vreau', 'am', 'ar', 'ati', 'avem', 'are', 'au', 'imi', 'mi', 'ne', 'va', 'le', 'il', 'isi', 'eu', 'tu',
        'el', 'ea', 'noi', 'voi', 'ele', 'explica', 'explicati', 'spune', 'spuneti', 'arata', 'aratati', 'gasesc',
        'gaseste', 'exista', 'folosit', 'folosita', 'foloseste', 'folosesc', 'functioneaza', 'lucreaza', 'cod', 'codul',
        'codului', 'partea', 'parte', 'exact', 'anume', 'adica',
        // „modulul X”, „fișierul X”: the word only points to the folder or file the question names
        'modulul', 'modulului', 'fisierul', 'fisierului', 'folderul', 'folderului', 'directorul', 'directorului',
        // „unde sunt definite / e implementată X”: the verb describes the question, not the code
        'definit', 'definita', 'definite', 'definiti', 'implementat', 'implementata', 'implementate', 'implementati',
        // English
        'the', 'an', 'is', 'are', 'was', 'were', 'be', 'been', 'where', 'what', 'how', 'why', 'when', 'who', 'which',
        'does', 'do', 'did', 'of', 'on', 'at', 'for', 'to', 'from', 'by', 'with', 'and', 'or', 'not', 'this', 'that',
        'these', 'those', 'it', 'its', 'there', 'here', 'can', 'could', 'should', 'would', 'will', 'you', 'we', 'they',
        'me', 'my', 'our', 'your', 'about', 'into', 'done', 'used', 'use', 'uses', 'explain', 'show', 'tell', 'find',
        'code', 'handled', 'happen', 'happens', 'defined', 'implemented', 'located',
    ];

    /**
     * Romanian words (the start of the word, without diacritics) and the English words code uses for them.
     */
    private const GLOSSARY = [
        'autentific' => ['authenticat', 'login'],
        'logar' => ['login'],
        'logat' => ['login'],
        'loghe' => ['login'],
        'conect' => ['login', 'connect'],
        'deconect' => ['logout'],
        'inregistr' => ['register'],
        'parol' => ['password'],
        'utilizator' => ['user'],
        'autoriz' => ['authoriz', 'policy', 'gate'],
        'permisiun' => ['permission', 'policy'],
        'drept' => ['policy', 'permission'],
        'baza' => ['database'],
        'baze' => ['database'],
        'tabel' => ['table', 'schema', 'migration'],
        'coloan' => ['column'],
        'rut' => ['route'],
        'coad' => ['queue', 'job'],
        'cozi' => ['queue', 'job'],
        'job' => ['job'],
        'lucrator' => ['worker'],
        'fisier' => ['file'],
        'dosar' => ['folder', 'directory'],
        'test' => ['test'],
        'clon' => ['clone'],
        'analiz' => ['analy'],
        'sesiun' => ['session'],
        'eroar' => ['error', 'exception'],
        'erori' => ['error', 'exception'],
        'configur' => ['config'],
        'setar' => ['config', 'setting'],
        'pagin' => ['page'],
        'caut' => ['search'],
        'sterg' => ['delete', 'destroy'],
        'stear' => ['delete', 'destroy'],
        'adaug' => ['add', 'store', 'create'],
        'actualiz' => ['update'],
        'modific' => ['update', 'change'],
        'trimit' => ['send', 'dispatch'],
        'limit' => ['limit', 'throttle'],
        'cript' => ['encrypt', 'hash'],
        'istoric' => ['history', 'commit'],
        'intrebar' => ['question'],
        'raspuns' => ['answer', 'response'],
        'cerer' => ['request'],
        'interfat' => ['component', 'page', 'layout'],
        'dependent' => ['depend', 'import'],
        'traduc' => ['translat', 'lang'],
        'limbaj' => ['language'],
        'rular' => ['run'],
        'ruleaz' => ['run'],
    ];

    /**
     * The terms to search for a question: stemmed words first, then the glossary's English words.
     *
     * @return list<string>
     */
    public static function fromQuestion(string $question): array
    {
        $terms = [];

        foreach (self::words($question) as $word) {
            if (strlen($word) < 2 || in_array($word, self::STOP_WORDS, true)) {
                continue;
            }

            $terms[] = self::stem($word);

            foreach (self::GLOSSARY as $start => $englishWords) {
                if (str_starts_with($word, $start)) {
                    array_push($terms, ...$englishWords);
                }
            }
        }

        return array_slice(array_values(array_unique($terms)), 0, self::MAX_TERMS);
    }

    /**
     * Put a space inside every compound identifier, so a full-text index sees its words:
     * "LoginController" → "Login Controller", "parseHTTPResponse" → "parse HTTP Response".
     * (snake_case and kebab-case are already split by the index: "_" and "-" are not letters.)
     */
    public static function splitIdentifiers(string $text): string
    {
        return preg_replace('/(?<=\p{Ll}|\d)(?=\p{Lu})|(?<=\p{Lu})(?=\p{Lu}\p{Ll})/u', ' ', $text) ?? $text;
    }

    /**
     * The lowercase words of a text, without diacritics; identifiers are split into their words.
     *
     * @return list<string>
     */
    private static function words(string $text): array
    {
        $plain = Str::lower(Str::ascii(self::splitIdentifiers($text)));

        return preg_split('/[^a-z0-9]+/', $plain, flags: PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /**
     * A shorter prefix of the word, so its other forms match too: a final "s" goes away
     * ("users" → "user"), and a word of 6 letters or more loses up to 3 letters of its ending,
     * keeping at least 5 ("autentificarea" → "autentifica", "fisierele" → "fisier", "modulul" → "modul").
     */
    private static function stem(string $word): string
    {
        if (strlen($word) > 3 && str_ends_with($word, 's')) {
            $word = substr($word, 0, -1);
        }

        if (strlen($word) >= 6) {
            $word = substr($word, 0, max(5, strlen($word) - 3));
        }

        return $word;
    }
}
