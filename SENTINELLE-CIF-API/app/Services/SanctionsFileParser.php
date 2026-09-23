<?php

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMXPath;
use InvalidArgumentException;
use Illuminate\Support\Str;

/** Parse uniquement le fichier fourni, sans accès réseau ni résolution d'entités XML. */
final class SanctionsFileParser
{
    private int $rawCount = 0;

    public function parse(string $content, string $source): array
    {
        $this->rawCount = 0;
        $content = ltrim($content, "\xEF\xBB\xBF \r\n\t");
        if ($content === '' || preg_match('/<!DOCTYPE|<!ENTITY|<html\b/i', $content)) {
            throw new InvalidArgumentException('Fichier vide ou format non autorisé. Importez le fichier de données officiel, pas une page HTML.');
        }
        $rows = str_starts_with($content, '<') ? $this->xml($content, $source)
            : ($source === 'OFAC' && ! preg_match('/^\d+\s*,/', $content) ? $this->ofacText($content) : $this->csv($content, $source));
        if ($rows === []) throw new InvalidArgumentException('Aucune entrée reconnue. Vérifiez la liste choisie et le format XML, CSV ou TXT.');
        $unique = [];
        foreach ($rows as $row) {
            if ($row['name'] === '' || $row['reference'] === '') throw new InvalidArgumentException('Une entrée ne contient ni référence ni nom exploitable.');
            if (isset($unique[$row['reference']])) {
                $previous = $unique[$row['reference']];
                $previous['aliases'] = array_values(array_unique([...$previous['aliases'], $row['name'], ...$row['aliases']]));
                $unique[$row['reference']] = $previous;
            } else {
                $unique[$row['reference']] = $row;
            }
        }
        return ['raw_count' => max($this->rawCount, count($rows)), 'entries' => array_values($unique)];
    }

    private function entry(string $reference, string $name, string $type, array $aliases = [], string $program = ''): array
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        if (mb_strlen($name) > 255 || mb_strlen($reference) > 150) throw new InvalidArgumentException('Nom ou référence trop long pour le format de screening.');
        return [
            'reference' => trim($reference), 'name' => $name,
            'normalized' => mb_strtoupper(trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', Str::ascii($name)) ?? '')),
            'type' => in_array(strtoupper($type), ['PERSON', 'INDIVIDUAL', 'P'], true) ? 'INDIVIDUAL' : 'ENTITY',
            'aliases' => array_values(array_unique(array_filter(array_map('trim', $aliases)))),
            'program' => mb_substr($program, 0, 150),
        ];
    }

    private function xml(string $content, string $source): array
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument();
            if (! $document->loadXML($content, LIBXML_NONET | LIBXML_NOBLANKS)) throw new InvalidArgumentException('Le fichier XML est incomplet ou mal formé.');
            $xpath = new DOMXPath($document);
            $expression = match ($source) {
                'ONU' => '//*[local-name()="INDIVIDUAL" or local-name()="ENTITY"]',
                'EU' => '//*[local-name()="sanctionEntity"]',
                'OFAC' => '//*[local-name()="sdnEntry"]',
                default => throw new InvalidArgumentException('Source non prise en charge.'),
            };
            $rows = [];
            foreach ($xpath->query($expression) as $node) {
                $this->rawCount++;
                $values = static function (string $name) use ($xpath, $node): array {
                    return array_map(fn ($n) => trim($n->textContent), iterator_to_array($xpath->query('.//*[local-name()="'.$name.'"]', $node)));
                };
                if ($source === 'ONU') {
                    $name = implode(' ', array_filter([...$values('FIRST_NAME'), ...$values('SECOND_NAME'), ...$values('THIRD_NAME'), ...$values('FOURTH_NAME')]));
                    $rows[] = $this->entry($values('DATAID')[0] ?? '', $name, $node->localName === 'INDIVIDUAL' ? 'INDIVIDUAL' : 'ENTITY', $values('ALIAS_NAME'), $values('UN_LIST_TYPE')[0] ?? '');
                } elseif ($source === 'EU') {
                    $names = [];
                    foreach ($xpath->query('.//*[local-name()="nameAlias"]', $node) as $alias) {
                        $names[] = $alias->getAttribute('wholeName') ?: trim($alias->getAttribute('firstName').' '.$alias->getAttribute('middleName').' '.$alias->getAttribute('lastName'));
                    }
                    $subject = $xpath->query('.//*[local-name()="subjectType"]', $node)->item(0);
                    $type = $subject instanceof DOMElement ? $subject->getAttribute('code') : 'ENTITY';
                    $rows[] = $this->entry($node->getAttribute('euReferenceNumber') ?: $node->getAttribute('logicalId'), $names[0] ?? '', $type, array_slice($names, 1), $values('programme')[0] ?? '');
                } else {
                    $direct = static fn (string $name): string => trim((string) $xpath->evaluate('string(./*[local-name()="'.$name.'"])', $node));
                    $name = trim($direct('firstName').' '.$direct('lastName'));
                    $aliases = [];
                    foreach ($xpath->query('.//*[local-name()="aka"]', $node) as $alias) {
                        $aliases[] = trim($xpath->evaluate('string(./*[local-name()="firstName"])', $alias).' '.$xpath->evaluate('string(./*[local-name()="lastName"])', $alias));
                    }
                    $rows[] = $this->entry($direct('uid'), $name, $direct('sdnType'), $aliases, implode(', ', $values('program')));
                }
            }
            return $rows;
        } finally {
            libxml_clear_errors(); libxml_use_internal_errors($previous);
        }
    }

    private function csv(string $content, string $source): array
    {
        $stream = fopen('php://temp', 'r+'); fwrite($stream, $content); rewind($stream);
        $rows = [];
        try {
            if ($source === 'OFAC') {
                while (($fields = fgetcsv($stream, 0, ',', '"', '')) !== false) {
                    // Certains exports OFAC hérités se terminent par le marqueur DOS EOF (0x1A).
                    if (count($fields) === 1 && in_array(trim($fields[0] ?? ''), ['', "\x1A"], true)) continue;
                    $this->rawCount++;
                    if (count($fields) < 4 || ! ctype_digit(trim($fields[0]))) throw new InvalidArgumentException('CSV OFAC invalide : identifiant, nom, type et programme attendus.');
                    $rows[] = $this->entry(trim($fields[0]), $fields[1], trim($fields[2]), [], trim($fields[3]));
                }
            } elseif ($source === 'EU') {
                $headers = fgetcsv($stream, 0, ';', '"', '');
                if (! is_array($headers) || ! in_array('NameAlias_WholeName', $headers, true)) throw new InvalidArgumentException('Export CSV UE non reconnu.');
                while (($fields = fgetcsv($stream, 0, ';', '"', '')) !== false) {
                    if (count($fields) === 1 && trim($fields[0] ?? '') === '') continue;
                    $this->rawCount++;
                    if (count($fields) !== count($headers)) throw new InvalidArgumentException('Ligne CSV UE incomplète.');
                    $r = array_combine($headers, $fields);
                    $name = $r['NameAlias_WholeName'] ?: trim(($r['NameAlias_FirstName'] ?? '').' '.($r['NameAlias_LastName'] ?? ''));
                    // Les lignes UE peuvent décrire une adresse/date sans alias nominal.
                    if (trim($name) === '') continue;
                    $rows[] = $this->entry($r['Entity_EU_ReferenceNumber'] ?: ($r['Entity_LogicalId'] ?? ''), $name, $r['Entity_SubjectType'] ?? '', [], $r['Entity_Regulation_Programme'] ?? '');
                }
            } else {
                throw new InvalidArgumentException('Pour la liste ONU, importez le fichier XML officiel.');
            }
        } finally { fclose($stream); }
        return $rows;
    }

    private function ofacText(string $content): array
    {
        // La version texte lisible OFAC sépare les fiches par une ligne vide.
        $rows = [];
        foreach (preg_split('/\r?\n\s*\r?\n/', $content) as $paragraph) {
            $paragraph = trim(preg_replace('/\s+/', ' ', $paragraph) ?? '');
            if (! preg_match('/\[([A-Z][A-Z0-9_ -]+)\]/', $paragraph, $program)) continue;
            $this->rawCount++;
            $name = preg_split('/\s*\((?:a\.k\.a\.|f\.k\.a\.|c\/o)|;|\s+\[|,\s+(?:\d|P\.O\.)/i', $paragraph, 2)[0];
            $name = trim($name);
            if ($name === '' || mb_strlen($name) > 255) throw new InvalidArgumentException('Fiche OFAC TXT ambiguë : utilisez de préférence l’export XML ou CSV officiel.');
            preg_match_all('/(?:a\.k\.a\.|f\.k\.a\.)\s*"([^"]+)"/i', $paragraph, $aliases);
            $rows[] = $this->entry('TXT-'.substr(hash('sha256', $name), 0, 32), $name, str_contains($paragraph, 'DOB ') ? 'INDIVIDUAL' : 'ENTITY', $aliases[1], $program[1]);
        }
        return $rows;
    }
}
